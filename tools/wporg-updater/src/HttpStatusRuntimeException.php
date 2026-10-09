<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class HttpStatusRuntimeException extends RuntimeException
{
    /** @param list<array{resource:string, field:string, code:string}> $errors */
    public function __construct(
        private readonly int $status,
        string $message,
        ?\Throwable $previous = null,
        private readonly array $errors = [],
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** @return list<array{resource:string, field:string, code:string}> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function status(): int
    {
        return $this->status;
    }
}
