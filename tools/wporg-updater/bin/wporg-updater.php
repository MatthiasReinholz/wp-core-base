<?php

declare(strict_types=1);

use WpOrgPluginUpdater\Config;
use WpOrgPluginUpdater\CoreScanner;
use WpOrgPluginUpdater\CoreUpdater;
use WpOrgPluginUpdater\DependencyScanner;
use WpOrgPluginUpdater\DownstreamScaffolder;
use WpOrgPluginUpdater\EnvironmentDoctor;
use WpOrgPluginUpdater\FrameworkConfig;
use WpOrgPluginUpdater\FrameworkInstaller;
use WpOrgPluginUpdater\FrameworkReleaseClient;
use WpOrgPluginUpdater\FrameworkReleasePreparer;
use WpOrgPluginUpdater\FrameworkReleaseVerifier;
use WpOrgPluginUpdater\FrameworkSyncer;
use WpOrgPluginUpdater\GitCommandRunner;
use WpOrgPluginUpdater\GitHubClient;
use WpOrgPluginUpdater\GitHubReleaseClient;
use WpOrgPluginUpdater\HttpClient;
use WpOrgPluginUpdater\ManifestWriter;
use WpOrgPluginUpdater\ManifestSuggester;
use WpOrgPluginUpdater\PrBodyRenderer;
use WpOrgPluginUpdater\PullRequestBlocker;
use WpOrgPluginUpdater\ReleaseClassifier;
use WpOrgPluginUpdater\RuntimeInspector;
use WpOrgPluginUpdater\RuntimeStager;
use WpOrgPluginUpdater\SupportForumClient;
use WpOrgPluginUpdater\Updater;
use WpOrgPluginUpdater\WordPressCoreClient;
use WpOrgPluginUpdater\WordPressOrgClient;

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

try {
    if (in_array($mode, ['help', '--help', '-h'], true)) {
        fwrite(STDOUT, <<<TEXT
Usage:
  php tools/wporg-updater/bin/wporg-updater.php sync
  php tools/wporg-updater/bin/wporg-updater.php doctor [--repo-root=/path] [--github]
  php tools/wporg-updater/bin/wporg-updater.php stage-runtime [--repo-root=/path] [--output=.wp-core-base/build/runtime]
  php tools/wporg-updater/bin/wporg-updater.php scaffold-downstream [--repo-root=/path] [--tool-path=vendor/wp-core-base] [--profile=content-only-default] [--content-root=cms] [--force]
  php tools/wporg-updater/bin/wporg-updater.php framework-sync [--repo-root=/path] [--check-only]
  php tools/wporg-updater/bin/wporg-updater.php prepare-framework-release [--repo-root=/path] --release-type=patch|minor|major|custom [--version=v1.0.1]
  php tools/wporg-updater/bin/wporg-updater.php release-verify [--repo-root=/path] [--tag=v1.0.0]
  php tools/wporg-updater/bin/wporg-updater.php suggest-manifest [--repo-root=/path]
  php tools/wporg-updater/bin/wporg-updater.php format-manifest [--repo-root=/path]
  php tools/wporg-updater/bin/wporg-updater.php pr-blocker

Modes:
  sync              Run WordPress core and dependency reconciliation.
  doctor            Validate the manifest, repo structure, runtime hygiene, and optional GitHub environment.
  stage-runtime     Assemble a clean runtime payload for image builds.
  scaffold-downstream  Create a manifest and workflow files from the bundled templates.
  framework-sync    Update the vendored wp-core-base framework snapshot from GitHub Releases.
  prepare-framework-release  Bump framework release metadata and scaffold release notes for a release PR.
  release-verify    Validate framework release metadata and release notes before publishing.
  suggest-manifest  Print suggested manifest entries for undeclared runtime paths.
  format-manifest   Rewrite the manifest into the normalized framework format.
  pr-blocker        Evaluate whether the current PR should remain blocked.

Flags and environment:
  --repo-root=PATH       Override the repository root to inspect or update.
  --tool-path=PATH       Path from the downstream repo root to the wp-core-base checkout for scaffold mode.
  --profile=PROFILE      Downstream scaffold profile or preset: full-core, content-only, content-only-default, content-only-migration, content-only-local-mu, or content-only-image-first.
  --content-root=PATH    Override the scaffolded content root.
  --output=PATH          Override the stage-runtime output path.
  --check-only           Print framework update availability without changing files.
  --release-type=TYPE    Release bump type for prepare-framework-release: patch, minor, major, or custom.
  --tag=TAG              Expected release tag for release-verify mode.
  --version=VERSION      Custom release version for prepare-framework-release.
  --allow-current-version  Allow prepare-framework-release to reuse the current version when refreshing an existing release branch.
  --force                Overwrite scaffolded files when they already exist.
  WPORG_REPO_ROOT        Environment alternative to --repo-root.
  WPORG_UPDATE_DRY_RUN   Enable dry-run behavior for sync mode.

TEXT);
        exit(0);
    }

    if ($mode === 'doctor') {
        $doctor = new EnvironmentDoctor($repoRoot);
        exit($doctor->run(isset($options['github'])));
    }

    if ($mode === 'release-verify') {
        $tag = isset($options['tag']) && is_string($options['tag']) ? $options['tag'] : null;
        $resolvedTag = (new FrameworkReleaseVerifier($repoRoot))->verify($tag);
        fwrite(STDOUT, sprintf("Release verification passed for %s\n", $resolvedTag));
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
        exit($scaffolder->scaffold((string) $toolPath, (string) $profile, is_string($contentRoot) ? $contentRoot : null, $force));
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
        $result = (new FrameworkInstaller($repoRoot, $runtimeInspector))->apply(
            $payloadRoot,
            is_string($distributionPath) ? $distributionPath : 'vendor/wp-core-base'
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

    if ($mode === 'stage-runtime') {
        $runtimeInspector = new RuntimeInspector($config->runtime);
        $stager = new RuntimeStager($config, $runtimeInspector);
        $stagedPaths = $stager->stage((string) ($options['output'] ?? $config->runtime['stage_dir']));
        fwrite(STDOUT, "Staged runtime paths:\n");

        foreach ($stagedPaths as $path) {
            fwrite(STDOUT, sprintf("- %s\n", $path));
        }

        exit(0);
    }

    if ($mode === 'suggest-manifest') {
        fwrite(STDOUT, (new ManifestSuggester($config))->render());
        exit(0);
    }

    if ($mode === 'format-manifest') {
        (new ManifestWriter())->write($config);
        fwrite(STDOUT, sprintf("Formatted manifest: %s\n", $config->manifestPath));
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
            supportForumClient: new SupportForumClient($httpClient, 100),
            releaseClassifier: new ReleaseClassifier(),
            prBodyRenderer: new PrBodyRenderer(),
            gitHubClient: $gitHubClient,
            gitRunner: new GitCommandRunner($repoRoot, $config->dryRun()),
            runtimeInspector: $runtimeInspector,
            manifestWriter: new ManifestWriter(),
            httpClient: $httpClient,
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

        foreach ([
            'core' => static fn () => $coreUpdater->sync(),
            'dependencies' => static function () use ($dependencyUpdater, &$dependencyWarnings): void {
                $dependencyWarnings = $dependencyUpdater->sync();
            },
        ] as $name => $syncPass) {
            try {
                $syncPass();
            } catch (Throwable $throwable) {
                $errors[] = sprintf('%s: %s', $name, $throwable->getMessage());
            }
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
        $frameworkSyncer->sync($checkOnly);
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
    fwrite(STDERR, $throwable->getMessage() . "\n");
    exit(1);
}
