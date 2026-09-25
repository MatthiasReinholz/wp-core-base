<?php

declare(strict_types=1);

use WpOrgPluginUpdater\Config;
use WpOrgPluginUpdater\ConfigPathRules;
use WpOrgPluginUpdater\DependencyMetadataResolver;
use WpOrgPluginUpdater\ExtractedPayloadLocator;
use WpOrgPluginUpdater\RuntimeInspector;
use WpOrgPluginUpdater\RuntimeStager;
use WpOrgPluginUpdater\TempDirectoryJanitor;
use WpOrgPluginUpdater\TempWorkspace;

/** @param callable(bool,string):void $assert */
function run_filesystem_hardening_contract_tests(callable $assert): void
{
    $root = sys_get_temp_dir() . '/wp-core-base-filesystem-contracts-' . bin2hex(random_bytes(8));
    mkdir($root . '/repo/cms/plugins/local-plugin', 0700, true);
    mkdir($root . '/temp', 0700);
    mkdir($root . '/outside', 0700);
    file_put_contents($root . '/outside/KEEP', 'unrelated data');
    $repo = $root . '/repo';
    $manifest = [
        'profile' => 'content-only',
        'paths' => ['content_root' => 'cms', 'plugins_root' => 'cms/plugins', 'themes_root' => 'cms/themes', 'mu_plugins_root' => 'cms/mu-plugins'],
        'core' => ['mode' => 'external', 'enabled' => false],
        'dependencies' => [['slug' => 'local-plugin', 'kind' => 'plugin', 'management' => 'local', 'source' => 'local', 'path' => 'cms/plugins/local-plugin', 'main_file' => 'plugin.php']],
    ];
    $config = Config::fromArray($repo, $manifest);
    $inspector = new RuntimeInspector($config->runtime);
    $throws = static function (callable $callback): bool {
        try {
            $callback();
            return false;
        } catch (RuntimeException) {
            return true;
        }
    };
    try {
        $blocked = ['.', './', '.git', '.git/objects', '.github/workflows', '.gitlab', '.gitlab-ci.yml',
            '.gitea/workflows', '.forgejo/workflows', '.circleci', '.gitignore',
            '.wp-core-base', '.wp-core-base/framework.php', '.wp-core-base/build', '.wp-core-base/build/locks',
            '.wp-core-base/build/locks/runtime', 'cms', 'cms/plugins/local-plugin', 'wp-admin', 'wp-includes/js',
            'wp-config.php', 'wp-load.php/runtime', 'tools', 'bin/anything', 'vendor/wp-core-base',
            '/tmp/runtime', 'C:/runtime', 'C:runtime', '../runtime', 'build/..', 'build/../runtime', "build/\0runtime", "\0build/runtime", "build/runtime\0"];
        foreach ($blocked as $output) {
            $assert($throws(static fn () => $config->stageDir($output)), 'Stage output must reject protected or unsafe path ' . json_encode($output));
            $unsafeManifest = $manifest;
            $unsafeManifest['runtime']['stage_dir'] = $output;
            $assert($throws(static fn () => Config::fromArray($repo, $unsafeManifest)), 'Manifest stage directory must reject the same unsafe path ' . json_encode($output));
        }
        $assert($config->stageDir('./.wp-core-base//build/runtime/') === $repo . '/.wp-core-base/build/runtime', 'Stage paths should have one canonical spelling.');
        mkdir($repo . '/.wp-core-base', 0700);
        mkdir($repo . '/.git', 0700);
        file_put_contents($repo . '/.git/KEEP', 'repository metadata');
        mkdir($repo . '/.wp-core-base/build/locks', 0700, true);
        mkdir($repo . '/wp-admin', 0700);
        $caseInsensitive = is_dir($repo . '/.GIT');
        if ($caseInsensitive) {
            foreach (['.GIT', '.Git/objects', '.Wp-core-base', '.WP-CORE-BASE/build/LOCKS', 'CMS', 'cms/PLUGINS/local-plugin', 'WP-ADMIN'] as $output) {
                $assert($throws(static fn () => $config->stageDir($output)), 'Stage output must reject filesystem aliases of control or source paths: ' . $output);
            }
            $assert($throws(static fn () => (new RuntimeStager($config, $inspector))->stage('.GIT')), 'The destructive stage command must reject a Git directory alias.');
            $assert(file_get_contents($repo . '/.git/KEEP') === 'repository metadata', 'Alias rejection must preserve existing Git metadata.');
            foreach (['.GIT/config', '.Wp-core-base/manifest.php'] as $path) {
                $assert($throws(static fn () => ConfigPathRules::assertSafeDependencyPath($repo, $path)), 'Dependency ownership must reject a filesystem control alias: ' . $path);
            }
            $aliases = $manifest;
            $second = $aliases['dependencies'][0];
            $second['slug'] = 'same-payload-alias';
            $second['path'] = 'cms/plugins/LOCAL-PLUGIN';
            $aliases['dependencies'][] = $second;
            $assert($throws(static fn () => Config::fromArray($repo, $aliases)), 'Strict ownership must reject different spellings of the same dependency directory.');
            $aliasedManifest = $manifest;
            $aliasedManifest['dependencies'][0]['path'] = 'cms/plugins/LOCAL-PLUGIN';
            $aliasedConfig = Config::fromArray($repo, $aliasedManifest);
            $assert((new \WpOrgPluginUpdater\RuntimeOwnershipInspector($aliasedConfig))->undeclaredRuntimePaths() === [], 'Physical dependency aliases must not also be classified as undeclared runtime.');
            file_put_contents($repo . '/cms/plugins/index.php', 'project-owned index');
            $ownedIndex = $manifest;
            $ownedIndex['dependencies'][] = ['slug' => 'owned-index', 'kind' => 'runtime-file', 'management' => 'local', 'source' => 'local', 'path' => 'cms/plugins/INDEX.php'];
            $indexConfig = Config::fromArray($repo, $ownedIndex);
            $assert(! (new \WpOrgPluginUpdater\CoreContentOwnership($indexConfig))->mayReplace('cms/plugins/index.php', false), 'Core index stubs must preserve declared file ownership through filesystem aliases.');
            unlink($repo . '/cms/plugins/index.php');
        } else {
            $assert($config->stageDir('.GIT') === $repo . '/.GIT', 'A distinct path on a case-sensitive filesystem must retain its meaning.');
            ConfigPathRules::assertSafeDependencyPath($repo, '.GIT/config');
        }
        foreach (['.git', '.github', '.wp-core-base/build'] as $controlPath) {
            $unsafeSources = ['profile' => 'content-only', 'paths' => ['content_root' => '.', 'plugins_root' => $controlPath, 'themes_root' => 'themes', 'mu_plugins_root' => 'mu-plugins']];
            $assert($throws(static fn () => Config::fromArray($repo, $unsafeSources)), 'Runtime source roots must not stage control data: ' . $controlPath);
            $unsafeSources['paths']['plugins_root'] = 'plugins';
            $unsafeSources['runtime']['allow_runtime_paths'] = [$controlPath];
            $assert($throws(static fn () => Config::fromArray($repo, $unsafeSources)), 'Runtime allowlist paths must not stage control data: ' . $controlPath);
        }
        foreach (['wp-admin', 'wp-includes'] as $coreRoot) {
            $coreSources = ['profile' => 'full-core', 'paths' => ['content_root' => $coreRoot, 'plugins_root' => $coreRoot . '/plugins', 'themes_root' => $coreRoot . '/themes', 'mu_plugins_root' => $coreRoot . '/mu-plugins']];
            $assert($throws(static fn () => Config::fromArray($repo, $coreSources)), 'Enabled core updates must not overwrite separately configured runtime roots.');
            $coreSources['core'] = ['enabled' => false];
            $assert(Config::fromArray($repo, $coreSources)->paths['content_root'] === $coreRoot, 'Core-disabled projects retain authority over their custom runtime directory names.');
            $ownedCore = ['profile' => 'full-core', 'paths' => ['content_root' => '.', 'plugins_root' => 'plugins', 'themes_root' => 'themes', 'mu_plugins_root' => 'mu-plugins'],
                'dependencies' => [['slug' => 'local-core-child', 'kind' => 'runtime-directory', 'management' => 'local', 'source' => 'local', 'path' => $coreRoot . '/local-project']]];
            $assert($throws(static fn () => Config::fromArray($repo, $ownedCore)), 'Local dependencies cannot be placed inside directories replaced by enabled core updates.');
        }
        $frameworkMetadata = require dirname(__DIR__, 4) . '/.wp-core-base/framework.php';
        $frameworkMetadata['distribution']['path'] = 'lib/private-framework';
        file_put_contents($repo . '/.wp-core-base/framework.php', '<?php return ' . var_export($frameworkMetadata, true) . ';');
        foreach (['lib', 'lib/private-framework', 'lib/private-framework/nested'] as $output) {
            $assert($throws(static fn () => $config->stageDir($output)), 'Custom framework installation paths and ancestors must be protected.');
            $unsafeManifest = $manifest;
            $unsafeManifest['runtime']['stage_dir'] = $output;
            $assert($throws(static fn () => Config::fromArray($repo, $unsafeManifest)), 'Manifest stage directory must protect custom framework installations.');
        }
        file_put_contents($repo . '/README.md', 'source documentation');
        $assert($throws(static fn () => $config->stageDir('README.md')), 'Existing source files must never be replaced by a staged directory.');
        $fileManifest = $manifest;
        $fileManifest['runtime']['stage_dir'] = 'README.md';
        $assert($throws(static fn () => Config::fromArray($repo, $fileManifest)), 'Manifest stage paths must also reject existing source files.');
        $assert(ConfigPathRules::normalizedRelativePath('docs/./nested//item', 'test') === 'docs/nested/item', 'Relative paths normalize harmless dot and repeated separator segments.');
        foreach (['/cms', 'C:cms', "cms/\0name", 'cms/..'] as $path) {
            $invalid = $manifest;
            $invalid['paths']['content_root'] = $path;
            $assert($throws(static fn () => Config::fromArray($repo, $invalid)), 'All manifest path fields must share safe relative path rules.');
        }

        $extract = $root . '/extract';
        mkdir($extract . '/local-plugin', 0700, true);
        file_put_contents($extract . '/local-plugin/plugin.php', "<?php\n/* Plugin Name: Example */\n");
        $assert(ExtractedPayloadLocator::locateByExpectedEntry($extract, '', 'plugin.php', 'local-plugin', false) === $extract . '/local-plugin', 'Extracted payload lookup must retain normal wrapper-directory support.');
        $assert(ExtractedPayloadLocator::locateByExpectedEntry($extract, 'local-plugin', 'plugin.php', 'local-plugin', false) === $extract . '/local-plugin', 'A root archive subdirectory must remain valid when later wrapper candidates do not contain it.');
        $assert(ExtractedPayloadLocator::locateByExpectedEntry($extract, 'local-plugin', 'plugin.php', 'local-plugin', true) === $extract . '/local-plugin/plugin.php', 'Single-file updates must support a root archive subdirectory.');
        mkdir($extract . '/release/dist', 0700, true);
        file_put_contents($extract . '/release/dist/plugin.php', "<?php\n/* Plugin Name: Wrapped */\n");
        $assert(ExtractedPayloadLocator::locateByExpectedEntry($extract, 'dist', 'plugin.php', 'wrapped', false) === $extract . '/release/dist', 'Missing earlier archive subdirectories must not hide a valid payload within a release wrapper.');
        $assert(ExtractedPayloadLocator::locateByExpectedEntry($extract, 'dist', 'plugin.php', 'wrapped', true) === $extract . '/release/dist/plugin.php', 'Single-file updates must support a wrapped archive subdirectory.');
        file_put_contents($extract . '/dist', 'not a candidate directory');
        $assert(ExtractedPayloadLocator::locateByExpectedEntry($extract, 'dist', 'plugin.php', 'wrapped', false) === $extract . '/release/dist', 'A non-directory archive candidate must not hide a valid directory in a later wrapper.');
        unlink($extract . '/dist');
        $assert($throws(static fn () => ExtractedPayloadLocator::locateByExpectedEntry($extract, 'missing', 'plugin.php', 'local-plugin', false)), 'A completely missing archive subdirectory must fail payload lookup.');
        symlink($root . '/missing-target', $extract . '/dist');
        $assert($throws(static fn () => ExtractedPayloadLocator::locateByExpectedEntry($extract, 'dist', 'plugin.php', 'wrapped', false)), 'A dangling archive-subdirectory symlink must fail closed rather than being skipped for a later valid wrapper.');
        unlink($extract . '/dist');
        symlink($root . '/outside', $extract . '/dist');
        $assert($throws(static fn () => ExtractedPayloadLocator::locateByExpectedEntry($extract, 'dist', 'plugin.php', 'wrapped', false)), 'An existing archive-subdirectory symlink must fail closed rather than being skipped for a later valid wrapper.');
        $assert($throws(static fn () => ExtractedPayloadLocator::locateByExpectedEntry($extract, 'dist/missing', 'plugin.php', 'wrapped', false)), 'An archive subdirectory with a symlink ancestor must fail closed even when its leaf is absent.');
        unlink($extract . '/dist');
        foreach (['../outside', '/outside', 'C:outside', "nested/\0outside"] as $unsafe) {
            $assert($throws(static fn () => ExtractedPayloadLocator::locateByExpectedEntry($extract, $unsafe, 'plugin.php', 'local-plugin', false)), 'Source-provided archive subdirectories must reject unsafe paths.');
            $assert($throws(static fn () => ExtractedPayloadLocator::locateByExpectedEntry($extract, '', $unsafe, 'local-plugin', true)), 'Expected archive entry must reject unsafe paths.');
            $assert($throws(static fn () => ExtractedPayloadLocator::locateForAuthoring($extract, '', 'local-plugin', 'plugin', new DependencyMetadataResolver(), $unsafe)), 'Authoring main files must reject unsafe paths.');
        }
        symlink($root . '/outside', $extract . '/local-plugin/linked');
        $assert($throws(static fn () => ExtractedPayloadLocator::locateByExpectedEntry($extract, 'local-plugin/linked', 'KEEP', 'local-plugin', true)), 'Payload lookup must reject symlink archive subdirectories.');
        $assert($throws(static fn () => ExtractedPayloadLocator::locateByExpectedEntry($extract, 'local-plugin', 'linked/KEEP', 'local-plugin', true)), 'Payload lookup must reject symlink ancestors of an expected entry.');

        mkdir($repo . '/build', 0700);
        symlink($root . '/outside', $repo . '/build/link');
        $assert($throws(static fn () => (new RuntimeStager($config, $inspector))->stage('build/link/runtime')), 'Staging must reject an existing ancestor symlink.');
        $linkedManifest = $manifest;
        $linkedManifest['runtime']['stage_dir'] = 'build/link/runtime';
        $assert($throws(static fn () => Config::fromArray($repo, $linkedManifest)), 'Manifest paths must reject existing ancestor symlinks too.');
        symlink($root . '/outside', $repo . '/build/output-link');
        $assert($throws(static fn () => (new RuntimeStager($config, $inspector))->stage('build/output-link')), 'Staging must reject an output root symlink.');
        $assert(file_get_contents($root . '/outside/KEEP') === 'unrelated data', 'Rejected staging paths must preserve unrelated sentinel bytes.');

        file_put_contents($repo . '/cms/plugins/local-plugin/plugin.php', '<?php // local runtime');
        mkdir($repo . '/build/runtime', 0700);
        file_put_contents($repo . '/build/runtime/previous-output', 'last successful deployment');
        mkdir($repo . '/cms/plugins/local-plugin/node_modules', 0700);
        file_put_contents($repo . '/cms/plugins/local-plugin/node_modules/unsafe.js', 'development payload');
        $assert($throws(static fn () => (new RuntimeStager($config, $inspector))->stage('build/runtime')), 'Invalid runtime source must fail staging.');
        $assert(file_get_contents($repo . '/build/runtime/previous-output') === 'last successful deployment', 'A failed assembly must preserve the entire last successful stage.');
        $assert(glob($repo . '/build/.wp-core-base-stage-*') === [], 'A failed assembly must clean only its new private staging directory.');
        $inspector->clearPath($repo . '/cms/plugins/local-plugin/node_modules');
        $staged = (new RuntimeStager($config, $inspector))->stage('build/runtime');
        $assert(in_array('cms/plugins/local-plugin', $staged, true), 'Successful stage must report assembled dependencies.');
        $assert(is_file($repo . '/build/runtime/cms/plugins/local-plugin/plugin.php') && ! file_exists($repo . '/build/runtime/previous-output'), 'Successful stage must replace the old payload with verified output.');
        $assert(glob($repo . '/build/.wp-core-base-stage-*') === [], 'Successful publication must clean its backup.');
        $inspector->copyPath($repo . '/cms/plugins/local-plugin/plugin.php', $repo . '/build/ignored.php', ['plugin.php']);
        $assert(! file_exists($repo . '/build/ignored.php'), 'Single-file copy must obey basename exclusions.');

        $workspace = TempWorkspace::create($repo, 'contract', $root . '/temp');
        $namespace = TempWorkspace::namespacePath($repo, $root . '/temp');
        $workspaceRoot = dirname($workspace->path());
        $assert((fileperms($namespace) & 0777) === 0700 && (fileperms($workspaceRoot) & 0777) === 0700, 'Workspace namespaces and operation roots must be private.');
        $assert((fileperms($workspaceRoot . '/' . TempWorkspace::MARKER) & 0777) === 0600, 'Workspace marker must not expose operation data.');
        $marker = TempWorkspace::readMarker($workspaceRoot);
        $assert(is_array($marker), 'Workspace must carry a valid ownership marker.');
        if (! is_array($marker)) {
            throw new RuntimeException('Fixture workspace did not create its marker.');
        }
        $marker['created_at'] = time() - 600;
        file_put_contents($workspaceRoot . '/' . TempWorkspace::MARKER, json_encode($marker, JSON_THROW_ON_ERROR));
        $janitor = new TempDirectoryJanitor([], 60, $root . '/temp', $repo);
        $result = $janitor->cleanup();
        $assert(is_dir($workspaceRoot) && $result['removed'] === [], 'An active operation must survive cleanup even when its creation timestamp is stale.');
        $workspace->preserve();
        $result = $janitor->cleanup();
        $assert(is_dir($workspaceRoot) && $result['removed'] === [], 'A preserved recovery workspace must survive automatic cleanup after its lock is released.');
        $workspace->close();
        $assert(is_dir($workspaceRoot), 'Explicit close after preserve must retain recovery files.');

        $abandoned = $namespace . '/workspace-abandoned-' . bin2hex(random_bytes(8));
        mkdir($abandoned . '/payload', 0700, true);
        file_put_contents($abandoned . '/' . TempWorkspace::MARKER, json_encode($marker, JSON_THROW_ON_ERROR));
        chmod($abandoned . '/' . TempWorkspace::MARKER, 0600);
        touch($abandoned . '/' . TempWorkspace::LOCK);
        chmod($abandoned . '/' . TempWorkspace::LOCK, 0600);
        symlink($root . '/outside', $abandoned . '/payload/child-link');
        symlink($root . '/outside', $namespace . '/workspace-root-link');
        mkdir($root . '/temp/wporg-update-legacy', 0700);
        touch($root . '/temp/wporg-update-legacy', time() - 600);
        $result = $janitor->cleanup();
        $assert(in_array($abandoned, $result['removed'], true) && ! file_exists($abandoned), 'A marked, owned, stale and unlocked abandoned workspace must be removed.');
        $assert(is_link($namespace . '/workspace-root-link') && is_dir($root . '/temp/wporg-update-legacy'), 'Janitor must ignore linked roots and unmarked legacy prefix directories.');
        $assert(file_get_contents($root . '/outside/KEEP') === 'unrelated data', 'Janitor may unlink child links but must never traverse into unrelated targets.');
        $assert($result['failed'] === [], 'Safe janitor cleanup must succeed without attempting linked or legacy roots.');

        $fresh = TempWorkspace::create($repo, 'fresh', $root . '/temp');
        $freshRoot = dirname($fresh->path());
        $fresh->close();
        $fresh->close();
        $assert(! file_exists($freshRoot), 'Explicit close must be idempotent and delete its own operation workspace.');
        $replaced = TempWorkspace::create($repo, 'replaced', $root . '/temp');
        $replacedRoot = dirname($replaced->path());
        rename($replacedRoot, $replacedRoot . '-original');
        symlink($root . '/outside', $replacedRoot);
        $assert($throws(static fn () => $replaced->close()), 'Workspace close must refuse a replaced operation root.');
        $assert(is_link($replacedRoot) && file_get_contents($root . '/outside/KEEP') === 'unrelated data', 'Close must preserve unrelated replacement roots and never follow them.');
        $ancestor = TempWorkspace::create($repo, 'ancestor', $root . '/temp');
        rename($root . '/temp', $root . '/original-temp');
        symlink($root . '/outside', $root . '/temp');
        $assert($throws(static fn () => $ancestor->close()), 'Workspace close must reject a replaced temp ancestor.');
        $assert(file_get_contents($root . '/outside/KEEP') === 'unrelated data', 'Ancestor replacement must not cause outside cleanup.');
        unlink($root . '/temp');
        rename($root . '/original-temp', $root . '/temp');
        symlink($root . '/temp', $root . '/linked-temp');
        $assert($throws(static fn () => TempWorkspace::create($repo, 'linked', $root . '/linked-temp')), 'Workspace creation must reject a caller-supplied symlink temp root.');
        $linkedResult = (new TempDirectoryJanitor([], 60, $root . '/linked-temp', $repo))->cleanup();
        $assert($linkedResult['removed'] === [] && $linkedResult['failed'] !== [], 'Janitor must reject a symlink root before inspecting its descendants.');
        $assert(file_get_contents($root . '/outside/KEEP') === 'unrelated data', 'All filesystem hardening cases must preserve unrelated data.');
    } finally {
        $inspector->clearPath($root);
    }
}
