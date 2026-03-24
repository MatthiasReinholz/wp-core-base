<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

final class ManifestSuggester
{
    public function __construct(
        private readonly Config $config,
        private readonly string $commandPrefix = 'php tools/wporg-updater/bin/wporg-updater.php',
    ) {
    }

    public function render(): string
    {
        $ownershipInspector = new RuntimeOwnershipInspector($this->config);
        $entries = $ownershipInspector->undeclaredRuntimePaths();

        if ($entries === []) {
            return "No undeclared runtime paths were detected.\n";
        }

        $lines = [];
        $lines[] = "Suggested manifest entries:";
        $lines[] = '';

        foreach ($entries as $entry) {
            $suggestion = $ownershipInspector->suggestedManifestEntry($entry);
            $lines[] = sprintf('# %s', $entry['path']);
            $lines[] = '# Recommended command:';
            $lines[] = sprintf(
                '%s add-dependency --source=local --kind=%s --path=%s',
                $this->commandPrefix,
                $entry['kind'],
                $entry['path']
            );

            if ($entry['is_symlink']) {
                $lines[] = '# This path is a symlink. Prefer converting it into local code, a release-backed managed dependency, or an ignored path.';
            }

            $lines[] = var_export($suggestion, true) . ',';
            $lines[] = '';
        }

        return implode("\n", $lines) . "\n";
    }
}
