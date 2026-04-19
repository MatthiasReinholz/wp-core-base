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
        $currentVersion = $framework->normalizedVersion();
        $version = $this->resolveVersion($currentVersion, $releaseType, $customVersion, $allowCurrentVersion);
        $releaseNotesPath = $this->repoRoot . '/docs/releases/' . $version . '.md';
        $releaseNotesCreated = false;

        if (! is_file($releaseNotesPath)) {
            $this->writeReleaseNotesTemplate($framework, $currentVersion, $version, $releaseNotesPath);
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

    private function writeReleaseNotesTemplate(FrameworkConfig $framework, string $currentVersion, string $targetVersion, string $path): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create release notes directory: %s', $directory));
        }

        $scope = $this->releaseScope($currentVersion, $targetVersion);
        $components = array_map(
            static fn (array $component): string => sprintf(
                '- %s `%s`',
                $component['name'],
                $component['version']
            ),
            $framework->baseline['managed_components']
        );

        $summary = sprintf(
            'This is the `%s` framework release from `v%s` to `v%s` for `wp-core-base`.' . "\n\n" .
            'It publishes the current validated framework state for downstream adoption and framework self-update PRs.',
            $scope,
            $currentVersion,
            $targetVersion
        );

        $downstreamImpact = sprintf(
            'Downstream repositories pinned to an older `wp-core-base` release can update to `v%s` through `framework-sync` or by vendoring the published snapshot manually.' . "\n\n" .
            'Review any framework-managed workflow refreshes, release automation changes, and documentation updates in the release PR before rollout.',
            $targetVersion
        );

        $migrationNotes = sprintf(
            'No special migration steps are expected by default for `v%s`.' . "\n\n" .
            'If your downstream repository has locally customized scaffolded workflow files, review upstream workflow diffs manually before adopting this release.',
            $targetVersion
        );

        $workflowChanges = sprintf(
            'No framework-managed downstream workflow or pipeline template changes are expected by default for `v%s`.' . "\n\n" .
            'If this release changes GitHub workflows, GitLab pipeline jobs, required permissions, removed managed files, or framework-sync behavior, replace this paragraph with the concrete downstream impact.',
            $targetVersion
        );

        $requiredDownstreamActions = sprintf(
            'No extra downstream action is required by default for `v%s`.' . "\n\n" .
            'If downstreams with customized framework-managed files must act before rollout, replace this paragraph with the exact steps and include `framework-sync --check-only --fail-on-skipped-managed-files` when appropriate.',
            $targetVersion
        );

        $operationalNotes = sprintf(
            'The published framework asset for this release is `%s`.' . "\n\n" .
            'Normal release flow: run `prepare-wp-core-base-release`, review and merge `release/v%s`, then let `finalize-wp-core-base-release` create the tag and publish the authoritative framework release artifact.',
            $framework->assetName(),
            $targetVersion
        );

        $contents = sprintf(
            "# wp-core-base %s\n\n## Summary\n\n%s\n\n## Downstream Impact\n\n%s\n\n## Migration Notes\n\n%s\n\n## Downstream Workflow Changes\n\n%s\n\n## Required Downstream Actions\n\n%s\n\n## Bundled Baseline\n\n- WordPress core: `%s`\n%s\n\n## Operational Notes\n\n%s\n",
            $targetVersion,
            $summary,
            $downstreamImpact,
            $migrationNotes,
            $workflowChanges,
            $requiredDownstreamActions,
            $framework->baseline['wordpress_core'],
            $components === [] ? '' : implode("\n", $components),
            $operationalNotes
        );

        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException(sprintf('Unable to write release notes template: %s', $path));
        }
    }

    private function releaseScope(string $currentVersion, string $targetVersion): string
    {
        [$currentMajor, $currentMinor] = array_map('intval', array_slice(explode('.', $currentVersion), 0, 2));
        [$targetMajor, $targetMinor] = array_map('intval', array_slice(explode('.', $targetVersion), 0, 2));

        if ($targetMajor > $currentMajor) {
            return 'major';
        }

        if ($targetMinor > $currentMinor) {
            return 'minor';
        }

        return 'patch';
    }
}
