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
    run_core_refresh_native_noop_contract_tests($assert);
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

/** @param callable(bool,string):void $assert */
function run_core_refresh_native_noop_contract_tests(callable $assert): void
{
    $root = sys_get_temp_dir() . '/core-refresh-base-noop-' . bin2hex(random_bytes(6));
    mkdir($root, 0700, true);
    $run = static function (string $cwd, array $args): string {
        $process = proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        if (! is_resource($process)) { throw new RuntimeException('Unable to start native Git fixture.'); }
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) { throw new RuntimeException('Native Git fixture failed: ' . substr((string) $err, 0, 2048)); }
        return trim((string) $out);
    };
    $oldGlobal = getenv('GIT_CONFIG_GLOBAL');
    file_put_contents($root . '/signed-profile', "[commit]\n\tgpgsign = true\n[user]\n\tsigningkey = unavailable-fixture-key\n");
    putenv('GIT_CONFIG_GLOBAL=' . $root . '/signed-profile');
    try {
        foreach (['pending-identical', 'base-satisfied', 'pending-changed'] as $case) {
            $caseRoot = $root . '/' . $case; mkdir($caseRoot);
            $remote = $caseRoot . '/remote.git'; $work = $caseRoot . '/work';
            $run($caseRoot, ['git', 'init', '--bare', $remote]);
            $run($caseRoot, ['git', 'init', '-b', 'main', $work]);
            $run($work, ['git', 'config', 'user.name', 'Core refresh fixture']);
            $run($work, ['git', 'config', 'user.email', 'fixture@example.test']);
            // Only this disposable repository opts out of the inherited signer.
            $run($work, ['git', 'config', 'commit.gpgsign', 'false']);
            mkdir($work . '/wp-includes'); mkdir($work . '/wp-admin'); mkdir($work . '/.wp-core-base');
            $baseVersion = $case === 'base-satisfied' ? '7.1.4' : '7.1.3';
            $version = static fn (string $v): string => '<?php $wp_version = ' . var_export($v, true) . ';';
            file_put_contents($work . '/wp-includes/version.php', $version($baseVersion));
            file_put_contents($work . '/wp-admin/index.php', 'verified target admin');
            file_put_contents($work . '/.gitignore', "/.wp-core-base/build/\n");
            $manifest = ['profile' => 'full-core', 'core' => ['mode' => 'managed', 'enabled' => true], 'dependencies' => []];
            file_put_contents($work . '/.wp-core-base/manifest.php', '<?php return ' . var_export($manifest, true) . ';');
            $run($work, ['git', 'add', '.']); $run($work, ['git', 'commit', '--no-gpg-sign', '-m', 'Current default base']);
            $run($work, ['git', 'remote', 'add', 'origin', $remote]); $run($work, ['git', 'push', '-u', 'origin', 'main']);
            $base = $run($work, ['git', 'rev-parse', 'HEAD']);
            $branch = 'codex/wordpress-core-7-1-4-fixture';
            $run($work, ['git', 'checkout', '-b', $branch]);
            if ($case !== 'base-satisfied') {
                file_put_contents($work . '/wp-includes/version.php', $version('7.1.4'));
                if ($case === 'pending-changed') { file_put_contents($work . '/wp-admin/index.php', 'older target bytes'); }
                $run($work, ['git', 'add', '.']); $run($work, ['git', 'commit', '--no-gpg-sign', '-m', 'Existing target branch']);
            }
            $head = $run($work, ['git', 'rev-parse', 'HEAD']);
            $run($work, ['git', 'push', 'origin', $branch]); $run($work, ['git', 'checkout', 'main']);
            $archive = $caseRoot . '/verified-fixture.zip';
            $files = ['wp-includes/version.php' => $version('7.1.4'), 'wp-admin/index.php' => 'verified target admin'];
            $zip = new ZipArchive();
            if ($zip->open($archive, ZipArchive::CREATE) !== true) { throw new RuntimeException('Unable to create tiny core fixture ZIP.'); }
            foreach ($files as $name => $body) { $zip->addFromString('wordpress/' . $name, $body); }
            $zip->close();
            $checksums = new class($files) implements \WpOrgPluginUpdater\JsonHttpTransport {
                public int $reads = 0;
                public function __construct(private readonly array $files) {}
                public function getJsonWithOptions(string $url, array $headers = [], array $options = []): array {
                    if ($url !== 'https://api.wordpress.org/core/checksums/1.0/?version=7.1.4&locale=en_US'
                        || $headers !== [] || ($options['max_redirects'] ?? null) !== 3) { throw new LogicException('Unexpected core checksum request.'); }
                    $this->reads++;
                    return ['checksums' => array_map('md5', $this->files)];
                }
                public function downloadToFileWithOptions(string $url, string $destination, array $headers = [], array $options = []): void { throw new LogicException('Unexpected transport download.'); }
            };
            $download = new class($archive) implements \WpOrgPluginUpdater\ArchiveDownloader {
                public function __construct(private readonly string $archive) {}
                public function downloadToFile(string $url, string $destination, array $headers = []): void {
                    if ($url !== 'https://example.test/core714.zip' || ! copy($this->archive, $destination)) { throw new LogicException('Unexpected archive request.'); }
                }
            };
            $client = new FakeGitHubAutomationClient();
            $pr = ['number' => 42, 'state' => 'open', 'draft' => false,
                'labels' => [['name' => 'automation:dependency-update']],
                'metadata' => ['kind' => 'core', 'slug' => 'wordpress-core', 'branch' => $branch, 'base_version' => $baseVersion],
                'head' => ['ref' => $branch, 'sha' => $head, 'repo' => ['full_name' => 'example/site']],
                'base' => ['ref' => 'main', 'repo' => ['full_name' => 'example/site']],
                'planned_target_version' => '7.1.4', 'planned_release_at' => '2026-11-03T00:00:00Z', 'planned_scope' => 'patch', 'requires_code_update' => true];
            $pr['body'] = '<!-- wporg-update-metadata: ' . json_encode($pr['metadata'], JSON_THROW_ON_ERROR) . ' -->';
            $git = new \WpOrgPluginUpdater\GitCommandRunner($work);
            $updater = new CoreUpdater(Config::load($work), new \WpOrgPluginUpdater\CoreScanner(),
                new \WpOrgPluginUpdater\WordPressCoreClient(new \WpOrgPluginUpdater\HttpClient(), $checksums),
                new \WpOrgPluginUpdater\ReleaseClassifier(), new \WpOrgPluginUpdater\PrBodyRenderer(), $client, $git, $download);
            $release = ['version' => '7.1.4', 'download_url' => 'https://example.test/core714.zip',
                'release_url' => 'https://example.test/core714', 'release_text' => 'Fixture patch.', 'release_html' => '<p>Fixture patch.</p>'];
            $result = (new ReflectionMethod(CoreUpdater::class, 'refreshPullRequest'))->invoke($updater, $baseVersion, $release, $pr, [], 'main', $base);
            $remoteRef = $run($work, ['git', 'ls-remote', '--refs', 'origin', 'refs/heads/' . $branch]);
            $assert($run($work, ['git', 'rev-parse', 'origin/main']) === $base, 'Refresh must preserve the exact current default-base commit.');
            if ($case === 'base-satisfied') {
                $assert(! $result && count($client->closedPullRequests) === 1 && $remoteRef === '', 'Only a target genuinely satisfied by current base closes its owned PR and branch.');
                $assert($checksums->reads === 0, 'An already satisfied base must not download or apply another core archive.');
            } else {
                $assert($result && $client->closedPullRequests === [] && count($client->updatedPullRequests) === 1, 'An already-current pending target branch must remain open against an older default base.');
                $assert($checksums->reads === 1 && str_contains($remoteRef, 'refs/heads/' . $branch), 'Pending refresh must checksum-verify the target and preserve its remote branch.');
                $assert(($case === 'pending-identical') === str_starts_with($remoteRef, $head), 'Identical refresh must leave its exact remote head untouched; changed target must receive one normal new commit.');
                $assert($git->successfulPushRevision($branch) === ($case === 'pending-identical' ? null : explode("\t", $remoteRef)[0]), 'No staged delta must not fabricate a successful push receipt.');
            }
            $assert($run($work, ['git', 'status', '--porcelain']) === '', 'Native refresh fixtures must finish clean.');
        }
    } finally {
        putenv($oldGlobal === false ? 'GIT_CONFIG_GLOBAL' : 'GIT_CONFIG_GLOBAL=' . $oldGlobal);
        (new RuntimeInspector([]))->clearPath($root);
    }
}
