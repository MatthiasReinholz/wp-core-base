<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class MutationLock
{
    private const DEFAULT_TIMEOUT_SECONDS = 300;
    private const RETRY_INTERVAL_MICROSECONDS = 250000;

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function synchronized(string $repoRoot, callable $callback, string $name = 'mutation'): mixed
    {
        $lockDirectory = $repoRoot . '/.wp-core-base/build/locks';

        if (! is_dir($lockDirectory) && ! mkdir($lockDirectory, 0775, true) && ! is_dir($lockDirectory)) {
            throw new RuntimeException(sprintf('Unable to create lock directory: %s', $lockDirectory));
        }

        $lockPath = $lockDirectory . '/' . preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) . '.lock';
        $handle = fopen($lockPath, 'c+');

        if (! is_resource($handle)) {
            throw new RuntimeException(sprintf('Unable to open mutation lock: %s', $lockPath));
        }

        try {
            $this->acquireLockWithTimeout($handle, $lockPath);
            $this->writeOwnerMetadata($handle);

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     */
    private function acquireLockWithTimeout($handle, string $lockPath): void
    {
        $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS;
        $timeoutOverride = getenv('WP_CORE_BASE_LOCK_TIMEOUT_SECONDS');

        if (is_string($timeoutOverride) && ctype_digit($timeoutOverride) && (int) $timeoutOverride > 0) {
            $timeoutSeconds = (int) $timeoutOverride;
        }

        $deadline = microtime(true) + $timeoutSeconds;

        while (true) {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                return;
            }

            if (microtime(true) >= $deadline) {
                throw new RuntimeException(sprintf(
                    'Timed out after %d seconds waiting for mutation lock %s.%s',
                    $timeoutSeconds,
                    $lockPath,
                    $this->lockDiagnosticSuffix($handle)
                ));
            }

            usleep(self::RETRY_INTERVAL_MICROSECONDS);
        }
    }

    /**
     * @param resource $handle
     */
    private function writeOwnerMetadata($handle): void
    {
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, sprintf(
            "pid=%d\nacquired_at=%s\n",
            getmypid() ?: 0,
            gmdate(DATE_ATOM)
        ));
        fflush($handle);
    }

    /**
     * @param resource $handle
     */
    private function lockDiagnosticSuffix($handle): string
    {
        rewind($handle);
        $contents = stream_get_contents($handle);

        if (! is_string($contents) || trim($contents) === '') {
            return '';
        }

        $pid = null;
        $acquiredAt = null;

        foreach (preg_split('/\R+/', $contents) ?: [] as $line) {
            if (str_starts_with($line, 'pid=')) {
                $value = trim(substr($line, strlen('pid=')));
                if (ctype_digit($value)) {
                    $pid = $value;
                }

                continue;
            }

            if (str_starts_with($line, 'acquired_at=')) {
                $acquiredAt = trim(substr($line, strlen('acquired_at=')));
            }
        }

        $parts = [];

        if ($pid !== null) {
            $parts[] = sprintf('holder pid=%s', $pid);
        }

        if ($acquiredAt !== null && $acquiredAt !== '') {
            $parts[] = sprintf('holder acquired_at=%s', $acquiredAt);
        }

        if ($parts === []) {
            return '';
        }

        return ' Last known lock owner: ' . implode(', ', $parts) . '.';
    }
}
