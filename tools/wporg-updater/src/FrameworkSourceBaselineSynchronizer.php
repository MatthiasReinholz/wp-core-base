<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class FrameworkSourceBaselineSynchronizer
{
    public function __construct(
        private readonly string $repoRoot,
    ) {
    }

    /**
     * Keep the source repository's public baseline contract aligned after a
     * bundled dependency or WordPress core update. Vendored downstreams keep
     * their installed-release metadata pinned and are intentionally skipped.
     *
     * @return list<string>
     */
    public function synchronize(Config $config, ?string $wordPressCoreVersion = null): array
    {
        $frameworkPath = $this->repoRoot . '/.wp-core-base/framework.php';

        if (! is_file($frameworkPath)) {
            return [];
        }

        $framework = FrameworkConfig::load($this->repoRoot, $frameworkPath);

        if ($framework->distributionPath() !== '.') {
            return [];
        }

        $coreVersion = $wordPressCoreVersion ?? $framework->baseline['wordpress_core'];
        $managedComponents = array_map(
            static fn (array $dependency): array => [
                'name' => (string) $dependency['name'],
                'version' => (string) $dependency['version'],
                'kind' => (string) $dependency['kind'],
            ],
            $config->managedDependencies()
        );
        $updatedFramework = $framework->withInstalledRelease(
            version: $framework->version,
            wordPressCoreVersion: $coreVersion,
            managedComponents: $managedComponents,
            managedFiles: $framework->managedFiles(),
        );

        $versions = ['WordPress core' => $coreVersion];
        foreach ($managedComponents as $component) {
            if (isset($versions[$component['name']])) {
                throw new RuntimeException(sprintf(
                    'Managed component names must be unique in the public baseline: %s.',
                    $component['name']
                ));
            }

            $versions[$component['name']] = $component['version'];
        }

        $readmePath = $this->repoRoot . '/README.md';
        $releaseNotesPath = $this->repoRoot . '/docs/releases/' . $framework->normalizedVersion() . '.md';
        $readme = $this->updatedBaselineVersions($readmePath, 'Current Baseline', $versions);
        $releaseNotes = $this->updatedBaselineVersions($releaseNotesPath, 'Bundled Baseline', $versions);

        (new FrameworkWriter())->write($updatedFramework);
        $this->write($readmePath, $readme);
        $this->write($releaseNotesPath, $releaseNotes);

        return [
            '.wp-core-base/framework.php',
            'README.md',
            'docs/releases/' . $framework->normalizedVersion() . '.md',
        ];
    }

    /** @param array<string, string> $versions */
    private function updatedBaselineVersions(string $path, string $heading, array $versions): string
    {
        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new RuntimeException(sprintf('Unable to read public baseline document: %s', $path));
        }

        $sectionCount = 0;
        $updated = preg_replace_callback(
            '/(^## ' . preg_quote($heading, '/') . '\R)(.*?)(?=^## |\z)/ms',
            function (array $sectionMatches) use ($versions, $path, $heading): string {
                $section = $sectionMatches[2];

                foreach ($versions as $name => $version) {
                    $pattern = '/^(\- ' . preg_quote($name, '/') . '(?::)? `)[^`]+(`)$/m';
                    $replacementCount = 0;
                    $nextSection = preg_replace_callback(
                        $pattern,
                        static fn (array $matches): string => $matches[1] . $version . $matches[2],
                        $section,
                        -1,
                        $replacementCount
                    );

                    if (! is_string($nextSection) || $replacementCount !== 1) {
                        throw new RuntimeException(sprintf(
                            'Expected exactly one baseline entry for %s in the %s section of %s; found %d.',
                            $name,
                            $heading,
                            $path,
                            $replacementCount
                        ));
                    }

                    $section = $nextSection;
                }

                return $sectionMatches[1] . $section;
            },
            $contents,
            -1,
            $sectionCount
        );

        if (! is_string($updated) || $sectionCount !== 1) {
            throw new RuntimeException(sprintf(
                'Expected exactly one %s section in %s; found %d.',
                $heading,
                $path,
                $sectionCount
            ));
        }

        return $updated;
    }

    private function write(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException(sprintf('Unable to write public baseline document: %s', $path));
        }
    }
}
