<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class EnvironmentDoctor
{
    private int $errors = 0;
    private int $warnings = 0;

    public function __construct(
        private readonly string $repoRoot,
    ) {
    }

    public function run(bool $requireGitHub = false): int
    {
        $this->printHeading('wp-core-base doctor');

        $this->okIf(is_dir($this->repoRoot), sprintf('Repository root exists: %s', $this->repoRoot), 'Repository root does not exist.');
        $this->okIf(PHP_VERSION_ID >= 80100, sprintf('PHP version is %s.', PHP_VERSION), 'PHP 8.1 or newer is required.');
        $this->warnIf(PHP_VERSION_ID < 80300, sprintf('PHP version is %s. PHP 8.3 is recommended to match CI.', PHP_VERSION));

        foreach (['curl', 'dom', 'json', 'libxml', 'simplexml', 'zip'] as $extension) {
            $this->okIf(extension_loaded($extension), sprintf('PHP extension loaded: %s', $extension), sprintf('Missing required PHP extension: %s', $extension));
        }

        $this->okIf($this->commandExists('git'), 'git is available.', 'git is not available on PATH.');
        $this->okIf($this->isGitRepository(), 'Repository root is inside a Git worktree.', 'Repository root is not inside a Git worktree.');

        $manifestPath = $this->repoRoot . '/.wp-core-base/manifest.php';
        $this->okIf(is_file($manifestPath), 'Manifest file exists.', sprintf('Manifest file not found: %s', $manifestPath));

        $config = null;

        try {
            $config = Config::load($this->repoRoot);
            $this->ok(sprintf('Manifest loaded successfully for profile `%s`.', $config->profile));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
        }

        if ($config !== null) {
            $this->inspectConfiguredStructure($config);
            $this->inspectDependencies($config);
            $this->inspectRuntimeOwnership($config);
            $this->inspectRuntimeStaging($config);
        }

        $this->inspectGitHubEnvironment($config, $requireGitHub);
        $this->inspectGitHubWorkflows($requireGitHub);
        $this->printSummary();

        return $this->errors === 0 ? 0 : 1;
    }

    private function inspectConfiguredStructure(Config $config): void
    {
        foreach ($config->paths as $key => $path) {
            $this->okIf(
                $this->isSafeRelativePath($path),
                sprintf('Configured %s path is valid: %s', $key, $path),
                sprintf('Configured %s path is not a safe relative path: %s', $key, $path)
            );
        }

        $this->ok(sprintf(
            'Runtime ownership mode is `%s`; validation mode is `%s`; staged kinds: %s; validated kinds: %s.',
            $config->manifestMode(),
            $config->validationMode(),
            implode(', ', $config->stagedKinds()),
            implode(', ', $config->validatedKinds())
        ));
        $this->ok(sprintf('Ownership roots: %s.', implode(', ', $config->ownershipRoots())));

        if ($config->profile === 'content-only') {
            $this->okIf(
                ! $config->coreManaged(),
                'content-only profile is configured with external core.',
                'content-only profile may not manage WordPress core.'
            );
            return;
        }

        if (! $config->coreEnabled()) {
            $this->warn('full-core profile has core updates disabled.');
            return;
        }

        try {
            $core = (new CoreScanner())->inspect($this->repoRoot);
            $this->ok(sprintf('WordPress core detected at version %s.', $core['version']));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
        }
    }

    private function inspectDependencies(Config $config): void
    {
        if ($config->dependencies() === []) {
            $this->warn('No dependencies are declared in the manifest.');
            return;
        }

        $scanner = new DependencyScanner();
        $runtimeInspector = new RuntimeInspector($config->runtime);

        foreach ($config->dependencies() as $dependency) {
            $absolutePath = $config->repoRoot . '/' . $dependency['path'];
            $kind = (string) $dependency['kind'];
            $classification = sprintf('%s %s', $dependency['management'], $kind);

            $this->okIf(
                file_exists($absolutePath) || is_link($absolutePath),
                sprintf('Declared %s path exists: %s', $classification, $dependency['path']),
                sprintf('Declared %s path does not exist: %s', $classification, $dependency['path'])
            );

            if ($dependency['management'] === 'ignored') {
                $this->ok(sprintf('Ignored dependency registered: %s.', $dependency['component_key']));
                continue;
            }

            if (in_array($kind, ['plugin', 'theme', 'mu-plugin-package'], true)) {
                $mainFile = $absolutePath . '/' . $dependency['main_file'];
                $this->okIf(
                    is_file($mainFile),
                    sprintf('Main file exists for %s: %s', $dependency['slug'], $dependency['main_file']),
                    sprintf('Main file not found for %s: %s', $dependency['slug'], (string) $dependency['main_file'])
                );
            }

            try {
                $state = $scanner->inspect($config->repoRoot, $dependency);
                $this->ok(sprintf(
                    'Declared %s dependency %s detected at %s (%s).',
                    $dependency['management'],
                    $dependency['component_key'],
                    $state['version'] ?? 'unknown',
                    $state['path']
                ));
            } catch (RuntimeException $exception) {
                $this->error($exception->getMessage());
                continue;
            }

            if (! $config->shouldValidateDependency($dependency)) {
                $this->ok(sprintf('Validation skipped for %s because kind `%s` is not in runtime.validated_kinds.', $dependency['component_key'], $kind));
                continue;
            }

            [$globalAllowPaths, $stripPaths, $stripFiles] = $this->sourceValidationRules($config, $dependency);

            try {
                $runtimeInspector->assertPathIsClean(
                    $absolutePath,
                    array_values(array_unique(array_merge((array) $dependency['policy']['allow_runtime_paths'], $globalAllowPaths))),
                    [],
                    $stripPaths,
                    $stripFiles
                );
                $this->ok(sprintf('Runtime hygiene passed for %s.', $dependency['component_key']));
            } catch (RuntimeException $exception) {
                $this->error($exception->getMessage());
            }

            if ($dependency['management'] !== 'managed') {
                continue;
            }

            try {
                $checksum = $runtimeInspector->computeChecksum($absolutePath);

                if ($checksum !== $dependency['checksum']) {
                    $this->error(sprintf(
                        'Checksum drift detected for %s. Expected %s but found %s.',
                        $dependency['component_key'],
                        $dependency['checksum'],
                        $checksum
                    ));
                } else {
                    $this->ok(sprintf('Checksum matches manifest for %s.', $dependency['component_key']));
                }
            } catch (RuntimeException $exception) {
                $this->error($exception->getMessage());
            }
        }
    }

    private function inspectRuntimeOwnership(Config $config): void
    {
        $ownershipInspector = new RuntimeOwnershipInspector($config);
        $undeclaredPaths = $ownershipInspector->undeclaredRuntimePaths();

        if ($undeclaredPaths === []) {
            $this->ok('No undeclared runtime paths were detected under the configured ownership roots.');
            return;
        }

        foreach ($undeclaredPaths as $entry) {
            $message = sprintf(
                'Undeclared runtime path detected: %s (inferred kind `%s`).',
                $entry['path'],
                $entry['kind']
            );

            if ($entry['is_symlink']) {
                $message .= ' It is a symlink; convert it into local code, a release-backed managed dependency, or an ignored path.';
            } else {
                $message .= ' Declare it as managed, local, ignored, or move it to runtime.allow_runtime_paths.';
            }

            if ($config->isStrictManifestMode()) {
                $this->error($message);
            } else {
                $this->warn($message);
            }

            $suggestion = $ownershipInspector->suggestedManifestEntry($entry);
            $this->note(sprintf(
                'Suggested manifest entry for %s: kind=%s, management=local, path=%s',
                $entry['path'],
                $suggestion['kind'],
                $suggestion['path']
            ));
        }
    }

    private function inspectRuntimeStaging(Config $config): void
    {
        $stagePath = $config->repoRoot . '/.wp-core-base/build/doctor-runtime';
        $runtimeInspector = new RuntimeInspector($config->runtime);
        $stager = new RuntimeStager($config, $runtimeInspector);

        try {
            $stagedPaths = $stager->stage('.wp-core-base/build/doctor-runtime');
            $this->ok(sprintf(
                'Runtime staging succeeded at %s (%s).',
                $stagePath,
                $stagedPaths === [] ? 'no staged paths' : implode(', ', $stagedPaths)
            ));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
        } finally {
            $runtimeInspector->clearPath($stagePath);
        }
    }

    private function inspectGitHubEnvironment(?Config $config, bool $requireGitHub): void
    {
        $repository = getenv('GITHUB_REPOSITORY');
        $token = getenv('GITHUB_TOKEN');
        $apiUrl = getenv('GITHUB_API_URL');

        $hasRepository = is_string($repository) && $repository !== '';
        $hasToken = is_string($token) && $token !== '';

        if ($hasRepository && $hasToken) {
            $this->ok(sprintf(
                'GitHub automation environment looks configured for %s%s.',
                $repository,
                is_string($apiUrl) && $apiUrl !== '' ? sprintf(' using API %s', $apiUrl) : ''
            ));
        } else {
            $message = 'GitHub automation environment is not fully configured. Set GITHUB_REPOSITORY and GITHUB_TOKEN to run sync or pr-blocker modes.';

            if ($requireGitHub) {
                $this->error($message);
            } else {
                $this->warn($message . ' This is fine for local verification and non-GitHub use.');
            }
        }

        if ($config === null) {
            return;
        }

        foreach ($config->managedDependencies() as $dependency) {
            if ($dependency['source'] !== 'github-release') {
                continue;
            }

            $tokenEnv = $dependency['source_config']['github_token_env'] ?? null;

            if (! is_string($tokenEnv) || $tokenEnv === '') {
                continue;
            }

            $hasDependencyToken = getenv($tokenEnv);

            if (is_string($hasDependencyToken) && $hasDependencyToken !== '') {
                $this->ok(sprintf('GitHub token env is configured for %s: %s', $dependency['component_key'], $tokenEnv));
                continue;
            }

            $message = sprintf('GitHub token env %s is missing for %s.', $tokenEnv, $dependency['component_key']);

            if ($requireGitHub) {
                $this->error($message);
            } else {
                $this->warn($message);
            }
        }
    }

    private function inspectGitHubWorkflows(bool $requireGitHub): void
    {
        if (! $requireGitHub) {
            return;
        }

        $workflowDir = $this->repoRoot . '/.github/workflows';

        if (! is_dir($workflowDir)) {
            $this->error('GitHub workflow directory is missing. Run scaffold-downstream or add workflows manually.');
            return;
        }

        $workflowFiles = array_merge(
            glob($workflowDir . '/*.yml') ?: [],
            glob($workflowDir . '/*.yaml') ?: [],
        );

        if ($workflowFiles === []) {
            $this->error('No GitHub workflow files found. Run scaffold-downstream or add workflows manually.');
            return;
        }

        $hasSyncWorkflow = false;
        $hasBlockerWorkflow = false;
        $hasValidationWorkflow = false;

        foreach ($workflowFiles as $workflowFile) {
            $contents = file_get_contents($workflowFile);

            if ($contents === false) {
                continue;
            }

            $hasSyncWorkflow = $hasSyncWorkflow || str_contains($contents, 'wporg-updater.php sync');
            $hasBlockerWorkflow = $hasBlockerWorkflow || str_contains($contents, 'wporg-updater.php pr-blocker');
            $hasValidationWorkflow = $hasValidationWorkflow || str_contains($contents, 'wporg-updater.php stage-runtime');
        }

        $this->okIf($hasSyncWorkflow, 'Found a GitHub workflow that runs sync mode.', 'No GitHub workflow found that runs `wporg-updater.php sync`.');
        $this->okIf($hasBlockerWorkflow, 'Found a GitHub workflow that runs blocker mode.', 'No GitHub workflow found that runs `wporg-updater.php pr-blocker`.');
        $this->okIf($hasValidationWorkflow, 'Found a GitHub workflow that stages runtime output.', 'No GitHub workflow found that runs `wporg-updater.php stage-runtime`.');
    }

    /**
     * @param array<string, mixed> $dependency
     * @return array{0:list<string>,1:list<string>}
     */
    private function sourceValidationRules(Config $config, array $dependency): array
    {
        $allowPaths = $this->translatedRuntimeAllowRulesForRoot($config, (string) $dependency['path']);

        if (! $config->isStagedCleanValidationMode()) {
            return [$allowPaths, [], []];
        }

        [$globalStripPaths, $globalStripFiles] = $this->translatedRuntimeStripRulesForRoot($config, (string) $dependency['path']);
        $dependencyStripPaths = $config->shouldAllowStripOnStage($dependency) ? $config->dependencyStripPaths($dependency) : [];
        $dependencyStripFiles = $config->shouldAllowStripOnStage($dependency) ? $config->dependencyStripFiles($dependency) : [];

        return [
            $allowPaths,
            array_values(array_unique(array_merge($dependencyStripPaths, $globalStripPaths))),
            array_values(array_unique(array_merge($dependencyStripFiles, $globalStripFiles))),
        ];
    }

    /**
     * @return list<string>
     */
    private function translatedRuntimeAllowRulesForRoot(Config $config, string $rootPath): array
    {
        $allowPaths = [];

        foreach ((array) $config->runtime['allow_runtime_paths'] as $allowPath) {
            if ($allowPath === $rootPath) {
                $allowPaths[] = '';
                continue;
            }

            if (str_starts_with($allowPath, $rootPath . '/')) {
                $allowPaths[] = substr($allowPath, strlen($rootPath) + 1);
            }
        }

        return array_values(array_unique($allowPaths));
    }

    /**
     * @return array{0:list<string>,1:list<string>}
     */
    private function translatedRuntimeStripRulesForRoot(Config $config, string $rootPath): array
    {
        $stripPaths = [];

        foreach ((array) $config->runtime['strip_paths'] as $stripPath) {
            if ($stripPath === $rootPath) {
                $stripPaths[] = '';
                continue;
            }

            if (str_starts_with($stripPath, $rootPath . '/')) {
                $stripPaths[] = substr($stripPath, strlen($rootPath) + 1);
            }
        }

        return [
            array_values(array_unique($stripPaths)),
            array_values(array_unique($config->stripFiles())),
        ];
    }

    private function isGitRepository(): bool
    {
        if (! $this->commandExists('git')) {
            return false;
        }

        $output = [];
        $status = 0;
        exec(sprintf('cd %s && git rev-parse --is-inside-work-tree 2>/dev/null', escapeshellarg($this->repoRoot)), $output, $status);

        return $status === 0 && trim(implode("\n", $output)) === 'true';
    }

    private function commandExists(string $command): bool
    {
        $output = [];
        $status = 0;
        exec(sprintf('command -v %s >/dev/null 2>&1', escapeshellarg($command)), $output, $status);
        return $status === 0;
    }

    private function isSafeRelativePath(string $path): bool
    {
        return $path !== '' && ! str_starts_with($path, '/') && ! str_contains($path, '../');
    }

    private function printHeading(string $heading): void
    {
        fwrite(STDOUT, $heading . "\n\n");
    }

    private function printSummary(): void
    {
        fwrite(STDOUT, "\n");

        if ($this->errors === 0) {
            fwrite(STDOUT, sprintf("Doctor completed with %d warning(s).\n", $this->warnings));
            return;
        }

        fwrite(STDOUT, sprintf("Doctor found %d error(s) and %d warning(s).\n", $this->errors, $this->warnings));
    }

    private function ok(string $message): void
    {
        fwrite(STDOUT, "[ok] " . $message . "\n");
    }

    private function warn(string $message): void
    {
        $this->warnings++;
        fwrite(STDOUT, "[warn] " . $message . "\n");
    }

    private function error(string $message): void
    {
        $this->errors++;
        fwrite(STDOUT, "[error] " . $message . "\n");
    }

    private function note(string $message): void
    {
        fwrite(STDOUT, "[note] " . $message . "\n");
    }

    private function okIf(bool $condition, string $okMessage, string $errorMessage): void
    {
        if ($condition) {
            $this->ok($okMessage);
            return;
        }

        $this->error($errorMessage);
    }

    private function warnIf(bool $condition, string $message): void
    {
        if ($condition) {
            $this->warn($message);
        }
    }
}
