<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class FrameworkReleasePreparer
{
    public function __construct(
        private readonly string $repoRoot,
    ) {
    }

    /**
     * @return array{version:string, release_notes_path:string, release_notes_created:bool}
     */
    public function prepare(string $releaseType, ?string $customVersion = null, bool $allowCurrentVersion = false): array
    {
        $framework = FrameworkConfig::load($this->repoRoot);
        $version = $this->resolveVersion($framework->normalizedVersion(), $releaseType, $customVersion, $allowCurrentVersion);
        $releaseNotesPath = $this->repoRoot . '/docs/releases/' . $version . '.md';
        $releaseNotesCreated = false;

        if (! is_file($releaseNotesPath)) {
            $this->writeReleaseNotesTemplate($framework, $version, $releaseNotesPath);
            $releaseNotesCreated = true;
        }

        if ($framework->normalizedVersion() !== $version) {
            $updated = $framework->withInstalledRelease(
                version: $version,
                wordPressCoreVersion: $framework->baseline['wordpress_core'],
                managedComponents: $framework->baseline['managed_components'],
                managedFiles: $framework->managedFiles(),
            );
            (new FrameworkWriter())->write($updated);
        } elseif (! $allowCurrentVersion) {
            throw new RuntimeException(sprintf(
                'Target framework version %s is already present. Use allow-current-version only when refreshing an existing release branch.',
                $version
            ));
        }

        return [
            'version' => 'v' . $version,
            'release_notes_path' => $releaseNotesPath,
            'release_notes_created' => $releaseNotesCreated,
        ];
    }

    private function resolveVersion(string $currentVersion, string $releaseType, ?string $customVersion, bool $allowCurrentVersion): string
    {
        $resolved = null;

        if ($releaseType === 'custom') {
            if (! is_string($customVersion) || trim($customVersion) === '') {
                throw new RuntimeException('Custom releases require --version=vX.Y.Z.');
            }

            $resolved = $this->normalizeVersion($customVersion);
        } elseif (! in_array($releaseType, ['patch', 'minor', 'major'], true)) {
            throw new RuntimeException('release_type must be one of: patch, minor, major, custom.');
        } else {
            [$major, $minor, $patch] = array_map('intval', explode('.', $currentVersion));

            $resolved = match ($releaseType) {
                'patch' => sprintf('%d.%d.%d', $major, $minor, $patch + 1),
                'minor' => sprintf('%d.%d.%d', $major, $minor + 1, 0),
                'major' => sprintf('%d.%d.%d', $major + 1, 0, 0),
            };
        }

        if (version_compare($resolved, $currentVersion, $allowCurrentVersion ? '<' : '<=')) {
            throw new RuntimeException(sprintf(
                'Target framework version %s must be %s the current version %s.',
                $resolved,
                $allowCurrentVersion ? 'greater than or equal to' : 'greater than',
                $currentVersion
            ));
        }

        return $resolved;
    }

    private function normalizeVersion(string $version): string
    {
        $normalized = ltrim(trim($version), 'v');

        if (preg_match('/^\d+\.\d+\.\d+$/', $normalized) !== 1) {
            throw new RuntimeException(sprintf('Custom version must use vX.Y.Z or X.Y.Z format. Received: %s', $version));
        }

        return $normalized;
    }

    private function writeReleaseNotesTemplate(FrameworkConfig $framework, string $version, string $path): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create release notes directory: %s', $directory));
        }

        $components = array_map(
            static fn (array $component): string => sprintf(
                '- %s %s',
                $component['name'],
                $component['version']
            ),
            $framework->baseline['managed_components']
        );

        $contents = sprintf(
            "# wp-core-base %s\n\n## Summary\n\nDescribe the framework changes in this release.\n\n## Downstream Impact\n\nDescribe what downstream repos need to know before adopting this release.\n\n## Migration Notes\n\nDocument any migration steps or confirm that no special migration is required.\n\n## Bundled Baseline\n\n- WordPress core: `%s`\n%s\n\n## Operational Notes\n\nAdd any notes about release packaging, workflow behavior, or review expectations.\n",
            $version,
            $framework->baseline['wordpress_core'],
            $components === [] ? '' : implode("\n", $components)
        );

        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException(sprintf('Unable to write release notes template: %s', $path));
        }
    }
}
