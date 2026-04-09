<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater\Cli;

final class ModeDispatcher
{
    /** @var list<CliModeHandler> */
    private array $handlers;

    /**
     * @param list<CliModeHandler> $handlers
     */
    public function __construct(array $handlers)
    {
        $this->handlers = $handlers;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function dispatch(string $mode, array $options): ?int
    {
        foreach ($this->handlers as $handler) {
            if (! $handler->supports($mode)) {
                continue;
            }

            return $handler->handle($mode, $options);
        }

        return null;
    }
}
