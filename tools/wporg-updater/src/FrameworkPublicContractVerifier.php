<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class FrameworkPublicContractVerifier
{
    public function __construct(
        private readonly string $repoRoot,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function verify(FrameworkConfig $framework, string $releaseNotes): array
    {
        $config = Config::load($this->repoRoot);
        $readmePath = $this->repoRoot . '/README.md';
        $readme = file_get_contents($readmePath);

        if (! is_string($readme)) {
            throw new RuntimeException(sprintf('Unable to read README: %s', $readmePath));
        }

        $frameworkVersion = $framework->normalizedVersion();
        $baseline = [
            [
                'name' => 'WordPress core',
                'version' => $framework->baseline['wordpress_core'],
                'kind' => 'core',
            ],
            ...$framework->baseline['managed_components'],
        ];
        $manifestManaged = [];

        foreach ($config->managedDependencies() as $dependency) {
            $manifestManaged[] = [
                'name' => (string) $dependency['name'],
                'version' => (string) $dependency['version'],
                'kind' => (string) $dependency['kind'],
            ];
        }

        $normalizedExpected = $this->normalizeComponents($framework->baseline['managed_components']);
        $normalizedManifest = $this->normalizeComponents($manifestManaged);

        if ($normalizedExpected !== $normalizedManifest) {
            throw new RuntimeException(sprintf(
                'Framework baseline in .wp-core-base/framework.php does not match manifest-managed dependencies. Framework=%s Manifest=%s',
                $this->renderComponents($framework->baseline['managed_components']),
                $this->renderComponents($manifestManaged)
            ));
        }

        $requiredReadmeSnippets = [
            sprintf('- framework release `%s`', $frameworkVersion),
            ...array_map(
                static fn (array $component): string => sprintf('- %s `%s`', $component['name'], $component['version']),
                $baseline
            ),
        ];

        foreach ($requiredReadmeSnippets as $snippet) {
            if (! str_contains($readme, $snippet)) {
                throw new RuntimeException(sprintf(
                    'README.md is missing current framework/baseline fact: %s',
                    $snippet
                ));
            }
        }

        foreach ($baseline as $component) {
            $releaseSnippet = $component['kind'] === 'core'
                ? sprintf('- %s: `%s`', $component['name'], $component['version'])
                : sprintf('- %s `%s`', $component['name'], $component['version']);

            if (! str_contains($releaseNotes, $releaseSnippet)) {
                throw new RuntimeException(sprintf(
                    'Release notes for v%s are missing bundled baseline fact: %s',
                    $frameworkVersion,
                    $releaseSnippet
                ));
            }
        }

        return [
            'framework_version' => $frameworkVersion,
            'baseline' => $baseline,
            'managed_dependency_count' => count($manifestManaged),
        ];
    }

    /**
     * @param list<array{name:string, version:string, kind:string}> $components
     * @return list<string>
     */
    private function normalizeComponents(array $components): array
    {
        $normalized = array_map(
            static fn (array $component): string => sprintf(
                '%s|%s|%s',
                strtolower(trim((string) $component['name'])),
                strtolower(trim((string) $component['kind'])),
                trim((string) $component['version'])
            ),
            $components
        );
        sort($normalized);

        return $normalized;
    }

    /**
     * @param list<array{name:string, version:string, kind:string}> $components
     */
    private function renderComponents(array $components): string
    {
        return implode(
            ', ',
            array_map(
                static fn (array $component): string => sprintf(
                    '%s(%s)=%s',
                    $component['name'],
                    $component['kind'],
                    $component['version']
                ),
                $components
            )
        );
    }
}
