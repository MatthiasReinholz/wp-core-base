<?php

declare(strict_types=1);

use WpOrgPluginUpdater\Config;
use WpOrgPluginUpdater\CommandHelp;
use WpOrgPluginUpdater\CoreScanner;
use WpOrgPluginUpdater\CoreUpdater;
use WpOrgPluginUpdater\AdminGovernanceExporter;
use WpOrgPluginUpdater\DependencyScanner;
use WpOrgPluginUpdater\DependencyAuthoringService;
use WpOrgPluginUpdater\DependencyMetadataResolver;
use WpOrgPluginUpdater\DownstreamScaffolder;
use WpOrgPluginUpdater\EnvironmentDoctor;
use WpOrgPluginUpdater\FrameworkConfig;
use WpOrgPluginUpdater\FrameworkInstaller;
use WpOrgPluginUpdater\FrameworkReleaseClient;
use WpOrgPluginUpdater\FrameworkReleasePreparer;
use WpOrgPluginUpdater\FrameworkReleaseSignature;
use WpOrgPluginUpdater\FrameworkReleaseVerifier;
use WpOrgPluginUpdater\FrameworkSyncer;
use WpOrgPluginUpdater\GitCommandRunner;
use WpOrgPluginUpdater\GitHubClient;
use WpOrgPluginUpdater\GitHubReleaseClient;
use WpOrgPluginUpdater\HttpClient;
use WpOrgPluginUpdater\InteractivePrompter;
use WpOrgPluginUpdater\ManifestWriter;
use WpOrgPluginUpdater\ManifestSuggester;
use WpOrgPluginUpdater\ManagedSourceRegistry;
use WpOrgPluginUpdater\MutationLock;
use WpOrgPluginUpdater\OutputRedactor;
use WpOrgPluginUpdater\PremiumProviderRegistry;
use WpOrgPluginUpdater\PremiumProviderScaffolder;
use WpOrgPluginUpdater\PremiumSourceResolver;
use WpOrgPluginUpdater\PrBodyRenderer;
use WpOrgPluginUpdater\PremiumCredentialsStore;
use WpOrgPluginUpdater\PullRequestBlocker;
use WpOrgPluginUpdater\ReleaseClassifier;
use WpOrgPluginUpdater\ReleaseSignatureKeyStore;
use WpOrgPluginUpdater\RuntimeInspector;
use WpOrgPluginUpdater\RuntimeStager;
use WpOrgPluginUpdater\SyncReport;
use WpOrgPluginUpdater\WordPressOrgManagedSource;
use WpOrgPluginUpdater\SupportForumClient;
use WpOrgPluginUpdater\Updater;
use WpOrgPluginUpdater\WordPressCoreClient;
use WpOrgPluginUpdater\WordPressOrgClient;
use WpOrgPluginUpdater\GitHubReleaseManagedSource;

require dirname(__DIR__) . '/src/Autoload.php';

$mode = $argv[1] ?? 'sync';
$arguments = array_slice($argv, 2);
$frameworkRoot = dirname(__DIR__, 3);
$options = [];

foreach ($arguments as $argument) {
    if (! str_starts_with($argument, '--')) {
        continue;
    }

    $option = substr($argument, 2);

    if (str_contains($option, '=')) {
        [$name, $value] = explode('=', $option, 2);
        $options[$name] = $value;
        continue;
    }

    $options[$option] = true;
}

$repoRootEnv = getenv('WPORG_REPO_ROOT');
$repoRoot = $options['repo-root'] ?? $repoRootEnv;
$repoRoot = is_string($repoRoot) && $repoRoot !== '' ? $repoRoot : $frameworkRoot;
$toolPath = $options['tool-path'] ?? (
    realpath($repoRoot) === realpath($frameworkRoot)
        ? '.'
        : 'vendor/wp-core-base'
);
$force = isset($options['force']);
$commandPrefix = $toolPath === '.'
    ? 'bin/wp-core-base'
    : sprintf('%s/bin/wp-core-base', trim((string) $toolPath, '/'));
$phpCommandPrefix = $toolPath === '.'
    ? 'php tools/wporg-updater/bin/wporg-updater.php'
    : sprintf('php %s/tools/wporg-updater/bin/wporg-updater.php', trim((string) $toolPath, '/'));
$mutationLock = new MutationLock();

$maybePromptForMissing = static function (array &$options, InteractivePrompter $prompter, array $premiumProviders): void {
    $source = isset($options['source']) && is_string($options['source']) ? $options['source'] : null;
    $kind = isset($options['kind']) && is_string($options['kind']) ? $options['kind'] : null;

    if ($source === null || $source === '') {
        $options['source'] = $prompter->choose(
            'Select dependency source',
            ['wordpress.org', 'github-release', 'premium', 'local'],
            'local'
        );
        $source = $options['source'];
    }

    if ($kind === null || $kind === '') {
        $options['kind'] = $prompter->choose(
            'Select dependency kind',
            ['plugin', 'theme', 'mu-plugin-package', 'mu-plugin-file', 'runtime-file', 'runtime-directory'],
            'plugin'
        );
        $kind = $options['kind'];
    }

    if ((! isset($options['slug']) || ! is_string($options['slug']) || trim($options['slug']) === '') && ! isset($options['path'])) {
        if ($source === 'local') {
            $options['path'] = $prompter->ask('Runtime path');
        } else {
            $options['slug'] = $prompter->ask('Slug');
        }
    }

    if ($source === 'github-release') {
        if (! isset($options['github-repository']) || ! is_string($options['github-repository']) || trim($options['github-repository']) === '') {
            $options['github-repository'] = $prompter->ask('GitHub repository (owner/repo)');
        }

        if (! isset($options['private']) && $prompter->confirm('Is this GitHub repository private?', false)) {
            $options['private'] = true;
        }

        if (($options['private'] ?? false) === true && (! isset($options['github-token-env']) || ! is_string($options['github-token-env']) || trim($options['github-token-env']) === '')) {
            $tokenEnv = $prompter->ask('GitHub token env var name (leave blank for default)', '');

            if ($tokenEnv !== '') {
                $options['github-token-env'] = $tokenEnv;
            }
        }
    }

    if (PremiumSourceResolver::isPremiumSource($source)) {
        if ($source === 'premium' && (! isset($options['provider']) || ! is_string($options['provider']) || trim($options['provider']) === '')) {
            if ($premiumProviders === []) {
                throw new RuntimeException('No premium providers are registered. Scaffold one with scaffold-premium-provider or add it to .wp-core-base/premium-providers.php.');
            }

            $options['provider'] = $prompter->choose(
                'Select premium provider',
                $premiumProviders,
                $premiumProviders[0]
            );
        }

        if (! isset($options['credential-key']) || ! is_string($options['credential-key']) || trim($options['credential-key']) === '') {
            $credentialKey = $prompter->ask('Premium credential lookup key (leave blank for component key)', '');

            if ($credentialKey !== '') {
                $options['credential-key'] = $credentialKey;
            }
        }
    }

    if ($source === 'local' && (! isset($options['path']) || ! is_string($options['path']) || trim($options['path']) === '')) {
        $options['path'] = $prompter->ask('Runtime path');
    }
};

try {
    if (in_array($mode, ['help', '--help', '-h'], true)) {
        $topic = $arguments[0] ?? null;
        $topic = is_string($topic) && ! str_starts_with($topic, '--') ? $topic : null;
        fwrite(STDOUT, CommandHelp::render($topic, $commandPrefix, $phpCommandPrefix));
        exit(0);
    }

    if ($mode === 'doctor') {
        $doctor = new EnvironmentDoctor($repoRoot);
        exit($doctor->run(isset($options['github'])));
    }

    if ($mode === 'render-sync-report') {
        $reportPath = isset($options['report-json']) && is_string($options['report-json']) ? $options['report-json'] : null;

        if ($reportPath === null || trim($reportPath) === '') {
            throw new RuntimeException('render-sync-report requires --report-json=/path/to/report.json.');
        }

        $summary = SyncReport::exists($reportPath)
            ? SyncReport::renderSummary(SyncReport::read($reportPath))
            : "## wp-core-base Sync Report\n\nNo sync report was written before the workflow ended.\n";
        $summaryPath = isset($options['summary-path']) && is_string($options['summary-path']) ? $options['summary-path'] : null;

        if ($summaryPath !== null && trim($summaryPath) !== '') {
            if (file_put_contents($summaryPath, $summary) === false) {
                throw new RuntimeException(sprintf('Unable to write sync summary: %s', $summaryPath));
            }
        } else {
            fwrite(STDOUT, $summary);
        }

        exit(0);
    }

    if ($mode === 'sync-report-issue') {
        $reportPath = isset($options['report-json']) && is_string($options['report-json']) ? $options['report-json'] : null;

        if ($reportPath === null || trim($reportPath) === '') {
            throw new RuntimeException('sync-report-issue requires --report-json=/path/to/report.json.');
        }

        if (! SyncReport::exists($reportPath)) {
            fwrite(STDOUT, "No sync report was written; skipping source-failure issue sync.\n");
            exit(0);
        }

        $config = Config::load($repoRoot);
        $gitHubClient = GitHubClient::fromEnvironment(
            new HttpClient(userAgent: 'wp-core-base/sync-report-issue'),
            $config->githubApiBase(),
            $config->dryRun()
        );
        $runUrl = null;
        $serverUrl = getenv('GITHUB_SERVER_URL');
        $repository = getenv('GITHUB_REPOSITORY');
        $runId = getenv('GITHUB_RUN_ID');

        if (is_string($serverUrl) && $serverUrl !== '' && is_string($repository) && $repository !== '' && is_string($runId) && $runId !== '') {
            $runUrl = sprintf('%s/%s/actions/runs/%s', rtrim($serverUrl, '/'), $repository, $runId);
        }

        SyncReport::syncIssue($gitHubClient, SyncReport::read($reportPath), $runUrl);
        exit(0);
    }

    if ($mode === 'release-verify') {
        $tag = isset($options['tag']) && is_string($options['tag']) ? $options['tag'] : null;
        $artifact = isset($options['artifact']) && is_string($options['artifact']) ? $options['artifact'] : null;
        $checksumFile = isset($options['checksum-file']) && is_string($options['checksum-file']) ? $options['checksum-file'] : null;
        $signatureFile = isset($options['signature-file']) && is_string($options['signature-file']) ? $options['signature-file'] : null;
        $publicKeyFile = isset($options['public-key-file']) && is_string($options['public-key-file']) ? $options['public-key-file'] : null;
        $resolvedTag = (new FrameworkReleaseVerifier($repoRoot))->verify($tag, $artifact, $checksumFile, $signatureFile, $publicKeyFile);
        fwrite(STDOUT, sprintf("Release verification passed for %s\n", $resolvedTag));
        exit(0);
    }

    if ($mode === 'release-sign') {
        $artifact = isset($options['artifact']) && is_string($options['artifact']) ? $options['artifact'] : null;
        $checksumFile = isset($options['checksum-file']) && is_string($options['checksum-file']) ? $options['checksum-file'] : null;
        $signatureFile = isset($options['signature-file']) && is_string($options['signature-file']) ? $options['signature-file'] : null;
        $privateKeyEnv = isset($options['private-key-env']) && is_string($options['private-key-env']) ? $options['private-key-env'] : null;
        $passphraseEnv = isset($options['passphrase-env']) && is_string($options['passphrase-env']) ? $options['passphrase-env'] : null;

        if ($artifact === null || trim($artifact) === '' || ! is_file($artifact)) {
            throw new RuntimeException('release-sign requires --artifact=/path/to/release.zip.');
        }

        if ($checksumFile === null || trim($checksumFile) === '' || ! is_file($checksumFile)) {
            throw new RuntimeException('release-sign requires --checksum-file=/path/to/release.zip.sha256.');
        }

        if ($signatureFile === null || trim($signatureFile) === '') {
            throw new RuntimeException('release-sign requires --signature-file=/path/to/release.zip.sha256.sig.');
        }

        if ($privateKeyEnv === null || trim($privateKeyEnv) === '') {
            throw new RuntimeException('release-sign requires --private-key-env=ENV_VAR.');
        }

        $privateKeyPem = ReleaseSignatureKeyStore::privateKeyFromEnvironment($privateKeyEnv);
        $passphrase = ReleaseSignatureKeyStore::optionalEnvironmentValue($passphraseEnv);
        $document = FrameworkReleaseSignature::signChecksumFile($checksumFile, $signatureFile, $privateKeyPem, $passphrase);

        fwrite(STDOUT, sprintf("Release signature written for %s\n", basename($artifact)));
        fwrite(STDOUT, sprintf("Signed checksum: %s\n", $document['signed_file']));
        fwrite(STDOUT, sprintf("Key ID: %s\n", $document['key_id']));
        exit(0);
    }

    if ($mode === 'prepare-framework-release') {
        $releaseType = $options['release-type'] ?? null;

        if (! is_string($releaseType) || $releaseType === '') {
            throw new RuntimeException('prepare-framework-release requires --release-type=patch|minor|major|custom.');
        }

        $customVersion = isset($options['version']) && is_string($options['version']) ? $options['version'] : null;
        $result = (new FrameworkReleasePreparer($repoRoot))->prepare(
            $releaseType,
            $customVersion,
            isset($options['allow-current-version'])
        );

        fwrite(STDOUT, sprintf("Prepared framework release %s\n", $result['version']));
        fwrite(STDOUT, sprintf("Release notes: %s\n", $result['release_notes_path']));

        if ($result['release_notes_created']) {
            fwrite(STDOUT, "Release notes template created.\n");
        }

        exit(0);
    }

    if ($mode === 'scaffold-downstream') {
        $profile = $options['profile'] ?? 'content-only-default';
        $contentRoot = $options['content-root'] ?? null;
        $scaffolder = new DownstreamScaffolder($frameworkRoot, $repoRoot);
        $adoptExistingManagedFiles = isset($options['adopt-existing-managed-files']);
        exit($mutationLock->synchronized(
            $repoRoot,
            static fn (): int => $scaffolder->scaffold(
                (string) $toolPath,
                (string) $profile,
                is_string($contentRoot) ? $contentRoot : null,
                $force,
                $adoptExistingManagedFiles
            ),
            'scaffold-downstream'
        ));
    }

    if ($mode === 'scaffold-premium-provider') {
        $provider = isset($options['provider']) && is_string($options['provider']) ? $options['provider'] : null;

        if ($provider === null || trim($provider) === '') {
            throw new RuntimeException('scaffold-premium-provider requires --provider=your-provider.');
        }

        $class = isset($options['class']) && is_string($options['class']) ? $options['class'] : null;
        $path = isset($options['path']) && is_string($options['path']) ? $options['path'] : null;
        $result = (new PremiumProviderScaffolder($frameworkRoot, $repoRoot))->scaffold($provider, $class, $path, $force);
        fwrite(STDOUT, sprintf("Scaffolded premium provider %s\n", $result['provider']));
        fwrite(STDOUT, sprintf("Registry: %s\n", $result['registry_path']));
        fwrite(STDOUT, sprintf("Class: %s\n", $result['class']));
        fwrite(STDOUT, sprintf("Path: %s\n", $result['path']));
        exit(0);
    }

    if ($mode === 'framework-apply') {
        $payloadRoot = $options['payload-root'] ?? null;
        $distributionPath = $options['distribution-path'] ?? null;
        $resultPath = $options['result-path'] ?? null;

        if (! is_string($payloadRoot) || $payloadRoot === '') {
            throw new RuntimeException('framework-apply requires --payload-root.');
        }

        if (! is_string($resultPath) || $resultPath === '') {
            throw new RuntimeException('framework-apply requires --result-path.');
        }

        $config = Config::load($repoRoot);
        $runtimeInspector = new RuntimeInspector($config->runtime);
        $result = $mutationLock->synchronized(
            $repoRoot,
            static fn (): array => (new FrameworkInstaller($repoRoot, $runtimeInspector))->apply(
                $payloadRoot,
                is_string($distributionPath) ? $distributionPath : 'vendor/wp-core-base'
            ),
            'framework-apply'
        );

        if (file_put_contents($resultPath, json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)) === false) {
            throw new RuntimeException(sprintf('Unable to write framework apply result file: %s', $resultPath));
        }

        exit(0);
    }

    $config = Config::load($repoRoot);
    $httpClient = new HttpClient(
        userAgent: 'wp-core-base/' . ($mode === 'sync' ? 'sync' : $mode)
    );
    $premiumProviderRegistry = PremiumProviderRegistry::load($repoRoot);
    $premiumProviders = $premiumProviderRegistry->providerKeys();
    $managedSourceRegistry = new ManagedSourceRegistry(
        new WordPressOrgManagedSource(new WordPressOrgClient($httpClient), $httpClient),
        new GitHubReleaseManagedSource(new GitHubReleaseClient($httpClient, $config->githubApiBase())),
        ...array_values($premiumProviderRegistry->instantiate($httpClient, new PremiumCredentialsStore()))
    );
    $adminGovernanceExporter = new AdminGovernanceExporter(new RuntimeInspector($config->runtime));

    if ($mode === 'stage-runtime') {
        $runtimeInspector = new RuntimeInspector($config->runtime);
        $stager = new RuntimeStager($config, $runtimeInspector, $adminGovernanceExporter);
        $stagedPaths = $stager->stage((string) ($options['output'] ?? $config->runtime['stage_dir']));
        fwrite(STDOUT, "Staged runtime paths:\n");

        foreach ($stagedPaths as $path) {
            fwrite(STDOUT, sprintf("- %s\n", $path));
        }

        exit(0);
    }

    if ($mode === 'refresh-admin-governance') {
        $mutationLock->synchronized(
            $repoRoot,
            static fn (): null => ($adminGovernanceExporter->refresh($config) ?: null),
            'refresh-admin-governance'
        );
        fwrite(STDOUT, "Refreshed admin governance runtime data\n");
        exit(0);
    }

    if ($mode === 'suggest-manifest') {
        fwrite(STDOUT, (new ManifestSuggester($config, $commandPrefix))->render());
        exit(0);
    }

    if ($mode === 'format-manifest') {
        $mutationLock->synchronized(
            $repoRoot,
            static fn (): null => ((new ManifestWriter())->write($config) ?: null),
            'format-manifest'
        );
        fwrite(STDOUT, sprintf("Formatted manifest: %s\n", $config->manifestPath));
        exit(0);
    }

    if (isset($options['help']) && in_array($mode, ['add-dependency', 'adopt-dependency', 'remove-dependency', 'scaffold-premium-provider', 'sync'], true)) {
        fwrite(STDOUT, CommandHelp::render($mode, $commandPrefix, $phpCommandPrefix));
        exit(0);
    }

    if (in_array($mode, ['add-dependency', 'adopt-dependency', 'remove-dependency', 'list-dependencies'], true)) {
        $prompter = new InteractivePrompter();

        if (
            $mode === 'add-dependency'
            && (
                isset($options['interactive'])
                || (
                    InteractivePrompter::canPrompt()
                    && (
                        ! isset($options['source'])
                        || ! isset($options['kind'])
                        || (! isset($options['slug']) && ! isset($options['path']))
                    )
                )
            )
        ) {
            $maybePromptForMissing($options, $prompter, $premiumProviders);
        }

        $authoringService = new DependencyAuthoringService(
            config: $config,
            metadataResolver: new DependencyMetadataResolver(),
            runtimeInspector: new RuntimeInspector($config->runtime),
            manifestWriter: new ManifestWriter(),
            managedSourceRegistry: $managedSourceRegistry,
            adminGovernanceExporter: $adminGovernanceExporter,
        );

        if ($mode === 'add-dependency') {
            if (isset($options['plan']) || isset($options['dry-run']) || isset($options['preview'])) {
                $result = $authoringService->planAddDependency($options);
                fwrite(STDOUT, "Planned dependency addition\n");
                fwrite(STDOUT, sprintf("Component: %s\n", $result['component_key']));
                fwrite(STDOUT, sprintf("Source: %s\n", $result['source']));
                fwrite(STDOUT, sprintf("Kind: %s\n", $result['kind']));
                fwrite(STDOUT, sprintf("Target path: %s\n", $result['target_path']));

                if (($result['selected_version'] ?? null) !== null) {
                    fwrite(STDOUT, sprintf("Selected version: %s\n", $result['selected_version']));
                }

                if (($result['main_file'] ?? null) !== null) {
                    fwrite(STDOUT, sprintf("Main file: %s\n", $result['main_file']));
                }

                if (($result['archive_subdir'] ?? '') !== '') {
                    fwrite(STDOUT, sprintf("Archive subdir: %s\n", $result['archive_subdir']));
                }

                fwrite(STDOUT, sprintf("Would replace existing path: %s\n", ($result['would_replace'] ?? false) ? 'yes' : 'no'));

                if (($result['source_reference'] ?? null) !== null) {
                    fwrite(STDOUT, sprintf("Resolved source: %s\n", $result['source_reference']));
                }

                fwrite(STDOUT, sprintf("Sanitize paths: %s\n", implode(', ', (array) ($result['sanitize_paths'] ?? [])) ?: '(none)'));
                fwrite(STDOUT, sprintf("Sanitize files: %s\n", implode(', ', (array) ($result['sanitize_files'] ?? [])) ?: '(none)'));
                exit(0);
            }

            $result = $mutationLock->synchronized(
                $repoRoot,
                static fn (): array => $authoringService->addDependency($options),
                'add-dependency'
            );
            fwrite(STDOUT, sprintf("Added dependency %s (%s)\n", $result['component_key'], $result['path']));

            if (($result['version'] ?? null) !== null) {
                fwrite(STDOUT, sprintf("Version: %s\n", $result['version']));
            }

            if (($result['checksum'] ?? null) !== null) {
                fwrite(STDOUT, sprintf("Checksum: %s\n", $result['checksum']));
            }

            foreach ((array) ($result['next_steps'] ?? []) as $step) {
                fwrite(STDOUT, sprintf("Next: %s\n", $step));
            }

            exit(0);
        }

        if ($mode === 'adopt-dependency') {
            if (isset($options['plan']) || isset($options['dry-run']) || isset($options['preview'])) {
                $result = $authoringService->planAdoptDependency($options);
                fwrite(STDOUT, "Planned dependency adoption\n");
                fwrite(STDOUT, sprintf("Adopt from: %s\n", $result['adopted_from']));
                fwrite(STDOUT, sprintf("To component: %s\n", $result['component_key']));
                fwrite(STDOUT, sprintf("Target path: %s\n", $result['target_path']));

                if (($result['selected_version'] ?? null) !== null) {
                    fwrite(STDOUT, sprintf("Selected version: %s\n", $result['selected_version']));
                }

                fwrite(STDOUT, sprintf("Preserve current version: %s\n", ($result['preserve_version'] ?? false) ? 'yes' : 'no'));
                fwrite(STDOUT, sprintf("Would replace existing path: %s\n", ($result['would_replace'] ?? false) ? 'yes' : 'no'));

                if (($result['source_reference'] ?? null) !== null) {
                    fwrite(STDOUT, sprintf("Resolved source: %s\n", $result['source_reference']));
                }

                fwrite(STDOUT, sprintf("Sanitize paths: %s\n", implode(', ', (array) ($result['sanitize_paths'] ?? [])) ?: '(none)'));
                fwrite(STDOUT, sprintf("Sanitize files: %s\n", implode(', ', (array) ($result['sanitize_files'] ?? [])) ?: '(none)'));
                exit(0);
            }

            $result = $mutationLock->synchronized(
                $repoRoot,
                static fn (): array => $authoringService->adoptDependency($options),
                'adopt-dependency'
            );
            fwrite(STDOUT, sprintf(
                "Adopted dependency %s from %s\n",
                $result['component_key'],
                $result['adopted_from']
            ));

            if (($result['version'] ?? null) !== null) {
                fwrite(STDOUT, sprintf("Version: %s\n", $result['version']));
            }

            if (($result['checksum'] ?? null) !== null) {
                fwrite(STDOUT, sprintf("Checksum: %s\n", $result['checksum']));
            }

            foreach ((array) ($result['next_steps'] ?? []) as $step) {
                fwrite(STDOUT, sprintf("Next: %s\n", $step));
            }

            exit(0);
        }

        if ($mode === 'remove-dependency') {
            $result = $mutationLock->synchronized(
                $repoRoot,
                static fn (): array => $authoringService->removeDependency($options),
                'remove-dependency'
            );
            fwrite(STDOUT, sprintf(
                "Removed dependency %s%s\n",
                $result['removed']['component_key'],
                $result['deleted_path'] ? ' and deleted its path' : ''
            ));
            exit(0);
        }

        fwrite(STDOUT, $authoringService->renderDependencyList());
        exit(0);
    }

    if ($mode === 'sync') {
        $gitHubClient = GitHubClient::fromEnvironment($httpClient, $config->githubApiBase(), $config->dryRun());
        $runtimeInspector = new RuntimeInspector($config->runtime);
        $dependencyUpdater = new Updater(
            config: $config,
            dependencyScanner: new DependencyScanner(),
            wordPressOrgClient: new WordPressOrgClient($httpClient),
            gitHubReleaseClient: new GitHubReleaseClient($httpClient, $config->githubApiBase()),
            managedSourceRegistry: $managedSourceRegistry,
            supportForumClient: new SupportForumClient($httpClient, 100),
            releaseClassifier: new ReleaseClassifier(),
            prBodyRenderer: new PrBodyRenderer(),
            gitHubClient: $gitHubClient,
            gitRunner: new GitCommandRunner($repoRoot, $config->dryRun()),
            runtimeInspector: $runtimeInspector,
            manifestWriter: new ManifestWriter(),
            httpClient: $httpClient,
            adminGovernanceExporter: $adminGovernanceExporter,
        );
        $coreUpdater = new CoreUpdater(
            config: $config,
            coreScanner: new CoreScanner(),
            coreClient: new WordPressCoreClient($httpClient),
            releaseClassifier: new ReleaseClassifier(),
            prBodyRenderer: new PrBodyRenderer(),
            gitHubClient: $gitHubClient,
            gitRunner: new GitCommandRunner($repoRoot, $config->dryRun()),
        );
        $errors = [];
        $dependencyWarnings = [];
        $reportPath = isset($options['report-json']) && is_string($options['report-json']) ? $options['report-json'] : null;
        $failOnSourceErrors = isset($options['fail-on-source-errors']);

        $mutationLock->synchronized($repoRoot, static function () use (
            $coreUpdater,
            $dependencyUpdater,
            &$dependencyWarnings,
            &$errors
        ): void {
            foreach ([
                'core' => static fn () => $coreUpdater->sync(),
                'dependencies' => static function () use ($dependencyUpdater, &$dependencyWarnings): void {
                    $dependencyWarnings = $dependencyUpdater->sync();
                },
            ] as $name => $syncPass) {
                try {
                    $syncPass();
                } catch (Throwable $throwable) {
                    $errors[] = OutputRedactor::redact(sprintf('%s: %s', $name, $throwable->getMessage()));
                }
            }
        }, 'sync');

        if ($reportPath !== null && trim($reportPath) !== '') {
            SyncReport::write(SyncReport::build($errors, $dependencyWarnings), $reportPath);
        }

        if ($errors !== []) {
            throw new RuntimeException(implode("\n\n", $errors));
        }

        if ($dependencyWarnings !== []) {
            fwrite(
                STDERR,
                "[warn] Non-fatal dependency-source failures were reported during sync:\n- " .
                implode("\n- ", $dependencyWarnings) .
                "\n"
            );

            if ($failOnSourceErrors) {
                exit(SyncReport::EXIT_SOURCE_WARNINGS);
            }
        }

        exit(0);
    }

    if ($mode === 'framework-sync') {
        $framework = FrameworkConfig::load($repoRoot);
        $checkOnly = isset($options['check-only']);
        $gitHubClient = $checkOnly
            ? null
            : GitHubClient::fromEnvironment($httpClient, $config->githubApiBase(), $config->dryRun());
        $frameworkSyncer = new FrameworkSyncer(
            framework: $framework,
            repoRoot: $repoRoot,
            frameworkReleaseClient: new FrameworkReleaseClient(
                new GitHubReleaseClient($httpClient, $config->githubApiBase())
            ),
            releaseClassifier: new ReleaseClassifier(),
            prBodyRenderer: new PrBodyRenderer(),
            gitHubClient: $gitHubClient,
            gitRunner: new GitCommandRunner($repoRoot, $config->dryRun()),
            runtimeInspector: new RuntimeInspector($config->runtime),
        );
        if ($checkOnly) {
            $frameworkSyncer->sync(true);
        } else {
            $mutationLock->synchronized(
                $repoRoot,
                static fn (): null => ($frameworkSyncer->sync(false) ?: null),
                'framework-sync'
            );
        }
        exit(0);
    }

    if ($mode === 'pr-blocker') {
        $gitHubClient = GitHubClient::fromEnvironment($httpClient, $config->githubApiBase(), $config->dryRun());
        $blocker = new PullRequestBlocker($gitHubClient);
        exit($blocker->evaluateCurrentPullRequest());
    }

    fwrite(STDERR, sprintf("Unknown mode: %s\n", $mode));
    fwrite(STDERR, "Run with `help` to see the available modes.\n");
    exit(2);
} catch (Throwable $throwable) {
    fwrite(STDERR, OutputRedactor::redact($throwable->getMessage()) . "\n");
    exit(1);
}
