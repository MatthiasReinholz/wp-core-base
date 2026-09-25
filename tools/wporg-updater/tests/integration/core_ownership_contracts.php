<?php

declare(strict_types=1);

use WpOrgPluginUpdater\Config;
use WpOrgPluginUpdater\CoreContentOwnership;
use WpOrgPluginUpdater\CoreUpdater;
use WpOrgPluginUpdater\RuntimeInspector;
use WpOrgPluginUpdater\RuntimeOwnershipInspector;

/** @param callable(bool,string):void $assert */
function run_core_ownership_contract_tests(callable $assert, string $repoRoot): void
{
    $config = Config::load($repoRoot);
    $policy = new CoreContentOwnership($config);
    $assert(! $policy->mayReplace('wp-content/plugins/akismet', true), 'Core archives must preserve local bundled plugins.');
    $assert(! $policy->mayReplace('wp-content/plugins/woocommerce/woocommerce.php', false), 'Core archives must preserve independently managed dependencies.');
    $assert(! $policy->mayReplace('wp-content/themes/twentytwentysix', true), 'Core archives must not introduce unowned bundled themes.');
    $assert($policy->mayReplace('wp-content/plugins/index.php', false), 'Core-owned loose files remain eligible.');
    $manifest = $config->toArray();
    $manifest['dependencies'][] = [
        'name' => 'Project hello', 'slug' => 'project-hello', 'kind' => 'mu-plugin-file',
        'management' => 'local', 'source' => 'local', 'path' => 'wp-content/mu-plugins/project-hello.php',
        'main_file' => 'project-hello.php', 'version' => '1.0.0',
    ];
    $localConfig = Config::fromArray($repoRoot, $manifest, $repoRoot . '/.wp-core-base/manifest.php');
    $assert(! (new CoreContentOwnership($localConfig))->mayReplace('wp-content/mu-plugins/project-hello.php', false), 'Core archives must preserve explicitly owned individual files.');

    $root = sys_get_temp_dir() . '/core-ownership-ingestion-' . bin2hex(random_bytes(6));
    mkdir($root . '/archive/plugins/akismet', 0700, true);
    mkdir($root . '/archive/themes/twentytwentysix', 0700, true);
    mkdir($root . '/cms/plugins', 0700, true);
    $dependencies = [];
    foreach (['local', 'ignored', 'managed'] as $management) {
        $dependencies[] = [
            'slug' => $management . '-file', 'kind' => 'runtime-file', 'management' => $management,
            'source' => $management === 'managed' ? 'github-release' : 'local',
            'path' => 'cms/plugins/' . $management . '.php',
            ...($management === 'managed' ? [
                'version' => '1.0.0', 'checksum' => 'sha256:' . str_repeat('a', 64),
                'source_config' => ['github_repository' => 'example/plugin', 'verification_mode' => 'none'],
            ] : []),
        ];
        file_put_contents($root . '/cms/plugins/' . $management . '.php', 'project-owned ' . $management);
        file_put_contents($root . '/archive/plugins/' . $management . '.php', 'bundled replacement');
    }
    $fixtureManifest = [
        'paths' => ['content_root' => 'cms', 'plugins_root' => 'cms/plugins', 'themes_root' => 'cms/themes', 'mu_plugins_root' => 'cms/mu-plugins'],
        'runtime' => ['allow_runtime_paths' => ['cms/index.php', 'cms/plugins/index.php', 'cms/themes/index.php']],
        'dependencies' => $dependencies,
    ];
    $fixtureConfig = Config::fromArray($root, $fixtureManifest);
    $inspector = new RuntimeInspector($fixtureConfig->runtime);
    try {
        foreach (['index.php', 'plugins/index.php', 'themes/index.php'] as $stub) {
            file_put_contents($root . '/archive/' . $stub, 'core stub ' . $stub);
        }
        file_put_contents($root . '/archive/plugins/hello.php', '<?php /* Plugin Name: Hello Dolly */');
        file_put_contents($root . '/archive/plugins/akismet/akismet.php', 'bundled plugin');
        file_put_contents($root . '/archive/themes/twentytwentysix/style.css', 'bundled theme');
        $reflection = new ReflectionClass(CoreUpdater::class);
        $updater = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('config')->setValue($updater, $fixtureConfig);
        $ingest = $reflection->getMethod('syncCoreWpContent');
        $paths = $ingest->invoke($updater, $root . '/archive');
        sort($paths);
        $assert($paths === ['cms/index.php', 'cms/plugins/index.php', 'cms/themes/index.php'], 'Actual core ingestion must report only its standard unowned index stubs.');
        foreach (['index.php', 'plugins/index.php', 'themes/index.php'] as $stub) {
            $assert(file_get_contents($root . '/cms/' . $stub) === 'core stub ' . $stub, 'Core ingestion must install the permitted stub ' . $stub);
        }
        $assert(! file_exists($root . '/cms/plugins/hello.php'), 'Core ingestion must not install an undeclared single-file bundled plugin.');
        $assert(! file_exists($root . '/cms/plugins/akismet') && ! file_exists($root . '/cms/themes/twentytwentysix'), 'Core ingestion must not install undeclared bundled packages.');
        foreach (['local', 'ignored', 'managed'] as $management) {
            $assert(file_get_contents($root . '/cms/plugins/' . $management . '.php') === 'project-owned ' . $management, 'Core ingestion must preserve declared ' . $management . ' files.');
        }
        $assert((new RuntimeOwnershipInspector($fixtureConfig))->undeclaredRuntimePaths() === [], 'Core ingestion must not introduce undeclared runtime entries.');

        $fixtureManifest['dependencies'][] = [
            'slug' => 'owned-index', 'kind' => 'runtime-file', 'management' => 'local', 'source' => 'local', 'path' => 'cms/plugins/index.php',
        ];
        file_put_contents($root . '/cms/plugins/index.php', 'project-owned index');
        $ownedIndexUpdater = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('config')->setValue($ownedIndexUpdater, Config::fromArray($root, $fixtureManifest));
        $ownedIndexPaths = $ingest->invoke($ownedIndexUpdater, $root . '/archive');
        $assert(file_get_contents($root . '/cms/plugins/index.php') === 'project-owned index', 'Explicit ownership must protect even an otherwise eligible core index stub.');
        $assert(! in_array('cms/plugins/index.php', $ownedIndexPaths, true), 'Core ingestion must exclude preserved owned index stubs from its changed paths.');
    } finally {
        $inspector->clearPath($root);
    }
}
