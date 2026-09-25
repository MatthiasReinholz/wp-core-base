<?php

declare(strict_types=1);

use WpOrgPluginUpdater\Config;
use WpOrgPluginUpdater\ConfigMutationStateManager;
use WpOrgPluginUpdater\DependencyAuthoringService;
use WpOrgPluginUpdater\DependencyMetadataResolver;
use WpOrgPluginUpdater\DependencyMutationTransaction;
use WpOrgPluginUpdater\ManagedSourceRegistry;
use WpOrgPluginUpdater\ManifestWriter;
use WpOrgPluginUpdater\RuntimeInspector;
use WpOrgPluginUpdater\TempWorkspace;

/** @param callable(bool,string):void $assert */
function run_dependency_recovery_contract_tests(callable $assert): void
{
    $root = sys_get_temp_dir() . '/dependency-recovery-test-' . bin2hex(random_bytes(6));
    mkdir($root . '/.wp-core-base', 0775, true);
    mkdir($root . '/cms/plugins/example', 0775, true);
    $config = Config::fromArray($root, ['profile' => 'content-only', 'paths' => ['content_root' => 'cms']]);
    $writer = new ManifestWriter();
    $writer->write($config);
    $originalManifest = file_get_contents($config->manifestPath);
    $inspector = new RuntimeInspector($config->runtime);
    $manager = new ConfigMutationStateManager($writer, $inspector);
    $runtime = $root . '/cms/plugins/example';
    file_put_contents($runtime . '/original.php', 'original');
    $namespace = TempWorkspace::namespacePath($root);
    try {
        $framework = require dirname(__DIR__, 4) . '/.wp-core-base/framework.php';
        $framework['distribution']['path'] = 'lib/wp-core-base';
        foreach (['.git', '.wp-core-base', 'lib/wp-core-base'] as $index => $controlPath) {
            $protectedRoot = $root . '/protected-' . $index;
            mkdir($protectedRoot . '/.wp-core-base', 0700, true);
            if (! is_dir($protectedRoot . '/' . $controlPath)) {
                mkdir($protectedRoot . '/' . $controlPath, 0700, true);
            }
            $sentinelPath = $protectedRoot . '/' . $controlPath . '/KEEP';
            file_put_contents($sentinelPath, 'control payload');
            $frameworkPath = $protectedRoot . '/.wp-core-base/framework.php';
            $frameworkContents = '<?php return ' . var_export($framework, true) . ';';
            file_put_contents($frameworkPath, $frameworkContents);
            $protectedConfig = Config::fromArray($protectedRoot, [
                'profile' => 'content-only',
                'paths' => ['content_root' => '.', 'plugins_root' => 'plugins', 'themes_root' => 'themes', 'mu_plugins_root' => 'mu-plugins'],
                'runtime' => ['stage_dir' => 'build/runtime'],
                'dependencies' => [['slug' => 'documented-control', 'kind' => 'runtime-directory', 'management' => 'ignored', 'source' => 'local', 'path' => $controlPath]],
            ]);
            $writer->write($protectedConfig);
            $protectedManifest = file_get_contents($protectedConfig->manifestPath);
            $service = new DependencyAuthoringService($protectedConfig, new DependencyMetadataResolver(), $inspector, $writer, new ManagedSourceRegistry());
            $rejected = false;
            try {
                $service->removeDependency(['component-key' => 'runtime-directory:local:documented-control', 'delete-path' => true]);
            } catch (RuntimeException $exception) {
                $rejected = str_contains($exception->getMessage(), 'repository control path');
            }
            $assert($rejected, 'Ignored ownership must not authorize deletion of control path ' . $controlPath);
            $assert(file_get_contents($sentinelPath) === 'control payload', 'Rejected control deletion must preserve the payload at ' . $controlPath);
            $assert(file_get_contents($protectedConfig->manifestPath) === $protectedManifest, 'Rejected control deletion must preserve the manifest for ' . $controlPath);
            $assert(file_get_contents($frameworkPath) === $frameworkContents, 'Rejected control deletion must preserve framework metadata for ' . $controlPath);
            $result = $service->removeDependency(['component-key' => 'runtime-directory:local:documented-control']);
            $assert($result['deleted_path'] === false && Config::load($protectedRoot)->dependencies() === [], 'Manifest-only removal of ignored control entries must remain supported.');
            $assert(file_get_contents($sentinelPath) === 'control payload' && file_get_contents($frameworkPath) === $frameworkContents, 'Manifest-only removal must leave control payloads and framework metadata intact.');
        }

        $transaction = new DependencyMutationTransaction($config, $config, $manager, $inspector, $runtime);
        $transaction->beginRuntimeMutation();
        $inspector->clearPath($runtime);
        mkdir($runtime);
        file_put_contents($runtime . '/replacement.php', 'replacement');
        file_put_contents($config->manifestPath, 'changed');
        $original = new RuntimeException('Injected operation failure.');
        try {
            $transaction->rollback($original);
        } catch (Throwable $failure) {
            $assert($failure === $original, 'Successful recovery must preserve the original exception.');
        }
        $assert(file_get_contents($runtime . '/original.php') === 'original', 'Dependency rollback must restore the previous payload.');
        $assert(! file_exists($runtime . '/replacement.php'), 'Dependency rollback must remove partially installed payload files.');
        $assert(file_get_contents($config->manifestPath) === $originalManifest, 'Dependency rollback must restore its manifest.');

        $transaction = new DependencyMutationTransaction($config, $config, $manager, $inspector, $runtime);
        $transaction->beginRuntimeMutation();
        file_put_contents($runtime . '/original.php', 'changed-again');
        unlink($config->manifestPath);
        mkdir($config->manifestPath);
        file_put_contents($config->manifestPath . '/blocks-restore', 'sentinel');
        $failure = null;
        set_error_handler(static fn (): bool => true);
        try {
            $transaction->rollback(new RuntimeException('Injected persistence failure.'));
        } catch (Throwable $exception) {
            $failure = $exception;
        } finally {
            restore_error_handler();
        }
        $assert($failure instanceof RuntimeException && str_contains($failure->getMessage(), 'backups retained'), 'Failed recovery must report retained backup location.');
        $assert(file_get_contents($runtime . '/original.php') === 'original', 'One failed config restore must not prevent payload restoration.');
        $retained = glob($namespace . '/workspace-dependency-recovery-*') ?: [];
        $assert(count($retained) === 1, 'Only the failed recovery workspace must remain.');
        $marker = TempWorkspace::readMarker($retained[0]);
        $assert(($marker['preserved'] ?? false) === true, 'Recovery backup must be excluded from automatic cleanup.');
        $assert(is_file($retained[0] . '/payload/recovery.json'), 'Recovery backup must contain durable configuration state and target metadata.');
    } finally {
        $inspector->clearPath($root);
        if (is_dir($namespace)) {
            TempWorkspace::removeOwnedTree($namespace);
        }
    }
}
