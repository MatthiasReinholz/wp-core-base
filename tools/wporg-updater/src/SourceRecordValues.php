<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use DateTimeImmutable;
use RuntimeException;

/** Validates the small shared contract without constraining adapter-specific metadata. */
final class SourceRecordValues
{
    /** @param array<string,mixed> $data */
    public static function requiredString(array $data, string $key, string $context): string
    {
        $value = $data[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException(sprintf('%s must provide a non-empty string %s.', $context, $key));
        }
        return trim($value);
    }

    /** @param array<string,mixed> $data */
    public static function timestamp(array $data, string $key, string $context): string
    {
        $value = self::requiredString($data, $key, $context);
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
            throw new RuntimeException(sprintf('%s must provide an ISO-8601 timestamp with timezone for %s.', $context, $key));
        }
        try {
            new DateTimeImmutable($value);
            $errors = DateTimeImmutable::getLastErrors();
            if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                throw new RuntimeException('Invalid date or time.');
            }
        } catch (\Throwable $exception) {
            throw new RuntimeException(sprintf('%s contains an invalid %s timestamp.', $context, $key), 0, $exception);
        }
        return $value;
    }
}
