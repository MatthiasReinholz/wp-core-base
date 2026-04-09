<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class AtomicFileWriter
{
    public function write(string $path, string $contents): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create directory: %s', $directory));
        }

        $temporaryPath = sprintf('%s/.%s.tmp.%s', $directory, basename($path), bin2hex(random_bytes(6)));

        try {
            if (file_put_contents($temporaryPath, $contents) === false) {
                throw new RuntimeException(sprintf('Unable to write temporary file for %s.', $path));
            }

            $existingPermissions = is_file($path) ? fileperms($path) : false;
            $mode = is_int($existingPermissions) ? ($existingPermissions & 0777) : 0664;

            if (! chmod($temporaryPath, $mode)) {
                throw new RuntimeException(sprintf('Unable to apply permissions to %s.', $temporaryPath));
            }

            if (! rename($temporaryPath, $path)) {
                throw new RuntimeException(sprintf('Unable to move temporary file into place for %s.', $path));
            }
        } finally {
            if (is_file($temporaryPath) && ! unlink($temporaryPath)) {
                fwrite(STDERR, sprintf("[warn] Unable to clean up temporary file %s\n", $temporaryPath));
            }
        }
    }
}
