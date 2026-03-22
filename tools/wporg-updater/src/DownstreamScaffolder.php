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

    public function scaffold(string $toolPath, string $profile = 'content-only-default', ?string $contentRoot = null, bool $force = false): int
    {
        if (! is_dir($this->repoRoot)) {
            throw new RuntimeException(sprintf('Repository root does not exist: %s', $this->repoRoot));
        }

        $preset = $this->presetForProfile($profile);
        $contentRoot = $this->normalizeContentRoot($contentRoot ?? ($preset['profile'] === 'content-only' ? 'cms' : 'wp-content'));
        $paths = $this->pathsForProfile($preset['profile'], $contentRoot);
        $ownershipRoots = $this->replacePathPlaceholders($preset['ownership_roots'], $paths);
        $managedSanitizePaths = $this->replacePathPlaceholders($preset['managed_sanitize_paths'], $paths);

        $this->printHeading('wp-core-base scaffold-downstream');

        $syncCommand = $this->updaterCommand($toolPath, 'sync');
        $blockerCommand = $this->updaterCommand($toolPath, 'pr-blocker');
        $doctorCommand = $this->updaterCommand($toolPath, 'doctor --repo-root=. --github');
        $stageCommand = $this->updaterCommand($toolPath, 'stage-runtime --repo-root=. --output=.wp-core-base/build/runtime');

        $writes = [
            [
                'source' => $this->frameworkRoot . '/tools/wporg-updater/templates/manifest.' . $preset['template'] . '.php.tpl',
                'target' => $this->repoRoot . '/.wp-core-base/manifest.php',
                'replacements' => [
                    '__PROFILE__' => $preset['profile'],
                    '__CONTENT_ROOT__' => $paths['content_root'],
                    '__PLUGINS_ROOT__' => $paths['plugins_root'],
                    '__THEMES_ROOT__' => $paths['themes_root'],
                    '__MU_PLUGINS_ROOT__' => $paths['mu_plugins_root'],
                    '__CORE_MODE__' => $preset['core_mode'],
                    '__CORE_ENABLED__' => $preset['core_enabled'] ? 'true' : 'false',
                    '__MANIFEST_MODE__' => $preset['manifest_mode'],
                    '__VALIDATION_MODE__' => $preset['validation_mode'],
                    '__OWNERSHIP_ROOTS__' => $this->exportInlineArray($ownershipRoots),
                    '__MANAGED_KINDS__' => $this->exportInlineArray($preset['managed_kinds']),
                    '__STAGED_KINDS__' => $this->exportInlineArray($preset['staged_kinds']),
                    '__VALIDATED_KINDS__' => $this->exportInlineArray($preset['validated_kinds']),
                    '__MANAGED_SANITIZE_PATHS__' => $this->exportInlineArray($managedSanitizePaths),
                    '__MANAGED_SANITIZE_FILES__' => $this->exportInlineArray($preset['managed_sanitize_files']),
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
        fwrite(STDOUT, "[next] Classify managed, local, ignored, and ownership-root runtime paths before enabling the scheduled workflow.\n");

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

    /**
     * @return array{template:string, profile:string, core_mode:string, core_enabled:bool, manifest_mode:string, validation_mode:string, ownership_roots:list<string>, managed_kinds:list<string>, staged_kinds:list<string>, validated_kinds:list<string>, managed_sanitize_paths:list<string>, managed_sanitize_files:list<string>}
     */
    private function presetForProfile(string $profile): array
    {
        return match ($profile) {
            'full-core' => [
                'template' => 'full-core',
                'profile' => 'full-core',
                'core_mode' => 'managed',
                'core_enabled' => true,
                'manifest_mode' => 'strict',
                'validation_mode' => 'source-clean',
                'ownership_roots' => ['__PLUGINS_ROOT__', '__THEMES_ROOT__', '__MU_PLUGINS_ROOT__'],
                'managed_kinds' => ['plugin', 'theme', 'mu-plugin-package'],
                'staged_kinds' => Config::runtimeKinds(),
                'validated_kinds' => Config::runtimeKinds(),
                'managed_sanitize_paths' => ['__PLUGINS_ROOT__/.github', '__PLUGINS_ROOT__/.wordpress-org', '__PLUGINS_ROOT__/node_modules', '__PLUGINS_ROOT__/docs', '__PLUGINS_ROOT__/tests', '__THEMES_ROOT__/.github', '__THEMES_ROOT__/.wordpress-org', '__THEMES_ROOT__/node_modules', '__THEMES_ROOT__/docs', '__THEMES_ROOT__/tests', '__MU_PLUGINS_ROOT__/.github', '__MU_PLUGINS_ROOT__/.wordpress-org', '__MU_PLUGINS_ROOT__/node_modules', '__MU_PLUGINS_ROOT__/docs', '__MU_PLUGINS_ROOT__/tests'],
                'managed_sanitize_files' => ['README*', 'CHANGELOG*', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'pnpm-lock.yaml', 'yarn.lock'],
            ],
            'content-only', 'content-only-default', 'content-only-local-mu' => [
                'template' => 'content-only',
                'profile' => 'content-only',
                'core_mode' => 'external',
                'core_enabled' => false,
                'manifest_mode' => 'strict',
                'validation_mode' => 'source-clean',
                'ownership_roots' => ['__PLUGINS_ROOT__', '__THEMES_ROOT__', '__MU_PLUGINS_ROOT__'],
                'managed_kinds' => ['plugin', 'theme'],
                'staged_kinds' => Config::runtimeKinds(),
                'validated_kinds' => Config::runtimeKinds(),
                'managed_sanitize_paths' => ['__PLUGINS_ROOT__/.github', '__PLUGINS_ROOT__/.wordpress-org', '__PLUGINS_ROOT__/node_modules', '__PLUGINS_ROOT__/docs', '__PLUGINS_ROOT__/tests', '__THEMES_ROOT__/.github', '__THEMES_ROOT__/.wordpress-org', '__THEMES_ROOT__/node_modules', '__THEMES_ROOT__/docs', '__THEMES_ROOT__/tests', '__MU_PLUGINS_ROOT__/.github', '__MU_PLUGINS_ROOT__/.wordpress-org', '__MU_PLUGINS_ROOT__/node_modules', '__MU_PLUGINS_ROOT__/docs', '__MU_PLUGINS_ROOT__/tests'],
                'managed_sanitize_files' => ['README*', 'CHANGELOG*', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'pnpm-lock.yaml', 'yarn.lock'],
            ],
            'content-only-migration' => [
                'template' => 'content-only',
                'profile' => 'content-only',
                'core_mode' => 'external',
                'core_enabled' => false,
                'manifest_mode' => 'relaxed',
                'validation_mode' => 'source-clean',
                'ownership_roots' => ['__PLUGINS_ROOT__', '__THEMES_ROOT__', '__MU_PLUGINS_ROOT__'],
                'managed_kinds' => ['plugin', 'theme'],
                'staged_kinds' => Config::runtimeKinds(),
                'validated_kinds' => Config::runtimeKinds(),
                'managed_sanitize_paths' => ['__PLUGINS_ROOT__/.github', '__PLUGINS_ROOT__/.wordpress-org', '__PLUGINS_ROOT__/node_modules', '__PLUGINS_ROOT__/docs', '__PLUGINS_ROOT__/tests', '__THEMES_ROOT__/.github', '__THEMES_ROOT__/.wordpress-org', '__THEMES_ROOT__/node_modules', '__THEMES_ROOT__/docs', '__THEMES_ROOT__/tests', '__MU_PLUGINS_ROOT__/.github', '__MU_PLUGINS_ROOT__/.wordpress-org', '__MU_PLUGINS_ROOT__/node_modules', '__MU_PLUGINS_ROOT__/docs', '__MU_PLUGINS_ROOT__/tests'],
                'managed_sanitize_files' => ['README*', 'CHANGELOG*', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'pnpm-lock.yaml', 'yarn.lock'],
            ],
            'content-only-image-first' => [
                'template' => 'content-only',
                'profile' => 'content-only',
                'core_mode' => 'external',
                'core_enabled' => false,
                'manifest_mode' => 'strict',
                'validation_mode' => 'staged-clean',
                'ownership_roots' => ['__PLUGINS_ROOT__', '__THEMES_ROOT__', '__MU_PLUGINS_ROOT__', '__CONTENT_ROOT__/languages'],
                'managed_kinds' => ['plugin', 'theme'],
                'staged_kinds' => Config::runtimeKinds(),
                'validated_kinds' => Config::runtimeKinds(),
                'managed_sanitize_paths' => [
                    '__PLUGINS_ROOT__/docs',
                    '__PLUGINS_ROOT__/.github',
                    '__PLUGINS_ROOT__/.wordpress-org',
                    '__PLUGINS_ROOT__/node_modules',
                    '__PLUGINS_ROOT__/doc',
                    '__PLUGINS_ROOT__/tests',
                    '__PLUGINS_ROOT__/test',
                    '__PLUGINS_ROOT__/__tests__',
                    '__PLUGINS_ROOT__/examples',
                    '__PLUGINS_ROOT__/example',
                    '__PLUGINS_ROOT__/demo',
                    '__PLUGINS_ROOT__/screenshots',
                    '__THEMES_ROOT__/docs',
                    '__THEMES_ROOT__/.github',
                    '__THEMES_ROOT__/.wordpress-org',
                    '__THEMES_ROOT__/node_modules',
                    '__THEMES_ROOT__/doc',
                    '__THEMES_ROOT__/tests',
                    '__THEMES_ROOT__/test',
                    '__THEMES_ROOT__/__tests__',
                    '__THEMES_ROOT__/examples',
                    '__THEMES_ROOT__/example',
                    '__THEMES_ROOT__/demo',
                    '__THEMES_ROOT__/screenshots',
                ],
                'managed_sanitize_files' => [
                    'README*',
                    'CHANGELOG*',
                    '.gitignore',
                    '.gitattributes',
                    'phpunit.xml*',
                    'composer.json',
                    'composer.lock',
                    'package.json',
                    'package-lock.json',
                    'pnpm-lock.yaml',
                    'yarn.lock',
                ],
            ],
            default => throw new RuntimeException(sprintf('Invalid scaffold profile: %s', $profile)),
        };
    }

    /**
     * @param list<string> $items
     */
    private function exportInlineArray(array $items): string
    {
        $quoted = array_map(static fn (string $item): string => "'" . $item . "'", $items);
        return '[' . implode(', ', $quoted) . ']';
    }

    /**
     * @param list<string> $items
     * @param array{content_root:string, plugins_root:string, themes_root:string, mu_plugins_root:string} $paths
     * @return list<string>
     */
    private function replacePathPlaceholders(array $items, array $paths): array
    {
        return array_map(static function (string $item) use ($paths): string {
            return str_replace(
                ['__CONTENT_ROOT__', '__PLUGINS_ROOT__', '__THEMES_ROOT__', '__MU_PLUGINS_ROOT__'],
                [$paths['content_root'], $paths['plugins_root'], $paths['themes_root'], $paths['mu_plugins_root']],
                $item
            );
        }, $items);
    }

    private function printHeading(string $heading): void
    {
        fwrite(STDOUT, $heading . "\n\n");
    }
}
