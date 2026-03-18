<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;
use ZipArchive;

final class ZipExtractor
{
    public static function extractValidated(ZipArchive $zip, string $destination): void
    {
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entryName = $zip->getNameIndex($index);

            if (! is_string($entryName) || $entryName === '') {
                throw new RuntimeException('Archive contains an invalid entry name.');
            }

            self::assertSafeEntryName($entryName);
            self::assertNotSymlink($zip, $index, $entryName);
        }

        if (! $zip->extractTo($destination)) {
            throw new RuntimeException(sprintf('Failed to extract archive into %s.', $destination));
        }
    }

    public static function assertSafeEntryName(string $entryName): void
    {
        $normalized = str_replace('\\', '/', $entryName);
        $normalized = rtrim($normalized, '/');

        if ($normalized === '') {
            throw new RuntimeException('Archive contains an empty path.');
        }

        if (str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:\//', $normalized) === 1) {
            throw new RuntimeException(sprintf('Archive entry %s uses an absolute path.', $entryName));
        }

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new RuntimeException(sprintf('Archive entry %s contains path traversal.', $entryName));
            }
        }
    }

    private static function assertNotSymlink(ZipArchive $zip, int $index, string $entryName): void
    {
        $operationsSystem = 0;
        $attributes = 0;

        if (! $zip->getExternalAttributesIndex($index, $operationsSystem, $attributes)) {
            return;
        }

        $fileType = ($attributes >> 16) & 0xF000;

        if ($fileType === 0xA000) {
            throw new RuntimeException(sprintf('Archive entry %s is a symlink and will not be extracted.', $entryName));
        }
    }
}
