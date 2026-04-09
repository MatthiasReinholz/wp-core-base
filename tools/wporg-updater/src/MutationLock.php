<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class MutationLock
{
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
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException(sprintf('Unable to acquire mutation lock: %s', $lockPath));
            }

            return $callback();
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
