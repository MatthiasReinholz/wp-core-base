<?php

declare(strict_types=1);

use WpOrgPluginUpdater\Config;
use WpOrgPluginUpdater\CoreScanner;
use WpOrgPluginUpdater\CoreUpdater;
use WpOrgPluginUpdater\DownstreamScaffolder;
use WpOrgPluginUpdater\EnvironmentDoctor;
use WpOrgPluginUpdater\GitCommandRunner;
use WpOrgPluginUpdater\GitHubClient;
use WpOrgPluginUpdater\HttpClient;
use WpOrgPluginUpdater\PluginScanner;
use WpOrgPluginUpdater\PrBodyRenderer;
use WpOrgPluginUpdater\PullRequestBlocker;
use WpOrgPluginUpdater\ReleaseClassifier;
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
  php tools/wporg-updater/bin/wporg-updater.php scaffold-downstream [--repo-root=/path] [--tool-path=vendor/wp-core-base] [--force]
  php tools/wporg-updater/bin/wporg-updater.php pr-blocker

Modes:
  sync        Run WordPress core and plugin reconciliation.
  doctor      Validate local prerequisites, config, and optional GitHub environment.
  scaffold-downstream  Create downstream config and workflow files from the bundled templates.
  pr-blocker  Evaluate whether the current PR should remain blocked.

Flags and environment:
  --repo-root=PATH      Override the repository root to inspect or update.
  --tool-path=PATH      Path from the downstream repo root to the wp-core-base checkout for scaffold mode.
  --force               Overwrite scaffolded files when they already exist.
  WPORG_REPO_ROOT       Environment alternative to --repo-root.
  WPORG_UPDATE_DRY_RUN  Enable dry-run behavior for sync mode.

TEXT);
        exit(0);
    }

    if ($mode === 'doctor') {
        $doctor = new EnvironmentDoctor($repoRoot);
        exit($doctor->run(isset($options['github'])));
    }

    if ($mode === 'scaffold-downstream') {
        $scaffolder = new DownstreamScaffolder($frameworkRoot, $repoRoot);
        exit($scaffolder->scaffold((string) $toolPath, $force));
    }

    $config = Config::load($repoRoot);
    $httpClient = new HttpClient(
        userAgent: 'wp-core-base/' . ($mode === 'sync' ? 'sync' : 'pr-blocker')
    );

    if ($mode === 'sync') {
        $gitHubClient = GitHubClient::fromEnvironment($httpClient, $config->githubApiBase, $config->dryRun);
        $pluginUpdater = new Updater(
            config: $config,
            pluginScanner: new PluginScanner(),
            wordPressOrgClient: new WordPressOrgClient($httpClient),
            supportForumClient: new SupportForumClient($httpClient, $config->supportMaxPages),
            releaseClassifier: new ReleaseClassifier(),
            prBodyRenderer: new PrBodyRenderer(),
            gitHubClient: $gitHubClient,
            gitRunner: new GitCommandRunner($repoRoot, $config->dryRun),
        );
        $coreUpdater = new CoreUpdater(
            config: $config,
            coreScanner: new CoreScanner(),
            coreClient: new WordPressCoreClient($httpClient),
            releaseClassifier: new ReleaseClassifier(),
            prBodyRenderer: new PrBodyRenderer(),
            gitHubClient: $gitHubClient,
            gitRunner: new GitCommandRunner($repoRoot, $config->dryRun),
        );
        $errors = [];

        foreach ([
            'core' => static fn () => $coreUpdater->sync(),
            'plugins' => static fn () => $pluginUpdater->sync(),
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

        exit(0);
    }

    if ($mode === 'pr-blocker') {
        $gitHubClient = GitHubClient::fromEnvironment($httpClient, $config->githubApiBase, $config->dryRun);
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
