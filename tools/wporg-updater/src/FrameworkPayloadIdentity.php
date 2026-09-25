<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

/** A valid signature authorizes bytes; this binds those bytes to the requested release. */
final class FrameworkPayloadIdentity
{
    public static function assertMatches(FrameworkConfig $payload, FrameworkConfig $expectedSource, string $requestedVersion, bool $allowDowngrade = false): void
    {
        if ($requestedVersion === '' || $payload->normalizedVersion() !== ltrim($requestedVersion, 'vV')) {
            throw new RuntimeException(sprintf('Release artifact framework version mismatch. Expected %s but found %s.', $requestedVersion, $payload->version));
        }
        if ($payload->releaseSourceIdentity() !== $expectedSource->releaseSourceIdentity()) {
            throw new RuntimeException('Release artifact authoritative source mismatch.');
        }
        if ($payload->assetName() !== $expectedSource->assetName()) {
            throw new RuntimeException(sprintf('Release artifact asset-name mismatch. Expected %s but found %s.', $expectedSource->assetName(), $payload->assetName()));
        }
        if (! $allowDowngrade && version_compare($payload->normalizedVersion(), $expectedSource->normalizedVersion(), '<')) {
            throw new RuntimeException(sprintf('Framework downgrade from %s to %s is not permitted.', $expectedSource->version, $payload->version));
        }
    }
}
