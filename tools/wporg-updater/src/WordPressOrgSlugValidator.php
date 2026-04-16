<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class WordPressOrgSlugValidator
{
    private const SLUG_PATTERN = '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/';

    public static function assertValid(string $slug): void
    {
        if (preg_match(self::SLUG_PATTERN, $slug) === 1) {
            return;
        }

        throw new RuntimeException(sprintf(
            'WordPress.org slug is invalid: %s. Slugs must match %s.',
            $slug,
            '^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$'
        ));
    }
}
