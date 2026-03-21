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

    public function scaffold(string $toolPath, string $profile = 'content-only', ?string $contentRoot = null, bool $force = false): int
    {
        if (! is_dir($this->repoRoot)) {
            throw new RuntimeException(sprintf('Repository root does not exist: %s', $this->repoRoot));
        }

        if (! in_array($profile, ['full-core', 'content-only'], true)) {
            throw new RuntimeException(sprintf('Invalid scaffold profile: %s', $profile));
        }

        $contentRoot = $this->normalizeContentRoot($contentRoot ?? ($profile === 'content-only' ? 'cms' : 'wp-content'));
        $paths = $this->pathsForProfile($profile, $contentRoot);

        $this->printHeading('wp-core-base scaffold-downstream');

        $syncCommand = $this->updaterCommand($toolPath, 'sync');
        $blockerCommand = $this->updaterCommand($toolPath, 'pr-blocker');
        $doctorCommand = $this->updaterCommand($toolPath, 'doctor --repo-root=. --github');
        $stageCommand = $this->updaterCommand($toolPath, 'stage-runtime --repo-root=. --output=.wp-core-base/build/runtime');

        $writes = [
            [
                'source' => $this->frameworkRoot . '/tools/wporg-updater/templates/manifest.' . $profile . '.php.tpl',
                'target' => $this->repoRoot . '/.wp-core-base/manifest.php',
                'replacements' => [
                    '__PROFILE__' => $profile,
                    '__CONTENT_ROOT__' => $paths['content_root'],
                    '__PLUGINS_ROOT__' => $paths['plugins_root'],
                    '__THEMES_ROOT__' => $paths['themes_root'],
                    '__MU_PLUGINS_ROOT__' => $paths['mu_plugins_root'],
                    '__CORE_MODE__' => $profile === 'content-only' ? 'external' : 'managed',
                    '__CORE_ENABLED__' => $profile === 'content-only' ? 'false' : 'true',
                ],
            ],
            [
                'source' => $this->frameworkRoot . '/tools/wporg-updater/templates/downstream-workflow.yml.tpl',
                'target' => $this->repoRoot . '/.github/workflows/wporg-updates.yml',
                'replacements' => ['__WPORG_SYNC_COMMAND__' => $syncCommand],
            ],
            [
                'source' => $this->frameworkRoot . '/tools/wporg-updater/templates/downstream-pr-blocker-workflow.yml.tpl',
                'target' => $this->repoRoot . '/.github/workflows/wporg-update-pr-blocker.yml',
                'replacements' => ['__WPORG_BLOCKER_COMMAND__' => $blockerCommand],
            ],
            [
                'source' => $this->frameworkRoot . '/tools/wporg-updater/templates/downstream-validate-runtime-workflow.yml.tpl',
                'target' => $this->repoRoot . '/.github/workflows/wporg-validate-runtime.yml',
                'replacements' => [
                    '__WPORG_DOCTOR_COMMAND__' => $doctorCommand,
                    '__WPORG_STAGE_RUNTIME_COMMAND__' => $stageCommand,
                ],
            ],
        ];

        foreach ($writes as $write) {
            $this->writeFile(
                $write['source'],
                $write['target'],
                $write['replacements'],
                $force
            );
        }

        fwrite(STDOUT, "\n");
        fwrite(STDOUT, "Next steps:\n");
        fwrite(STDOUT, sprintf("[next] Review the generated manifest at %s/.wp-core-base/manifest.php.\n", $this->repoRoot));
        fwrite(STDOUT, sprintf("[next] Run `%s`.\n", $doctorCommand));
        fwrite(STDOUT, "[next] Classify your runtime trees as managed, local, or ignored before enabling the scheduled workflow.\n");

        return 0;
    }

    /**
     * @param array<string, string> $replacements
     */
    private function writeFile(string $source, string $target, array $replacements, bool $force): void
    {
        if (! is_file($source)) {
            throw new RuntimeException(sprintf('Scaffold template not found: %s', $source));
        }

        $contents = file_get_contents($source);

        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to read scaffold template: %s', $source));
        }

        $rendered = str_replace(array_keys($replacements), array_values($replacements), $contents);
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

    /**
     * @return array{content_root:string, plugins_root:string, themes_root:string, mu_plugins_root:string}
     */
    private function pathsForProfile(string $profile, string $contentRoot): array
    {
        return [
            'content_root' => $contentRoot,
            'plugins_root' => $contentRoot . '/plugins',
            'themes_root' => $contentRoot . '/themes',
            'mu_plugins_root' => $contentRoot . '/mu-plugins',
        ];
    }

    private function normalizeContentRoot(string $contentRoot): string
    {
        $normalized = trim(str_replace('\\', '/', $contentRoot), '/');

        if ($normalized === '' || str_starts_with($normalized, '..') || str_contains($normalized, '../')) {
            throw new RuntimeException(sprintf('Invalid content root: %s', $contentRoot));
        }

        return $normalized;
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
