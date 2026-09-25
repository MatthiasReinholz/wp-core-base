<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class MutationLock
{
    private const DEFAULT_TIMEOUT_SECONDS = 300;
    private const RETRY_INTERVAL_MICROSECONDS = 250000;

    /** @var array<string, array{pid:int, handle:resource, depth:int}> */
    private static array $held = [];

    /**
     * All operations share one lock. The name is diagnostic only.
     * Acquire before reading the manifest or constructing mutable services.
     */
    public function acquire(string $repoRoot, string $name = 'mutation'): MutationLease
    {
        $root = realpath($repoRoot);
        if ($root === false || ! is_dir($root)) {
            throw new RuntimeException(sprintf('Repository root does not exist: %s', $repoRoot));
        }
        $pid = getmypid() ?: 0;
        if (isset(self::$held[$root]) && self::$held[$root]['pid'] !== $pid) {
            // A fork must contend independently; never unlock its parent's handle.
            fclose(self::$held[$root]['handle']);
            unset(self::$held[$root]);
        }
        if (isset(self::$held[$root])) {
            self::$held[$root]['depth']++;
            return $this->lease($root, $pid);
        }

        $directory = $root;
        foreach (['.wp-core-base', 'build', 'locks'] as $segment) {
            $directory .= '/' . $segment;
            clearstatcache(true, $directory);
            if (is_link($directory) || (file_exists($directory) && ! is_dir($directory))) {
                throw new RuntimeException(sprintf('Unsafe mutation lock directory: %s', $directory));
            }
            if (! is_dir($directory) && ! mkdir($directory, 0700) && ! is_dir($directory)) {
                throw new RuntimeException(sprintf('Unable to create lock directory: %s', $directory));
            }
            clearstatcache(true, $directory);
            $directoryState = lstat($directory);
            if ($directoryState === false || ($directoryState['mode'] & 0170000) !== 0040000 || realpath($directory) !== $directory) {
                throw new RuntimeException(sprintf('Mutation lock directory changed during creation: %s', $directory));
            }
        }
        $lockPath = $directory . '/mutation.lock';
        clearstatcache(true, $lockPath);
        if (is_link($lockPath) || (file_exists($lockPath) && ! is_file($lockPath))) {
            throw new RuntimeException(sprintf('Unsafe mutation lock file: %s', $lockPath));
        }
        $handle = fopen($lockPath, 'c+');
        if (! is_resource($handle)) {
            throw new RuntimeException(sprintf('Unable to open mutation lock: %s', $lockPath));
        }
        try {
            clearstatcache(true, $lockPath);
            $opened = fstat($handle);
            $named = lstat($lockPath);
            if ($opened === false || $named === false || ($named['mode'] & 0170000) !== 0100000
                || $opened['dev'] !== $named['dev'] || $opened['ino'] !== $named['ino']) {
                throw new RuntimeException(sprintf('Mutation lock file changed while opening: %s', $lockPath));
            }
            $this->acquireLockWithTimeout($handle, $lockPath);
            clearstatcache(true, $lockPath);
            $current = lstat($lockPath);
            if ($current === false || $opened['dev'] !== $current['dev'] || $opened['ino'] !== $current['ino']) {
                throw new RuntimeException(sprintf('Mutation lock was replaced while waiting: %s', $lockPath));
            }
            $this->writeOwnerMetadata($handle, $name);
            self::$held[$root] = ['pid' => $pid, 'handle' => $handle, 'depth' => 1];
        } catch (\Throwable $throwable) {
            fclose($handle);
            throw $throwable;
        }
        return $this->lease($root, $pid);
    }

    private function lease(string $root, int $pid): MutationLease
    {
        return new MutationLease(static function () use ($root, $pid): void {
            if ((getmypid() ?: 0) !== $pid || ! isset(self::$held[$root])) {
                return;
            }
            if (--self::$held[$root]['depth'] === 0) {
                flock(self::$held[$root]['handle'], LOCK_UN);
                fclose(self::$held[$root]['handle']);
                unset(self::$held[$root]);
            }
        });
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function synchronized(string $repoRoot, callable $callback, string $name = 'mutation'): mixed
    {
        $lease = $this->acquire($repoRoot, $name);
        try {
            return $callback();
        } finally {
            $lease->close();
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
    private function writeOwnerMetadata($handle, string $name): void
    {
        $metadata = sprintf(
            "pid=%d\nacquired_at=%s\noperation=%s\n",
            getmypid() ?: 0,
            gmdate(DATE_ATOM),
            preg_replace('/[^A-Za-z0-9._-]/', '-', $name)
        );
        if (! ftruncate($handle, 0) || ! rewind($handle) || fwrite($handle, $metadata) !== strlen($metadata) || ! fflush($handle)) {
            throw new RuntimeException('Unable to write mutation lock owner diagnostics.');
        }
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
        $operation = null;

        foreach (preg_split('/\R+/', $contents) ?: [] as $line) {
            if (str_starts_with($line, 'pid=')) {
                $value = trim(substr($line, strlen('pid=')));
                if (ctype_digit($value)) {
                    $pid = $value;
                }

                continue;
            }

            if (str_starts_with($line, 'operation=')) {
                $operation = trim(substr($line, strlen('operation=')));
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

        if ($operation !== null && $operation !== '') {
            $parts[] = sprintf('holder operation=%s', $operation);
        }

        if ($parts === []) {
            return '';
        }

        return ' Last known lock owner: ' . implode(', ', $parts) . '.';
    }
}
