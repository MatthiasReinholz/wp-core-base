<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class DownstreamScaffolder
{
    public function __construct(
        private readonly string $frameworkRoot,
        private readonly string $repoRoot,
    ) {
    }

    public function scaffold(string $toolPath, bool $force = false): int
    {
        if (! is_dir($this->repoRoot)) {
            throw new RuntimeException(sprintf('Repository root does not exist: %s', $this->repoRoot));
        }

        $this->printHeading('wp-core-base scaffold-downstream');

        $syncCommand = $this->updaterCommand($toolPath, 'sync');
        $blockerCommand = $this->updaterCommand($toolPath, 'pr-blocker');

        $writes = [
            [
                'source' => $this->frameworkRoot . '/docs/examples/downstream-wporg-updates.php',
                'target' => $this->repoRoot . '/.github/wporg-updates.php',
                'render' => static fn (string $contents): string => $contents,
            ],
            [
                'source' => $this->frameworkRoot . '/tools/wporg-updater/templates/downstream-workflow.yml.tpl',
                'target' => $this->repoRoot . '/.github/workflows/wporg-updates.yml',
                'render' => static fn (string $contents): string => str_replace('__WPORG_SYNC_COMMAND__', $syncCommand, $contents),
            ],
            [
                'source' => $this->frameworkRoot . '/tools/wporg-updater/templates/downstream-pr-blocker-workflow.yml.tpl',
                'target' => $this->repoRoot . '/.github/workflows/wporg-update-pr-blocker.yml',
                'render' => static fn (string $contents): string => str_replace('__WPORG_BLOCKER_COMMAND__', $blockerCommand, $contents),
            ],
        ];

        foreach ($writes as $write) {
            $this->writeFile(
                $write['source'],
                $write['target'],
                $write['render'],
                $force
            );
        }

        fwrite(STDOUT, "\n");
        fwrite(STDOUT, "Next steps:\n");
        fwrite(STDOUT, sprintf("[next] Review the generated files in %s/.github.\n", $this->repoRoot));
        fwrite(STDOUT, sprintf("[next] Run `%s`.\n", $this->updaterCommand($toolPath, 'doctor --repo-root=. --github')));
        fwrite(STDOUT, "[next] Adjust managed plugins in `.github/wporg-updates.php` before enabling the scheduled workflow.\n");

        return 0;
    }

    /**
     * @param callable(string): string $render
     */
    private function writeFile(string $source, string $target, callable $render, bool $force): void
    {
        if (! is_file($source)) {
            throw new RuntimeException(sprintf('Scaffold template not found: %s', $source));
        }

        $contents = file_get_contents($source);

        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to read scaffold template: %s', $source));
        }

        $rendered = $render($contents);
        $targetDir = dirname($target);

        if (! is_dir($targetDir) && ! mkdir($targetDir, 0775, true) && ! is_dir($targetDir)) {
            throw new RuntimeException(sprintf('Unable to create directory: %s', $targetDir));
        }

        if (is_file($target)) {
            $existing = file_get_contents($target);

            if ($existing === $rendered) {
                fwrite(STDOUT, sprintf("[ok] Already up to date: %s\n", $target));
                return;
            }

            if (! $force) {
                fwrite(STDOUT, sprintf("[warn] Skipped existing file without --force: %s\n", $target));
                return;
            }
        }

        if (file_put_contents($target, $rendered) === false) {
            throw new RuntimeException(sprintf('Unable to write scaffolded file: %s', $target));
        }

        fwrite(STDOUT, sprintf("[ok] Wrote %s\n", $target));
    }

    private function updaterCommand(string $toolPath, string $mode): string
    {
        $normalized = trim($toolPath);

        if ($normalized === '' || $normalized === '.') {
            return sprintf('php tools/wporg-updater/bin/wporg-updater.php %s', $mode);
        }

        return sprintf(
            'php %s/tools/wporg-updater/bin/wporg-updater.php %s',
            trim($normalized, '/'),
            $mode
        );
    }

    private function printHeading(string $heading): void
    {
        fwrite(STDOUT, $heading . "\n\n");
    }
}
