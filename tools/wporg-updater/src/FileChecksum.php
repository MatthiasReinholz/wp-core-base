<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class FileChecksum
{
    public static function sha256(string $path): string
    {
        $checksum = @hash_file('sha256', $path);

        if ($checksum === false) {
            throw new RuntimeException(sprintf('Unable to hash file: %s', $path));
        }

        return strtolower($checksum);
    }

    public static function assertSha256Matches(string $path, string $expectedChecksum, string $label): void
    {
        $normalizedExpected = self::normalizeSha256($expectedChecksum);

        if ($normalizedExpected === null) {
            throw new RuntimeException(sprintf('Expected SHA-256 checksum for %s was invalid.', $label));
        }

        $actualChecksum = self::sha256($path);

        if (! hash_equals($normalizedExpected, $actualChecksum)) {
            throw new RuntimeException(sprintf(
                '%s checksum mismatch. Expected %s but found %s.',
                $label,
                $normalizedExpected,
                $actualChecksum
            ));
        }
    }

    public static function extractSha256ForAsset(string $contents, string $assetName): string
    {
        $lines = preg_split("/\r\n|\n|\r/", trim($contents)) ?: [];

        foreach ($lines as $line) {
            $checksum = self::parseSha256Line($line, $assetName);

            if ($checksum !== null) {
                return $checksum;
            }
        }

        throw new RuntimeException(sprintf('Checksum file did not contain a matching SHA-256 digest for %s.', $assetName));
    }

    private static function parseSha256Line(string $line, string $assetName): ?string
    {
        $line = trim($line);

        if ($line === '') {
            return null;
        }

        $parts = preg_split('/\s+/', $line, 2);
        $checksum = strtolower((string) ($parts[0] ?? ''));

        if (preg_match('/^[a-f0-9]{64}$/', $checksum) !== 1) {
            return null;
        }

        $filename = trim((string) ($parts[1] ?? ''), " *\t");

        if ($filename === '') {
            return null;
        }

        if ($filename !== $assetName) {
            throw new RuntimeException(sprintf(
                'Checksum file entry bound digest to %s, expected %s.',
                $filename,
                $assetName
            ));
        }

        return $checksum;
    }

    private static function normalizeSha256(string $checksum): ?string
    {
        $normalized = strtolower(trim($checksum));

        if (str_starts_with($normalized, 'sha256:')) {
            $normalized = substr($normalized, 7);
        }

        return preg_match('/^[a-f0-9]{64}$/', $normalized) === 1 ? $normalized : null;
    }
}
