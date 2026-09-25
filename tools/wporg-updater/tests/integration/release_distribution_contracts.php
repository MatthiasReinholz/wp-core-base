<?php

declare(strict_types=1);

use WpOrgPluginUpdater\Config;
use WpOrgPluginUpdater\DownstreamScaffolder;
use WpOrgPluginUpdater\FrameworkInstaller;
use WpOrgPluginUpdater\FrameworkReleaseArtifactBuilder;
use WpOrgPluginUpdater\FrameworkReleasePayload;
use WpOrgPluginUpdater\PathSwapWithRollback;
use WpOrgPluginUpdater\RuntimeInspector;
use WpOrgPluginUpdater\TempWorkspace;
use WpOrgPluginUpdater\ZipExtractor;

/** @param callable(bool,string):void $assert */
function run_release_distribution_contract_tests(callable $assert, string $repoRoot): void
{
    $workspace = TempWorkspace::create($repoRoot, 'release-contracts');
    $root = $workspace->path();
    $process = static function (array $arguments, string $cwd, ?array $environment = null): string {
        $handle = proc_open($arguments, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $environment);
        if (! is_resource($handle)) { throw new RuntimeException('Unable to start release contract subprocess.'); }
        $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($handle) !== 0) { throw new RuntimeException('Release contract subprocess failed: ' . $stderr . $stdout); }
        return (string) $stdout;
    };
    try {
        $fixture = $root . '/source';
        (new FrameworkReleaseArtifactBuilder($repoRoot))->copySnapshotTo($fixture);
        $process(['git', 'init', '-q'], $fixture);
        $process(['git', 'add', '.'], $fixture);
        $process(['git', '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '-qm', 'Immutable release input fixture'], $fixture);
        $revision = trim($process(['git', 'rev-parse', 'HEAD'], $fixture));
        $builder = new FrameworkReleaseArtifactBuilder($fixture);
        $first = $builder->build($root . '/first.zip');
        file_put_contents($fixture . '/.env', 'SYNTHETIC_SECRET=never-ship');
        mkdir($fixture . '/.phpstan');
        file_put_contents($fixture . '/.phpstan/cache.txt', 'cache');
        file_put_contents($fixture . '/README.md', 'uncommitted poisoned README');
        mkdir($fixture . '/tools/wporg-updater/tests', 0777, true);
        file_put_contents($fixture . '/tools/wporg-updater/tests/private.pem', 'SYNTHETIC PRIVATE KEY');
        chmod($fixture . '/bin/wp-core-base', 0600);
        $second = $builder->build($root . '/second.zip');
        $assert(hash_file('sha256', $first['artifact']) === hash_file('sha256', $second['artifact']), 'Expected byte-identical official builds despite dirty files, secrets, permissions, and mtimes.');
        $timezoneScript = $root . '/timezone-build.php';
        file_put_contents($timezoneScript, '<?php require ' . var_export($repoRoot . '/tools/wporg-updater/src/Autoload.php', true) . ';'
            . '(new WpOrgPluginUpdater\\FrameworkReleaseArtifactBuilder($argv[1]))->build($argv[2]);'
            . 'if (getenv("TZ") !== $argv[3]) { throw new RuntimeException("Builder changed caller timezone."); }');
        foreach (['America/New_York', 'Pacific/Auckland'] as $index => $timezone) {
            $environment = getenv();
            $environment['TZ'] = $timezone;
            $timezoneArtifact = $root . '/timezone-' . $index . '.zip';
            $process([PHP_BINARY, $timezoneScript, $fixture, $timezoneArtifact, $timezone], $fixture, $environment);
            $assert(hash_file('sha256', $first['artifact']) === hash_file('sha256', $timezoneArtifact), 'Expected identical DOS timestamps across process timezones and restored caller TZ.');
        }
        $assert($first['source_revision'] === $revision, 'Expected the release artifact to record an exact source commit.');
        $zip = new ZipArchive(); $zip->open($first['artifact']);
        $names = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = $zip->statIndex($index);
            $names[] = $entry['name'];
            $assert(($entry['comp_method'] ?? null) === ZipArchive::CM_STORE, 'Expected fixed stored compression policy independent of compression library versions.');
            $assert(! str_contains($entry['name'], '.env') && ! str_contains($entry['name'], 'private.pem') && ! str_contains($entry['name'], '.phpstan') && ! str_contains($entry['name'], '/wp-content/') && ! str_contains($entry['name'], '/wp-includes/'), 'Expected tooling-only release entries without runtime or workstation files.');
        }
        $sorted = $names; sort($sorted, SORT_STRING);
        $assert($names === $sorted, 'Expected deterministic sorted release entries.');
        $extract = $root . '/extract'; mkdir($extract);
        ZipExtractor::extractValidated($zip, $extract); $zip->close();
        $payload = $extract . '/wp-core-base';
        FrameworkReleasePayload::verify($payload);
        $assert(filesize($first['artifact']) < 5 * 1024 * 1024, 'Expected the framework tooling artifact to remain below 5 MiB.');
        $inventory = json_decode((string) file_get_contents($payload . '/' . FrameworkReleasePayload::INVENTORY), true);
        $assert(($inventory['source_revision'] ?? null) === $revision, 'Expected independent inventory provenance.');
        file_put_contents($payload . '/README.md', 'tampered');
        $rejected = false;
        try { FrameworkReleasePayload::verify($payload); } catch (RuntimeException) { $rejected = true; }
        $assert($rejected, 'Expected verification to detect content changes independently of builder output.');
        $zip->open($first['artifact']);
        file_put_contents($payload . '/README.md', (string) $zip->getFromName('wp-core-base/README.md')); $zip->close();

        $previous = hash_file('sha256', $first['artifact']);
        file_put_contents($root . '/not-a-directory', 'sentinel');
        $failed = false;
        set_error_handler(static fn (): bool => true);
        try { $builder->build($first['artifact'], $root . '/not-a-directory/checksum'); }
        catch (RuntimeException) { $failed = true; }
        finally { restore_error_handler(); }
        $assert($failed && hash_file('sha256', $first['artifact']) === $previous, 'Expected failed artifact publication to preserve the prior complete ZIP.');
        $failed = false;
        try { $builder->build($root . '/fixture-in-git.zip', null, null, true); } catch (RuntimeException) { $failed = true; }
        $assert($failed, 'Expected explicit fixture mode to reject real Git worktrees.');

        foreach (['github', 'gitlab'] as $host) {
            foreach (['content-only', 'full-core'] as $profile) {
                $downstream = $root . '/downstream-' . $host . '-' . $profile; mkdir($downstream);
                (new DownstreamScaffolder($repoRoot, $downstream, true))->scaffold('vendor/wp-core-base', $profile, $profile === 'content-only' ? 'cms' : 'wp-content', true, false, $host);
                $config = Config::load($downstream);
                (new FrameworkInstaller($downstream, new RuntimeInspector($config->runtime)))->apply($payload, 'vendor/wp-core-base');
                $assert(is_file($downstream . '/vendor/wp-core-base/bin/wp-core-base') && ! is_dir($downstream . '/vendor/wp-core-base/wp-includes'), 'Expected slim release installation for ' . $host . '/' . $profile . '.');
            }
        }

        $swapRoot = $root . '/swap'; mkdir($swapRoot); mkdir($swapRoot . '/target'); mkdir($swapRoot . '/staging');
        file_put_contents($swapRoot . '/target/old', 'old'); file_put_contents($swapRoot . '/staging/new', 'new');
        $inspector = new RuntimeInspector(Config::load($repoRoot)->runtime);
        $swap = new PathSwapWithRollback($inspector);
        $swap->swap($swapRoot . '/target', $swapRoot . '/staging', $swapRoot . '/backup', $swapRoot);
        rename($swapRoot . '/backup', $swapRoot . '/missing-backup');
        $failed = false;
        try { $swap->rollback($swapRoot . '/target', $swapRoot . '/backup'); } catch (RuntimeException) { $failed = true; }
        $assert($failed && is_file($swapRoot . '/target/new'), 'Expected missing recovery backup to preserve the current installed path.');
        rename($swapRoot . '/missing-backup', $swapRoot . '/backup');
        $swap->rollback($swapRoot . '/target', $swapRoot . '/backup');
        $assert(is_file($swapRoot . '/target/old'), 'Expected successful rollback to restore the old payload.');

        // Opt-in exact historical artifact compatibility lane; the caller supplies a verified v1.4.8 archive.
        $legacy = getenv('WP_CORE_BASE_LEGACY_RELEASE_ARTIFACT');
        if (is_string($legacy) && $legacy !== '') {
            $legacyExtract = $root . '/legacy'; mkdir($legacyExtract);
            $legacyZip = new ZipArchive();
            if ($legacyZip->open($legacy) !== true) { throw new RuntimeException('Cannot open supplied legacy release artifact.'); }
            ZipExtractor::extractValidated($legacyZip, $legacyExtract); $legacyZip->close();
            $legacyRoot = $legacyExtract . '/wp-core-base';
            FrameworkReleasePayload::verify($legacyRoot);
            foreach (['github', 'gitlab'] as $host) {
                foreach (['full-core', 'content-only'] as $profile) {
                    $downstream = $root . '/legacy-install-' . $host . '-' . $profile; mkdir($downstream);
                    $script = <<<'SCRIPT'
require $argv[1] . '/tools/wporg-updater/src/Autoload.php';
(new WpOrgPluginUpdater\DownstreamScaffolder($argv[1], $argv[2]))->scaffold('vendor/wp-core-base', $argv[3], $argv[3] === 'full-core' ? 'wp-content' : 'cms', true, false, $argv[4]);
$config = WpOrgPluginUpdater\Config::load($argv[2]);
(new WpOrgPluginUpdater\FrameworkInstaller($argv[2], new WpOrgPluginUpdater\RuntimeInspector($config->runtime)))->apply($argv[5], 'vendor/wp-core-base');
SCRIPT;
                    $process([PHP_BINARY, '-r', $script, $legacyRoot, $downstream, $profile, $host, $payload], $root);
                    $assert(is_file($downstream . '/vendor/wp-core-base/' . FrameworkReleasePayload::INVENTORY), 'Expected v1.4.8 installer to accept slim release for ' . $host . '/' . $profile . '.');
                }
            }
            foreach (['full-core', 'content-only'] as $profile) {
                $downstream = $root . '/new-install-old-' . $profile; mkdir($downstream);
                (new DownstreamScaffolder($repoRoot, $downstream, true))->scaffold('vendor/wp-core-base', $profile, $profile === 'full-core' ? 'wp-content' : 'cms', true);
                $config = Config::load($downstream);
                (new FrameworkInstaller($downstream, new RuntimeInspector($config->runtime)))->apply($legacyRoot, 'vendor/wp-core-base');
                $assert(is_file($downstream . '/vendor/wp-core-base/wp-includes/version.php'), 'Expected new installer to accept historical full snapshot for ' . $profile . '.');
            }
        }
    } finally {
        $workspace->close();
    }
}
