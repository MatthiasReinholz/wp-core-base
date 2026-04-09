<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

final class StructuredLogger
{
    private static ?string $operationId = null;

    public static function startTimer(): float
    {
        return microtime(true);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function log(
        string $level,
        string $operation,
        string $message,
        ?string $componentKey = null,
        ?float $startedAt = null,
        array $context = [],
    ): void {
        if (! self::jsonLogsEnabled()) {
            return;
        }

        $record = [
            'timestamp' => gmdate(DATE_ATOM),
            'operation_id' => self::operationId(),
            'operation' => $operation,
            'component_key' => $componentKey,
            'level' => strtolower($level),
            'message' => $message,
        ];

        if ($startedAt !== null) {
            $record['duration_ms'] = max(0, (int) round((microtime(true) - $startedAt) * 1000));
        }

        if ($context !== []) {
            $record['context'] = $context;
        }

        fwrite(STDERR, json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }

    private static function jsonLogsEnabled(): bool
    {
        $value = getenv('WP_CORE_BASE_JSON_LOGS');

        if (! is_string($value)) {
            return false;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function operationId(): string
    {
        if (self::$operationId === null) {
            self::$operationId = bin2hex(random_bytes(8));
        }

        return self::$operationId;
    }
}

