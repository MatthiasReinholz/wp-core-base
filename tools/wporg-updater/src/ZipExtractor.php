<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;
use ZipArchive;

final class ZipExtractor
{
    private const MAX_ENTRY_COUNT = 25000;
    private const MAX_TOTAL_UNCOMPRESSED_BYTES = 1024 * 1024 * 1024;
    private const MAX_COMPRESSION_RATIO = 200;

    public static function extractValidated(ZipArchive $zip, string $destination): void
    {
        $totalUncompressedBytes = 0;

        if ($zip->numFiles > self::MAX_ENTRY_COUNT) {
            throw new RuntimeException(sprintf(
                'Archive contains %d entries, which exceeds the limit of %d.',
                $zip->numFiles,
                self::MAX_ENTRY_COUNT
            ));
        }

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entryName = $zip->getNameIndex($index);

            if (! is_string($entryName) || $entryName === '') {
                throw new RuntimeException('Archive contains an invalid entry name.');
            }

            self::assertSafeEntryName($entryName);
            self::assertNotSymlink($zip, $index, $entryName);
            $stat = $zip->statIndex($index);

            if (! is_array($stat)) {
                throw new RuntimeException(sprintf('Archive entry %s is missing stat metadata.', $entryName));
            }

            $entrySize = (int) ($stat['size'] ?? 0);
            $compressedSize = (int) ($stat['comp_size'] ?? 0);
            $totalUncompressedBytes += $entrySize;

            if ($totalUncompressedBytes > self::MAX_TOTAL_UNCOMPRESSED_BYTES) {
                throw new RuntimeException(sprintf(
                    'Archive expands beyond the allowed size limit of %d bytes.',
                    self::MAX_TOTAL_UNCOMPRESSED_BYTES
                ));
            }

            if ($compressedSize > 0 && $entrySize > ($compressedSize * self::MAX_COMPRESSION_RATIO)) {
                throw new RuntimeException(sprintf(
                    'Archive entry %s exceeds the allowed compression ratio.',
                    $entryName
                ));
            }
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
