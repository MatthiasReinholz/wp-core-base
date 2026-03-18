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

        $configPath = $this->repoRoot . '/.github/wporg-updates.php';
        $this->okIf(is_file($configPath), 'Updater config file exists.', sprintf('Config file not found: %s', $configPath));

        $config = null;

        try {
            $config = Config::load($this->repoRoot);
            $this->ok('Updater config loads successfully.');
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
        }

        if ($config !== null) {
            $this->inspectConfiguredContent($config);
        }

        $this->inspectGitHubEnvironment($requireGitHub);
        $this->inspectGitHubWorkflows($requireGitHub);
        $this->printSummary();

        return $this->errors === 0 ? 0 : 1;
    }

    private function inspectConfiguredContent(Config $config): void
    {
        if ($config->coreConfig()['enabled']) {
            try {
                $core = (new CoreScanner())->inspect($this->repoRoot);
                $this->ok(sprintf('WordPress core detected at version %s.', $core['version']));
            } catch (RuntimeException $exception) {
                $this->error($exception->getMessage());
            }
        } else {
            $this->warn('WordPress core updates are disabled in config.');
        }

        $plugins = $config->enabledPlugins();

        if ($plugins === []) {
            $this->warn('No managed plugins are enabled in config.');
            return;
        }

        foreach ($plugins as $pluginConfig) {
            try {
                $plugin = (new PluginScanner())->inspect($this->repoRoot, $pluginConfig);
                $this->ok(sprintf(
                    'Managed plugin %s detected at %s (%s).',
                    $pluginConfig['slug'],
                    $plugin['version'],
                    $plugin['path']
                ));
            } catch (RuntimeException $exception) {
                $this->error($exception->getMessage());
            }
        }
    }

    private function inspectGitHubEnvironment(bool $requireGitHub): void
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
            return;
        }

        $message = 'GitHub automation environment is not fully configured. Set GITHUB_REPOSITORY and GITHUB_TOKEN to run sync or pr-blocker modes.';

        if ($requireGitHub) {
            $this->error($message);
            return;
        }

        $this->warn($message . ' This is fine for local verification and non-GitHub use.');
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

        foreach ($workflowFiles as $workflowFile) {
            $contents = file_get_contents($workflowFile);

            if ($contents === false) {
                continue;
            }

            $hasSyncWorkflow = $hasSyncWorkflow || str_contains($contents, 'wporg-updater.php sync');
            $hasBlockerWorkflow = $hasBlockerWorkflow || str_contains($contents, 'wporg-updater.php pr-blocker');
        }

        $this->okIf(
            $hasSyncWorkflow,
            'Found a GitHub workflow that runs sync mode.',
            'No GitHub workflow found that runs `wporg-updater.php sync`.'
        );

        if ($hasBlockerWorkflow) {
            $this->ok('Found a GitHub workflow that runs blocker mode.');
            return;
        }

        $this->warn('No GitHub workflow found that runs `wporg-updater.php pr-blocker`. Later minor and major update PRs will not queue automatically.');
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

        fwrite(STDOUT, sprintf(
            "Doctor found %d error(s) and %d warning(s).\n",
            $this->errors,
            $this->warnings
        ));
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

    private function okIf(bool $condition, string $successMessage, string $failureMessage): void
    {
        if ($condition) {
            $this->ok($successMessage);
            return;
        }

        $this->error($failureMessage);
    }

    private function warnIf(bool $condition, string $message): void
    {
        if ($condition) {
            $this->warn($message);
        }
    }
}
