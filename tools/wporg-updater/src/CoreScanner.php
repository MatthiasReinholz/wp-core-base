<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class CoreScanner
{
    /**
     * @return array{version:string}
     */
    public function inspect(string $repoRoot): array
    {
        $versionFile = $repoRoot . '/wp-includes/version.php';

        if (! is_file($versionFile)) {
            throw new RuntimeException(sprintf('WordPress core version file not found: %s', $versionFile));
        }

        $contents = file_get_contents($versionFile);

        if (! is_string($contents)) {
            throw new RuntimeException(sprintf('Failed to read WordPress core version file: %s', $versionFile));
        }

        if (preg_match("/\\$wp_version\\s*=\\s*'([^']+)'\\s*;/", $contents, $matches) !== 1) {
            throw new RuntimeException('Failed to parse $wp_version from wp-includes/version.php.');
        }

        return ['version' => trim($matches[1])];
    }
}
