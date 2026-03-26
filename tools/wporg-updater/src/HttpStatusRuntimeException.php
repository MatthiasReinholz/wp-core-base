<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class HttpStatusRuntimeException extends RuntimeException
{
    public function __construct(
        private readonly int $status,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }
}
