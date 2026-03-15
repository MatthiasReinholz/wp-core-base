<?php

declare(strict_types=1);

use WpOrgPluginUpdater\Config;
use WpOrgPluginUpdater\CoreScanner;
use WpOrgPluginUpdater\CoreUpdater;
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
$repoRootEnv = getenv('WPORG_REPO_ROOT');
$repoRoot = is_string($repoRootEnv) && $repoRootEnv !== '' ? $repoRootEnv : dirname(__DIR__, 3);
$config = Config::load($repoRoot);
$httpClient = new HttpClient(
    userAgent: 'wp-core-base/' . ($mode === 'sync' ? 'sync' : 'pr-blocker')
);

try {
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
    exit(2);
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage() . "\n");
    exit(1);
}
