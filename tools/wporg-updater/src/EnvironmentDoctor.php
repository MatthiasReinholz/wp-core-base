<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class EnvironmentDoctor
{
    private int $errors = 0;
    private int $warnings = 0;
    /** @var list<array{level:string,message:string}> */
    private array $messages = [];

    public function __construct(
        private readonly string $repoRoot,
        private readonly bool $emitOutput = true,
    ) {
    }

    public function run(bool $requireGitHub = false): int
    {
        $this->errors = 0;
        $this->warnings = 0;
        $this->messages = [];
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
        $frameworkPath = $this->repoRoot . '/.wp-core-base/framework.php';
        $this->okIf(is_file($manifestPath), 'Manifest file exists.', sprintf('Manifest file not found: %s', $manifestPath));
        $this->okIf(is_file($frameworkPath), 'Framework metadata file exists.', sprintf('Framework metadata file not found: %s', $frameworkPath));

        $config = null;
        $framework = null;

        try {
            $config = Config::load($this->repoRoot);
            $this->ok(sprintf('Manifest loaded successfully for profile `%s`.', $config->profile));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
        }

        try {
            $framework = FrameworkConfig::load($this->repoRoot);
            $this->ok(sprintf(
                'Framework metadata loaded successfully for `%s` at version %s.',
                $framework->repository,
                $framework->version
            ));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
        }

        if ($config !== null) {
            $this->inspectConfiguredStructure($config);
            $this->inspectMigrationWarnings($config);
            $this->inspectDependencies($config);
            $this->inspectRuntimeOwnership($config);
            $this->inspectAdminGovernance($config);
            $this->inspectRuntimeStaging($config);
        }

        if ($framework !== null) {
            $this->inspectFrameworkDistributionPath($framework, $requireGitHub);
        }

        $this->inspectGitHubEnvironment($config, $requireGitHub);
        $this->inspectGitHubWorkflows($framework, $requireGitHub);
        $this->printSummary();

        return $this->errors === 0 ? 0 : 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        return [
            'status' => $this->errors === 0 ? 'success' : 'failure',
            'error_count' => $this->errors,
            'warning_count' => $this->warnings,
            'messages' => $this->messages,
        ];
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
        $this->inspectRuntimeAllowPaths($config);

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

            [$globalAllowPaths, $stripPaths, $stripFiles, $sanitizeMatches] = $this->sourceValidationRules($config, $dependency, $runtimeInspector);

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

            if ($sanitizeMatches !== []) {
                $this->warn(sprintf(
                    'Managed dependency %s contains sanitizable entries that will be normalized during sync/stage: %s',
                    $dependency['component_key'],
                    implode(', ', $sanitizeMatches)
                ));
            }

            try {
                $checksum = $runtimeInspector->computeChecksum($absolutePath, [], $stripPaths, $stripFiles);

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

    private function inspectMigrationWarnings(Config $config): void
    {
        $pathsByDependency = [];

        foreach ($config->dependencies() as $dependency) {
            $path = (string) $dependency['path'];
            $pathsByDependency[$path][] = (string) $dependency['component_key'];
        }

        foreach ($pathsByDependency as $path => $componentKeys) {
            if (count($componentKeys) > 1) {
                $this->warn(sprintf(
                    'Multiple dependencies declare the same runtime path %s: %s. Consolidate these entries before stricter path validation is enforced.',
                    $path,
                    implode(', ', $componentKeys)
                ));
            }
        }

        $dependencyPaths = array_map(static fn (array $dependency): string => (string) $dependency['path'], $config->dependencies());
        sort($dependencyPaths);

        for ($index = 0, $count = count($dependencyPaths); $index < $count; $index++) {
            for ($cursor = $index + 1; $cursor < $count; $cursor++) {
                $left = $dependencyPaths[$index];
                $right = $dependencyPaths[$cursor];

                if (! $this->pathsOverlap($left, $right)) {
                    continue;
                }

                $this->warn(sprintf(
                    'Dependency runtime paths overlap (%s, %s). Collapse nested ownership before stricter path normalization is enforced.',
                    $left,
                    $right
                ));
            }
        }

        $this->inspectRuntimeAllowPaths($config);
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
        $stager = new RuntimeStager($config, $runtimeInspector, new AdminGovernanceExporter($runtimeInspector));

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

        $premiumRegistry = null;
        $premiumSources = [];

        try {
            $premiumRegistry = PremiumProviderRegistry::load($this->repoRoot);

            if ($premiumRegistry->exists()) {
                $premiumSources = $premiumRegistry->instantiate(
                    new HttpClient(userAgent: 'wp-core-base/doctor'),
                    new PremiumCredentialsStore(),
                    $config->managedDependencies(),
                );
                $this->ok(sprintf(
                    'Premium provider registry loaded from %s with %d provider(s).',
                    $premiumRegistry->path(),
                    count($premiumSources)
                ));

                foreach ($premiumSources as $provider => $premiumSource) {
                    if (! $premiumSource instanceof AbstractPremiumManagedSource) {
                        $this->warn(sprintf(
                            'Premium provider `%s` does not extend AbstractPremiumManagedSource, so doctor cannot verify its host allowlists.',
                            $provider
                        ));
                        continue;
                    }

                    foreach ($premiumSource->hostPolicyWarnings() as $warning) {
                        $this->warn($warning);
                    }
                }
            }
        } catch (RuntimeException $exception) {
            $premiumRegistry = null;
            $premiumSources = [];

            if ($requireGitHub) {
                $this->error($exception->getMessage());
            } else {
                $this->warn($exception->getMessage());
            }
        }

        foreach ($config->managedDependencies() as $dependency) {
            if ($dependency['source'] !== 'github-release') {
                if (PremiumSourceResolver::isPremiumSource((string) $dependency['source'])) {
                    $provider = PremiumSourceResolver::providerForDependency($dependency);

                    try {
                        if ($premiumRegistry === null || ! $premiumRegistry->hasProvider((string) $provider)) {
                            throw new RuntimeException(sprintf(
                                'Premium provider `%s` is not registered for %s. Add it to .wp-core-base/premium-providers.php.',
                                (string) $provider,
                                $dependency['component_key']
                            ));
                        }

                        if (! isset($premiumSources[(string) $provider])) {
                            throw new RuntimeException(sprintf(
                                'Premium provider `%s` could not be instantiated for %s. Fix .wp-core-base/premium-providers.php and rerun doctor.',
                                (string) $provider,
                                $dependency['component_key']
                            ));
                        }

                        $premiumSources[(string) $provider]->validateCredentialConfiguration($dependency);

                        $this->ok(sprintf(
                            'Premium credentials are configured for %s via %s.',
                            $dependency['component_key'],
                            PremiumCredentialsStore::envName()
                        ));
                    } catch (RuntimeException $exception) {
                        if ($requireGitHub) {
                            $this->error($exception->getMessage());
                        } else {
                            $this->warn($exception->getMessage());
                        }
                    }
                }

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

    private function inspectAdminGovernance(Config $config): void
    {
        $loaderPath = $this->repoRoot . '/' . FrameworkRuntimeFiles::governanceLoaderPath($config);
        $dataPath = $this->repoRoot . '/' . FrameworkRuntimeFiles::governanceDataPath($config);
        $exporter = new AdminGovernanceExporter(new RuntimeInspector($config->runtime));

        $this->okIf(
            is_file($loaderPath),
            sprintf('Admin governance loader exists: %s', FrameworkRuntimeFiles::governanceLoaderPath($config)),
            sprintf('Admin governance loader is missing: %s', FrameworkRuntimeFiles::governanceLoaderPath($config))
        );

        $this->okIf(
            is_file($dataPath),
            sprintf('Admin governance data exists: %s', FrameworkRuntimeFiles::governanceDataPath($config)),
            sprintf('Admin governance data is missing: %s. Run refresh-admin-governance.', FrameworkRuntimeFiles::governanceDataPath($config))
        );

        if (is_file($dataPath)) {
            if ($exporter->isStaleOrMissing($config)) {
                $this->warn(sprintf(
                    'Admin governance data is stale relative to the manifest: %s. Run refresh-admin-governance.',
                    FrameworkRuntimeFiles::governanceDataPath($config)
                ));
            } else {
                $this->ok('Admin governance data matches the current manifest.');
            }
        }
    }

    private function inspectGitHubWorkflows(?FrameworkConfig $framework, bool $requireGitHub): void
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
        $hasFrameworkSyncWorkflow = $framework === null || $framework->distributionPath() === '.';

        foreach ($workflowFiles as $workflowFile) {
            $contents = file_get_contents($workflowFile);

            if ($contents === false) {
                continue;
            }

            $hasSyncWorkflow = $hasSyncWorkflow || str_contains($contents, 'wporg-updater.php sync');
            $hasBlockerWorkflow = $hasBlockerWorkflow || str_contains($contents, 'wporg-updater.php pr-blocker');
            $hasValidationWorkflow = $hasValidationWorkflow || str_contains($contents, 'wporg-updater.php stage-runtime');
            $hasFrameworkSyncWorkflow = $hasFrameworkSyncWorkflow || str_contains($contents, 'wporg-updater.php framework-sync');
        }

        $this->okIf($hasSyncWorkflow, 'Found a GitHub workflow that runs sync mode.', 'No GitHub workflow found that runs `wporg-updater.php sync`.');
        $this->okIf($hasBlockerWorkflow, 'Found a GitHub workflow that runs blocker mode.', 'No GitHub workflow found that runs `wporg-updater.php pr-blocker`.');
        $this->okIf($hasValidationWorkflow, 'Found a GitHub workflow that stages runtime output.', 'No GitHub workflow found that runs `wporg-updater.php stage-runtime`.');
        $this->okIf($hasFrameworkSyncWorkflow, 'Found a GitHub workflow that runs framework-sync mode.', 'No GitHub workflow found that runs `wporg-updater.php framework-sync`.');
        $this->inspectWorkflowPermissions($workflowDir);
        $this->inspectWorkflowSemantics($workflowDir);
    }

    private function inspectFrameworkDistributionPath(FrameworkConfig $framework, bool $requireGitHub): void
    {
        $distributionPath = $framework->distributionPath();

        if ($distributionPath === '.') {
            $this->ok('Framework distribution path is the repository root.');
            return;
        }

        $absolutePath = $this->repoRoot . '/' . $distributionPath;
        $this->okIf(
            file_exists($absolutePath) || is_link($absolutePath),
            sprintf('Framework distribution path exists: %s', $distributionPath),
            sprintf('Framework distribution path does not exist: %s', $distributionPath)
        );

        if (! $this->commandExists('git') || ! $this->isGitRepository()) {
            return;
        }

        $pathsToCheck = [$distributionPath];
        $expectedToolPath = $distributionPath . '/tools/wporg-updater/bin/wporg-updater.php';

        if (is_file($this->repoRoot . '/' . $expectedToolPath)) {
            $pathsToCheck[] = $expectedToolPath;
        }

        foreach ($pathsToCheck as $path) {
            if (! $this->isIgnoredByGit($path)) {
                continue;
            }

            $message = sprintf(
                'Framework distribution path is ignored by Git: %s. Framework self-update depends on committing the vendored snapshot and reviewing its changes in pull requests.',
                $path
            );

            if (str_starts_with($distributionPath, 'vendor/')) {
                $message .= sprintf(
                    ' If your repo ignores /vendor/, prefer a narrow exception such as: /vendor/*, !/%s, !/%s/**',
                    $distributionPath,
                    $distributionPath
                );
            }

            if ($requireGitHub) {
                $this->error($message);
            } else {
                $this->warn($message);
            }

            return;
        }

        $this->ok(sprintf('Framework distribution path is not ignored by Git: %s', $distributionPath));
    }

    /**
     * @param array<string, mixed> $dependency
     * @return array{0:list<string>,1:list<string>,2:list<string>,3:list<string>}
     */
    private function sourceValidationRules(Config $config, array $dependency, RuntimeInspector $runtimeInspector): array
    {
        $allowPaths = $this->translatedRuntimeAllowRulesForRoot($config, (string) $dependency['path']);

        if ($dependency['management'] === 'managed') {
            [$globalSanitizePaths, $globalSanitizeFiles] = $this->translatedManagedSanitizeRulesForRoot($config, (string) $dependency['path']);
            $sanitizePaths = array_values(array_unique(array_merge($globalSanitizePaths, $config->dependencySanitizePaths($dependency))));
            $sanitizeFiles = array_values(array_unique(array_merge($globalSanitizeFiles, $config->dependencySanitizeFiles($dependency))));

            return [
                $allowPaths,
                $sanitizePaths,
                $sanitizeFiles,
                $runtimeInspector->matchingStrippedEntries(
                    $config->repoRoot . '/' . (string) $dependency['path'],
                    $sanitizePaths,
                    $sanitizeFiles
                ),
            ];
        }

        if (! $config->isStagedCleanValidationMode()) {
            return [$allowPaths, [], [], []];
        }

        [$globalStripPaths, $globalStripFiles] = $this->translatedRuntimeStripRulesForRoot($config, (string) $dependency['path']);
        $dependencyStripPaths = $config->shouldAllowStripOnStage($dependency) ? $config->dependencyStripPaths($dependency) : [];
        $dependencyStripFiles = $config->shouldAllowStripOnStage($dependency) ? $config->dependencyStripFiles($dependency) : [];

        return [
            $allowPaths,
            array_values(array_unique(array_merge($dependencyStripPaths, $globalStripPaths))),
            array_values(array_unique(array_merge($dependencyStripFiles, $globalStripFiles))),
            [],
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

    /**
     * @return array{0:list<string>,1:list<string>}
     */
    private function translatedManagedSanitizeRulesForRoot(Config $config, string $rootPath): array
    {
        $sanitizePaths = [];

        foreach ((array) $config->runtime['managed_sanitize_paths'] as $sanitizePath) {
            if ($sanitizePath === $rootPath) {
                $sanitizePaths[] = '';
                continue;
            }

            if (str_starts_with($sanitizePath, $rootPath . '/')) {
                $sanitizePaths[] = substr($sanitizePath, strlen($rootPath) + 1);
            }
        }

        return [
            array_values(array_unique($sanitizePaths)),
            array_values(array_unique($config->managedSanitizeFiles())),
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

    private function isIgnoredByGit(string $path): bool
    {
        $status = 0;
        exec(sprintf(
            'cd %s && git check-ignore --quiet --no-index -- %s 2>/dev/null',
            escapeshellarg($this->repoRoot),
            escapeshellarg($path)
        ), $_, $status);

        return $status === 0;
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

    private function inspectRuntimeAllowPaths(Config $config): void
    {
        $broadPaths = array_values(array_unique(array_merge(
            [$config->paths['content_root'], $config->paths['plugins_root'], $config->paths['themes_root'], $config->paths['mu_plugins_root']],
            $config->ownershipRoots()
        )));

        foreach ((array) $config->runtime['allow_runtime_paths'] as $allowPath) {
            if (in_array((string) $allowPath, $broadPaths, true)) {
                $this->warn(sprintf(
                    'runtime.allow_runtime_paths entry %s broadly suppresses undeclared-path detection. Prefer declaring specific child paths instead.',
                    (string) $allowPath
                ));
            }
        }
    }

    private function inspectWorkflowPermissions(string $workflowDir): void
    {
        $expectedPermissions = [
            'wporg-updates.yml' => [
                'contents' => 'write',
                'pull-requests' => 'write',
                'issues' => 'write',
            ],
            'wporg-updates-reconcile.yml' => [
                'contents' => 'write',
                'pull-requests' => 'write',
                'issues' => 'write',
            ],
            'wporg-update-pr-blocker.yml' => [
                'contents' => 'read',
                'pull-requests' => 'read',
                'issues' => 'read',
            ],
            'wporg-validate-runtime.yml' => [
                'contents' => 'read',
            ],
            'wp-core-base-self-update.yml' => [
                'contents' => 'write',
                'pull-requests' => 'write',
                'issues' => 'write',
            ],
        ];

        foreach ($expectedPermissions as $fileName => $expected) {
            $path = $workflowDir . '/' . $fileName;

            if (! is_file($path)) {
                continue;
            }

            $contents = file_get_contents($path);

            if ($contents === false) {
                $this->warn(sprintf('Unable to read workflow permissions for %s.', $fileName));
                continue;
            }

            $blocks = $this->parsePermissionsBlocks($contents);
            $topLevel = $blocks['top-level'] ?? null;

            if ($topLevel === null) {
                $this->warn(sprintf('Workflow %s does not have a parseable top-level permissions block.', $fileName));
                continue;
            }

            if ($topLevel !== $expected) {
                $this->warn(sprintf(
                    'Workflow %s permissions differ from the scaffolded baseline. Expected %s but found %s.',
                    $fileName,
                    $this->formatPermissions($expected),
                    $this->formatPermissions($topLevel)
                ));
                continue;
            }

            foreach ($blocks as $blockName => $permissions) {
                if ($blockName === 'top-level') {
                    continue;
                }

                if (! $this->permissionsWithinBaseline($permissions, $expected)) {
                    $this->warn(sprintf(
                        'Workflow %s permission override %s exceeds the scaffolded baseline. Expected at most %s but found %s.',
                        $fileName,
                        $blockName,
                        $this->formatPermissions($expected),
                        $this->formatPermissions($permissions)
                    ));
                    continue 2;
                }
            }

            $this->ok(sprintf('Workflow permissions match the scaffolded baseline for %s.', $fileName));
        }
    }

    private function inspectWorkflowSemantics(string $workflowDir): void
    {
        $updatesWorkflow = $this->readWorkflow($workflowDir . '/wporg-updates.yml');
        $reconcileWorkflow = $this->readWorkflow($workflowDir . '/wporg-updates-reconcile.yml');
        $blockerWorkflow = $this->readWorkflow($workflowDir . '/wporg-update-pr-blocker.yml');

        if ($updatesWorkflow !== null) {
            $this->okIf(
                str_contains($updatesWorkflow, "schedule:") && str_contains($updatesWorkflow, "workflow_dispatch:"),
                'Updates workflow exposes schedule and manual dispatch triggers.',
                'Updates workflow should define both schedule and workflow_dispatch triggers.'
            );
            $this->okIf(
                str_contains($updatesWorkflow, "concurrency:") && str_contains($updatesWorkflow, "group: wp-core-base-dependency-sync"),
                'Updates workflow defines the shared dependency-sync concurrency group.',
                'Updates workflow should define concurrency.group=wp-core-base-dependency-sync.'
            );
            $this->okIf(
                ! str_contains($updatesWorkflow, 'pull_request_target:'),
                'Updates workflow avoids pull_request_target for scheduled/manual sync.',
                'Updates workflow should not use pull_request_target.'
            );
        }

        if ($reconcileWorkflow !== null) {
            $this->okIf(
                str_contains($reconcileWorkflow, "pull_request_target:") && str_contains($reconcileWorkflow, "- closed"),
                'Reconcile workflow is wired to closed pull_request_target events.',
                'Reconcile workflow should trigger on pull_request_target closed events.'
            );
            $this->okIf(
                str_contains($reconcileWorkflow, "workflow_dispatch:") || str_contains($reconcileWorkflow, "schedule:"),
                'Reconcile workflow includes a manual/scheduled recovery trigger.',
                'Reconcile workflow should include workflow_dispatch or schedule for recovery retries.'
            );
            $this->okIf(
                str_contains($reconcileWorkflow, "github.event.pull_request.merged == true"),
                'Reconcile workflow gates closed-PR events to merged PRs.',
                'Reconcile workflow should gate pull_request_target closed events to merged PRs.'
            );
            $this->okIf(
                str_contains($reconcileWorkflow, "automation:dependency-update")
                && str_contains($reconcileWorkflow, "automation:framework-update"),
                'Reconcile workflow scopes execution to automation PR labels.',
                'Reconcile workflow should scope pull_request_target closed execution to automation PR labels.'
            );
            $this->okIf(
                str_contains($reconcileWorkflow, "concurrency:") && str_contains($reconcileWorkflow, "group: wp-core-base-dependency-sync"),
                'Reconcile workflow defines the shared dependency-sync concurrency group.',
                'Reconcile workflow should define concurrency.group=wp-core-base-dependency-sync.'
            );
            $this->okIf(
                ! str_contains($reconcileWorkflow, "\npull_request:\n"),
                'Reconcile workflow avoids pull_request (uses pull_request_target for safe metadata reads).',
                'Reconcile workflow should avoid pull_request and use pull_request_target for PR metadata reconciliation.'
            );
        }

        if ($blockerWorkflow !== null) {
            $this->okIf(
                str_contains($blockerWorkflow, "pull_request_target:") && str_contains($blockerWorkflow, "- opened"),
                'PR blocker workflow listens to pull_request_target update events.',
                'PR blocker workflow should trigger on pull_request_target open/sync events.'
            );
            $this->okIf(
                str_contains($blockerWorkflow, "workflow_dispatch:") || str_contains($blockerWorkflow, "schedule:"),
                'PR blocker workflow includes a manual/scheduled recovery path.',
                'PR blocker workflow should include workflow_dispatch or schedule for degraded-state recovery.'
            );
            $this->okIf(
                str_contains($blockerWorkflow, "pr-blocker-reconcile") || str_contains($blockerWorkflow, "pr-blocker --pr-number"),
                'PR blocker workflow includes an explicit blocker recovery execution path.',
                'PR blocker workflow should include a reconciliation/manual retry execution path (pr-blocker-reconcile or pr-blocker --pr-number).'
            );
            $this->okIf(
                ! str_contains($blockerWorkflow, "\npull_request:\n"),
                'PR blocker workflow avoids pull_request (uses pull_request_target for safe metadata reads).',
                'PR blocker workflow should avoid pull_request and use pull_request_target for PR metadata checks.'
            );
        }
    }

    private function readWorkflow(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            $this->warn(sprintf('Unable to read workflow semantic contract for %s.', basename($path)));
            return null;
        }

        return $contents;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function parsePermissionsBlocks(string $contents): array
    {
        $lines = preg_split("/\r\n|\n|\r/", $contents) ?: [];
        $blocks = [];

        foreach ($lines as $index => $line) {
            if (! preg_match('/^(\s*)permissions:\s*(.*)$/', $line, $permissionMatches)) {
                continue;
            }

            $indent = strlen($permissionMatches[1]);
            $permissions = $this->parseInlinePermissions(trim($permissionMatches[2]));
            $blockName = $indent === 0 ? 'top-level' : sprintf('line-%d', $index + 1);

            if ($indent === 4) {
                for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
                    if (preg_match('/^\s{2}([A-Za-z0-9_-]+):\s*$/', $lines[$cursor], $jobMatches)) {
                        $blockName = 'job:' . $jobMatches[1];
                        break;
                    }

                    if (trim($lines[$cursor]) !== '') {
                        break;
                    }
                }
            }

            if ($permissions === []) {
                for ($cursor = $index + 1, $count = count($lines); $cursor < $count; $cursor++) {
                    $candidate = $lines[$cursor];

                    if (trim($candidate) === '') {
                        continue;
                    }

                    if (! preg_match('/^(\s+)([A-Za-z-]+):\s*([A-Za-z-]+)\s*$/', $candidate, $matches)) {
                        break;
                    }

                    $candidateIndent = strlen($matches[1]);

                    if ($candidateIndent <= $indent) {
                        break;
                    }

                    $permissions[$matches[2]] = $matches[3];
                }
            }

            if ($permissions !== []) {
                $blocks[$blockName] = $permissions;
            }
        }

        return $blocks;
    }

    /**
     * @param array<string, string> $permissions
     */
    private function formatPermissions(array $permissions): string
    {
        $pairs = [];

        foreach ($permissions as $scope => $level) {
            $pairs[] = sprintf('%s=%s', $scope, $level);
        }

        return implode(', ', $pairs);
    }

    /**
     * @param array<string, string> $actual
     * @param array<string, string> $expected
     */
    private function permissionsWithinBaseline(array $actual, array $expected): bool
    {
        $levels = ['none' => 0, 'read' => 1, 'write' => 2];

        if (($actual['*'] ?? null) === 'write-all') {
            return false;
        }

        if (($actual['*'] ?? null) === 'read-all') {
            foreach ($expected as $level) {
                if (($levels[$level] ?? 0) > $levels['read']) {
                    return false;
                }
            }

            return true;
        }

        if (isset($actual['*'])) {
            return false;
        }

        foreach ($actual as $scope => $level) {
            if (! isset($expected[$scope], $levels[$level], $levels[$expected[$scope]])) {
                return false;
            }

            if ($levels[$level] > $levels[$expected[$scope]]) {
                return false;
            }
        }

        return true;
    }

    private function pathsOverlap(string $left, string $right): bool
    {
        return $this->pathStartsWith($left, $right) || $this->pathStartsWith($right, $left);
    }

    /**
     * @return array<string, string>
     */
    private function parseInlinePermissions(string $value): array
    {
        if ($value === '') {
            return [];
        }

        if ($value === 'read-all' || $value === 'write-all') {
            return ['*' => $value];
        }

        if (preg_match('/^\{(.*)\}$/', $value, $matches) !== 1) {
            return ['*' => $value];
        }

        $pairs = [];

        foreach (explode(',', $matches[1]) as $pair) {
            [$scope, $level] = array_pad(explode(':', $pair, 2), 2, null);
            $scope = is_string($scope) ? trim($scope) : '';
            $level = is_string($level) ? trim($level) : '';

            if ($scope === '' || $level === '') {
                return ['*' => $value];
            }

            $pairs[$scope] = $level;
        }

        return $pairs;
    }

    private function pathStartsWith(string $path, string $prefix): bool
    {
        return $path === $prefix || str_starts_with($path, $prefix . '/');
    }

    private function printHeading(string $heading): void
    {
        if (! $this->emitOutput) {
            return;
        }

        fwrite(STDOUT, $heading . "\n\n");
    }

    private function printSummary(): void
    {
        if (! $this->emitOutput) {
            return;
        }

        fwrite(STDOUT, "\n");

        if ($this->errors === 0) {
            fwrite(STDOUT, sprintf("Doctor completed with %d warning(s).\n", $this->warnings));
            return;
        }

        fwrite(STDOUT, sprintf("Doctor found %d error(s) and %d warning(s).\n", $this->errors, $this->warnings));
    }

    private function ok(string $message): void
    {
        $redacted = OutputRedactor::redact($message);
        $this->messages[] = ['level' => 'ok', 'message' => $redacted];

        if (! $this->emitOutput) {
            return;
        }

        fwrite(STDOUT, "[ok] " . $redacted . "\n");
    }

    private function warn(string $message): void
    {
        $this->warnings++;
        $redacted = OutputRedactor::redact($message);
        $this->messages[] = ['level' => 'warn', 'message' => $redacted];

        if (! $this->emitOutput) {
            return;
        }

        fwrite(STDOUT, "[warn] " . $redacted . "\n");
    }

    private function error(string $message): void
    {
        $this->errors++;
        $redacted = OutputRedactor::redact($message);
        $this->messages[] = ['level' => 'error', 'message' => $redacted];

        if (! $this->emitOutput) {
            return;
        }

        fwrite(STDOUT, "[error] " . $redacted . "\n");
    }

    private function note(string $message): void
    {
        $redacted = OutputRedactor::redact($message);
        $this->messages[] = ['level' => 'note', 'message' => $redacted];

        if (! $this->emitOutput) {
            return;
        }

        fwrite(STDOUT, "[note] " . $redacted . "\n");
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
