<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use Throwable;

final class TempDirectoryJanitor
{
    /**
     * Prefixes are retained for source compatibility only. Legacy prefix-named directories
     * have no ownership contract and are deliberately never removed.
     * @param list<string> $prefixes
     */
    public function __construct(
        array $prefixes = [],
        private readonly int $maxAgeSeconds = 3600,
        private readonly ?string $tempRoot = null,
        private readonly ?string $repoRoot = null,
    ) {
        // Keep the obsolete argument for downstream named and positional callers.
        unset($prefixes);
    }

    /** @return array{removed:list<string>,failed:list<string>} */
    public function cleanup(): array
    {
        $removed = [];
        $failed = [];
        if ($this->repoRoot === null) {
            return ['removed' => [], 'failed' => []];
        }
        try {
            $namespace = TempWorkspace::namespacePath($this->repoRoot, $this->tempRoot);
            if (! TempWorkspace::isOwnedPrivateDirectory(dirname($namespace)) || ! TempWorkspace::isOwnedPrivateDirectory($namespace)) {
                return ['removed' => [], 'failed' => []];
            }
            $repository = TempWorkspace::repositoryIdentity($this->repoRoot);
            $entries = scandir($namespace);
            if (! is_array($entries)) {
                return ['removed' => [], 'failed' => [sprintf('Unable to scan workspace namespace %s.', $namespace)]];
            }
            foreach ($entries as $entry) {
                if (! str_starts_with($entry, 'workspace-')) {
                    continue;
                }
                $path = $namespace . '/' . $entry;
                $marker = TempWorkspace::readMarker($path);
                if ($marker === null || $marker['repository'] !== $repository || $marker['preserved']
                    || time() - $marker['created_at'] < $this->maxAgeSeconds) {
                    continue;
                }
                $lockPath = $path . '/' . TempWorkspace::LOCK;
                if (! TempWorkspace::isOwnedPrivateFile($lockPath)) {
                    continue;
                }
                $before = lstat($lockPath);
                $lock = @fopen($lockPath, 'r+b');
                if ($lock === false) {
                    continue;
                }
                try {
                    $opened = fstat($lock);
                    if (! is_array($before) || ! is_array($opened) || $before['ino'] !== $opened['ino'] || $before['dev'] !== $opened['dev']
                        || ! flock($lock, LOCK_EX | LOCK_NB)) {
                        continue;
                    }
                    // Revalidate under the same operation lock before retiring the directory.
                    $current = TempWorkspace::readMarker($path);
                    if ($current !== $marker || ! TempWorkspace::isOwnedPrivateDirectory($namespace)
                        || ! TempWorkspace::isOwnedPrivateDirectory(dirname($namespace))) {
                        continue;
                    }
                    $quarantine = $namespace . '/retired-' . bin2hex(random_bytes(16));
                    if (! @rename($path, $quarantine)) {
                        $failed[] = sprintf('Unable to retire stale workspace %s.', $path);
                        continue;
                    }
                    if (TempWorkspace::removeOwnedTree($quarantine)) {
                        $removed[] = $path;
                    } else {
                        $failed[] = sprintf('Unable to remove retired workspace %s.', $quarantine);
                    }
                } finally {
                    flock($lock, LOCK_UN);
                    fclose($lock);
                }
            }
        } catch (Throwable $exception) {
            $failed[] = $exception->getMessage();
        }
        return ['removed' => $removed, 'failed' => $failed];
    }

    /** @return list<string> */
    public static function defaultPrefixes(): array
    {
        return ['wporg-update-', 'wp-core-update-', 'wporg-remove-backup-', 'wporg-adopt-backup-', 'wporg-authoring-',
            'wp-core-base-framework-', 'wp-core-base-framework-meta-', 'wp-core-base-release-verify-', 'wp-core-base-artifact-'];
    }

    public static function defaultMaxAgeSeconds(): int
    {
        $override = getenv('WP_CORE_BASE_TEMP_DIR_MAX_AGE_SECONDS');
        return is_string($override) && ctype_digit($override) && (int) $override > 0 ? (int) $override : 3600;
    }
}
