<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

final class DependencyTrustState
{
    public const VERIFIED = 'verified';
    public const METADATA_ONLY = 'metadata-only';
    public const PROVIDER_ASSERTED = 'provider-asserted';

    public static function normalize(string $value): string
    {
        return match ($value) {
            self::VERIFIED, self::METADATA_ONLY, self::PROVIDER_ASSERTED => $value,
            default => self::METADATA_ONLY,
        };
    }

    public static function requiresReviewerWarning(string $value): bool
    {
        return self::normalize($value) !== self::VERIFIED;
    }

    public static function reviewerWarning(string $value): string
    {
        return match (self::normalize($value)) {
            self::VERIFIED => 'Archive authenticity was independently verified before apply.',
            self::PROVIDER_ASSERTED => 'Archive authenticity was asserted by the upstream provider integration and was not independently verified.',
            default => 'Archive authenticity was not independently verified; reviewers should treat this as metadata-only guidance.',
        };
    }
}
