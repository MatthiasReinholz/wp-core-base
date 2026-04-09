<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

final class TempDirectoryJanitor
{
    /**
     * @param list<string> $prefixes
     */
    public function __construct(
        private readonly array $prefixes = [],
        private readonly int $maxAgeSeconds = 3600,
        private readonly ?string $tempRoot = null,
    ) {
    }

    /**
     * @return array{removed:list<string>,failed:list<string>}
     */
    public function cleanup(): array
    {
        $tempRoot = $this->tempRoot ?? sys_get_temp_dir();
        $removed = [];
        $failed = [];

        if (! is_dir($tempRoot)) {
            return ['removed' => [], 'failed' => []];
        }

        $entries = scandir($tempRoot);

        if (! is_array($entries)) {
            return ['removed' => [], 'failed' => [sprintf('Unable to scan temp directory %s.', $tempRoot)]];
        }

        $now = time();

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || ! $this->matchesPrefix($entry)) {
                continue;
            }

            $path = $tempRoot . '/' . $entry;

            if (! is_dir($path)) {
                continue;
            }

            $modifiedAt = filemtime($path);

            if (! is_int($modifiedAt) || ($now - $modifiedAt) < $this->maxAgeSeconds) {
                continue;
            }

            if ($this->removeDirectoryTree($path)) {
                $removed[] = $path;
                continue;
            }

            $failed[] = sprintf('Failed to remove stale temporary directory %s.', $path);
        }

        return ['removed' => $removed, 'failed' => $failed];
    }

    /**
     * @return list<string>
     */
    public static function defaultPrefixes(): array
    {
        return [
            'wporg-update-',
            'wp-core-update-',
            'wporg-remove-backup-',
            'wporg-adopt-backup-',
            'wporg-authoring-',
            'wp-core-base-framework-',
            'wp-core-base-framework-meta-',
            'wp-core-base-release-verify-',
            'wp-core-base-artifact-',
        ];
    }

    public static function defaultMaxAgeSeconds(): int
    {
        $override = getenv('WP_CORE_BASE_TEMP_DIR_MAX_AGE_SECONDS');

        if (is_string($override) && ctype_digit($override) && (int) $override > 0) {
            return (int) $override;
        }

        return 3600;
    }

    private function matchesPrefix(string $entry): bool
    {
        foreach ($this->prefixes as $prefix) {
            if (is_string($prefix) && $prefix !== '' && str_starts_with($entry, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function removeDirectoryTree(string $path): bool
    {
        if (! is_dir($path)) {
            return true;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . '/' . $entry;

            if (is_link($child) || is_file($child)) {
                if (! unlink($child)) {
                    return false;
                }

                continue;
            }

            if (is_dir($child) && ! $this->removeDirectoryTree($child)) {
                return false;
            }
        }

        return rmdir($path);
    }
}

