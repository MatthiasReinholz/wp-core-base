<?php

declare(strict_types=1);

use WpOrgPluginUpdater\Config;
use WpOrgPluginUpdater\EnvironmentDoctor;
use WpOrgPluginUpdater\RuntimeCompatibilityValidator;
use WpOrgPluginUpdater\RuntimeInspector;
use WpOrgPluginUpdater\RuntimeStager;

/** @param callable(bool,string):void $assert */
function run_runtime_compatibility_contract_tests(callable $assert, string $frameworkRoot): void
{
    $root = sys_get_temp_dir() . '/wp-core-base-compatibility-' . bin2hex(random_bytes(8));
    mkdir($root . '/wp-includes', 0700, true);
    mkdir($root . '/cms/plugins/sample', 0700, true);
    mkdir($root . '/.wp-core-base/build/runtime', 0700, true);
    $manifest = [
        'profile' => 'full-core',
        'core' => ['mode' => 'managed', 'enabled' => true],
        'paths' => ['content_root' => 'cms', 'plugins_root' => 'cms/plugins', 'themes_root' => 'cms/themes', 'mu_plugins_root' => 'cms/mu-plugins'],
        'dependencies' => [['slug' => 'sample', 'kind' => 'plugin', 'management' => 'local', 'source' => 'local', 'path' => 'cms/plugins/sample', 'main_file' => 'sample.php']],
    ];
    $mainFile = $root . '/cms/plugins/sample/sample.php';
    $versionFile = $root . '/wp-includes/version.php';
    $writePlugin = static function (?string $requires) use ($mainFile): void {
        file_put_contents($mainFile, "<?php\n/*\nPlugin Name: Sample\nVersion: 1.0.0\n" . ($requires === null ? '' : "Requires at least: $requires\n") . "*/\n");
    };
    $writeCore = static function (string $version) use ($versionFile): void {
        file_put_contents($versionFile, '<?php $wp_version = ' . var_export($version, true) . ';');
    };
    $failure = static function (callable $operation): ?string {
        try { $operation(); return null; } catch (RuntimeException $exception) { return $exception->getMessage(); }
    };
    $config = Config::fromArray($root, $manifest);
    $inspector = new RuntimeInspector($config->runtime);
    try {
        $writeCore('6.9.9');
        $writePlugin('7.0');
        file_put_contents($root . '/.wp-core-base/build/runtime/sentinel.php', 'last successful stage');
        $stager = new RuntimeStager($config, $inspector);
        $message = $failure(static fn () => $stager->stage('.wp-core-base/build/runtime'));
        $assert($message !== null && str_contains($message, 'requires WordPress 7.0') && str_contains($message, 'local managed core is 6.9.9'), 'An installed plugin requiring WordPress 7.0 must fail against local 6.9.9 with actionable version context.');
        $assert(file_get_contents($root . '/.wp-core-base/build/runtime/sentinel.php') === 'last successful stage', 'Compatibility rejection must preserve the previous successful stage.');
        $assert(glob($root . '/.wp-core-base/build/.wp-core-base-stage-*') === [], 'Compatibility rejection must clean its private assembly directory.');

        $managed = $manifest;
        $managed['dependencies'][0]['management'] = 'managed';
        $managed['dependencies'][0]['source'] = 'wordpress.org';
        $managed['dependencies'][0]['version'] = '1.0.0';
        $managed['dependencies'][0]['checksum'] = $inspector->computeChecksum(dirname($mainFile));
        $managedConfig = Config::fromArray($root, $managed);
        $assert($failure(static fn () => (new RuntimeStager($managedConfig, $inspector))->stage('.wp-core-base/build/runtime')) !== null, 'A correct managed checksum must not authorize a plugin incompatible with core.');

        $framework = require $frameworkRoot . '/.wp-core-base/framework.php';
        $framework['baseline']['wordpress_core'] = '99.0';
        file_put_contents($root . '/.wp-core-base/framework.php', '<?php return ' . var_export($framework, true) . ';');
        file_put_contents($root . '/.wp-core-base/manifest.php', '<?php return ' . var_export($manifest, true) . ';');
        $doctor = new EnvironmentDoctor($root, false);
        $assert($doctor->run() === 1, 'Doctor must fail for an incompatible installed plugin.');
        $messages = array_column($doctor->report()['messages'], 'message');
        $assert(count(array_filter($messages, static fn (string $message): bool => str_contains($message, 'local managed core is 6.9.9'))) === 1, 'Doctor JSON messages must identify actual local core, not the framework baseline version.');

        foreach ([['6.9.9', '6.9.9'], ['6.9.9', '6.8'], ['7.0', '7.0.0'], ['7.0-beta1', '7.0'], ['6.9.9', null]] as [$core, $requires]) {
            $writeCore($core);
            $writePlugin($requires);
            $assert($failure(static fn () => (new RuntimeCompatibilityValidator($config))->assertPluginCoreCompatibility($root)) === null, 'Compatible or unspecified minimum headers must remain accepted: ' . $core . '/' . ($requires ?? 'none'));
        }
        $stager->stage('.wp-core-base/build/runtime');
        $assert(is_file($root . '/.wp-core-base/build/runtime/cms/plugins/sample/sample.php'), 'A compatible plugin must actually stage successfully.');

        $writeCore('6.9.9');
        file_put_contents($mainFile, "<?php /*\rPlugin Name: Sample\rRequires at least: 7.0 */\r");
        $assert($failure(static fn () => (new RuntimeCompatibilityValidator($config))->assertPluginCoreCompatibility($root)) !== null, 'CR-only headers and comment terminators must follow WordPress header parsing.');
        file_put_contents($mainFile, "<?php /* Requires at least: 7.0 */\n");
        $assert($failure(static fn () => (new RuntimeCompatibilityValidator($config))->assertPluginCoreCompatibility($root)) !== null, 'A PHP opening tag on the header line must not hide the required version.');
        file_put_contents($mainFile, "<?php\n" . str_repeat(' ', 8192) . "\nRequires at least: 99.0\n");
        $assert($failure(static fn () => (new RuntimeCompatibilityValidator($config))->assertPluginCoreCompatibility($root)) === null, 'Text beyond the WordPress header region must not create a false compatibility requirement.');

        $writePlugin('7.0');
        foreach (['ignored', 'unstaged'] as $case) {
            $excluded = $manifest;
            if ($case === 'ignored') {
                $excluded['dependencies'][0]['management'] = 'ignored';
            } else {
                $excluded['runtime']['staged_kinds'] = ['theme'];
            }
            $assert($failure(static fn () => (new RuntimeStager(Config::fromArray($root, $excluded), $inspector))->stage('.wp-core-base/build/runtime')) === null, 'The compatibility gate must respect excluded runtime ownership: ' . $case);
        }

        foreach (['mu-plugin-package', 'mu-plugin-file'] as $kind) {
            $mu = $manifest;
            $mu['dependencies'][0]['kind'] = $kind;
            $mu['dependencies'][0]['path'] = $kind === 'mu-plugin-file' ? 'cms/mu-plugins/sample.php' : 'cms/mu-plugins/sample';
            $muPath = $root . '/' . $mu['dependencies'][0]['path'] . ($kind === 'mu-plugin-package' ? '/sample.php' : '');
            if (! is_dir(dirname($muPath))) { mkdir(dirname($muPath), 0700, true); }
            copy($mainFile, $muPath);
            $message = $failure(static fn () => (new RuntimeStager(Config::fromArray($root, $mu), $inspector))->stage('.wp-core-base/build/runtime'));
            $assert($message !== null && str_contains($message, 'requires WordPress 7.0'), 'Declared MU plugins must enforce their minimum core version: ' . $kind);
        }

        foreach (['relaxed-plugin', 'allowlisted-plugin', 'runtime-plugin', 'relaxed-mu', 'allowlisted-mu', 'runtime-mu', 'declared-nested-mu'] as $case) {
            $caseRoot = $root . '/cases/' . $case;
            $caseManifest = $manifest;
            $caseManifest['dependencies'] = [];
            $path = str_contains($case, '-mu') ? 'cms/mu-plugins/sample.php' : 'cms/plugins/sample.php';
            if ($case === 'relaxed-plugin') { $path = 'cms/plugins/sample/sample.php'; }
            if ($case === 'declared-nested-mu') { $path = 'cms/mu-plugins/sample/nested/start.php'; }
            mkdir(dirname($caseRoot . '/' . $path), 0700, true);
            mkdir($caseRoot . '/wp-includes', 0700, true);
            mkdir($caseRoot . '/.wp-core-base/build/runtime', 0700, true);
            file_put_contents($caseRoot . '/wp-includes/version.php', '<?php $wp_version = "6.9.9";');
            // Root MU files load even without Plugin Name; regular plugin discovery requires it.
            file_put_contents($caseRoot . '/' . $path, "<?php\n/*\n" . (str_contains($case, '-mu') ? '' : "Plugin Name: Discovered\n") . "Requires at least: 7.0\n*/\n");
            file_put_contents($caseRoot . '/.wp-core-base/build/runtime/sentinel.php', 'previous deployment');
            if (str_starts_with($case, 'relaxed-')) {
                $caseManifest['runtime']['manifest_mode'] = 'relaxed';
            } elseif (str_starts_with($case, 'allowlisted-')) {
                $caseManifest['runtime']['allow_runtime_paths'] = [$path];
            } elseif ($case === 'declared-nested-mu') {
                $caseManifest['dependencies'][] = ['slug' => 'sample', 'kind' => 'mu-plugin-package', 'management' => 'local', 'source' => 'local', 'path' => 'cms/mu-plugins/sample', 'main_file' => 'nested/start.php'];
            } else {
                $caseManifest['dependencies'][] = ['slug' => 'sample', 'kind' => 'runtime-file', 'management' => 'local', 'source' => 'local', 'path' => $path];
            }
            $caseConfig = Config::fromArray($caseRoot, $caseManifest);
            $caseInspector = new RuntimeInspector($caseConfig->runtime);
            $message = $failure(static fn () => (new RuntimeStager($caseConfig, $caseInspector))->stage('.wp-core-base/build/runtime'));
            $assert($message !== null && str_contains($message, 'requires WordPress 7.0'), 'Actual assembled compatibility must reject every selected plugin route: ' . $case);
            $assert(file_get_contents($caseRoot . '/.wp-core-base/build/runtime/sentinel.php') === 'previous deployment', 'Rejected discovered plugins must preserve the previous stage: ' . $case);
            $assert(glob($caseRoot . '/.wp-core-base/build/.wp-core-base-stage-*') === [], 'Rejected discovered plugins must clean their private assembly: ' . $case);
        }

        $discoveryRoot = $root . '/cases/discovery-boundaries';
        mkdir($discoveryRoot . '/cms/plugins/sample/nested', 0700, true);
        mkdir($discoveryRoot . '/wp-includes', 0700, true);
        file_put_contents($discoveryRoot . '/wp-includes/version.php', '<?php $wp_version = "6.9.9";');
        file_put_contents($discoveryRoot . '/cms/plugins/helper.php', "<?php /* Requires at least: 7.0 */\n");
        file_put_contents($discoveryRoot . '/cms/plugins/sample/nested/helper.php', "<?php\n/*\nPlugin Name: Nested non-entrypoint\nRequires at least: 7.0\n*/\n");
        $discoveryManifest = $manifest;
        $discoveryManifest['dependencies'] = [];
        $discoveryManifest['runtime']['manifest_mode'] = 'relaxed';
        $discoveryConfig = Config::fromArray($discoveryRoot, $discoveryManifest);
        $assert($failure(static fn () => (new RuntimeStager($discoveryConfig, new RuntimeInspector($discoveryConfig->runtime)))->stage('.wp-core-base/build/runtime')) === null, 'Discovery must not treat unnamed regular PHP or nested helper files as plugin entrypoints.');

        foreach (['content-only', 'full-core'] as $profile) {
            $external = $manifest;
            $external['profile'] = $profile;
            $external['core'] = ['mode' => 'external', 'enabled' => false];
            $externalConfig = Config::fromArray($root, $external);
            file_put_contents($versionFile, '<?php /* external deployment core is unknown */');
            $assert($failure(static fn () => (new RuntimeStager($externalConfig, $inspector))->stage('.wp-core-base/build/runtime')) === null, 'External core must remain unknown instead of being inferred from a stray version file: ' . $profile);
            file_put_contents($root . '/.wp-core-base/manifest.php', '<?php return ' . var_export($external, true) . ';');
            $doctor->run();
            $warnings = array_filter($doctor->report()['messages'], static fn (array $entry): bool => $entry['level'] === 'warn' && str_contains($entry['message'], 'Plugin minimum WordPress compatibility is unverified'));
            $assert(count($warnings) === 1, 'Doctor JSON must explicitly report unverified compatibility for external core: ' . $profile);
        }

        $assert($failure(static fn () => (new RuntimeCompatibilityValidator($config))->assertPluginCoreCompatibility($root)) !== null, 'A required managed core version must not silently become unknown when its local version file is malformed.');
        $writeCore('6.9.9');
        rename($mainFile, $mainFile . '.target');
        symlink($mainFile . '.target', $mainFile);
        $assert($failure(static fn () => (new RuntimeCompatibilityValidator($config))->assertPluginCoreCompatibility($root)) !== null, 'Header inspection must reject symlinked plugin entry points before reading them.');
    } finally {
        $inspector->clearPath($root);
    }
}
