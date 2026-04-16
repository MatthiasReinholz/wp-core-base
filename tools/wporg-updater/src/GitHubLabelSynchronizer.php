<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

final class GitHubLabelSynchronizer
{
    public function __construct(
        private readonly string $repository,
        private readonly bool $dryRun = false,
    ) {
    }

    /**
     * @param array<string, array{color:string, description:string}> $definitions
     * @param callable(string,string,?array<string,mixed>=): array<string, mixed>|list<mixed> $requestJson
     */
    public function ensureLabels(array $definitions, callable $requestJson): void
    {
        $definitions = LabelHelper::normalizeDefinitions($definitions);

        if ($this->dryRun) {
            fwrite(STDOUT, "[dry-run] Ensuring GitHub labels\n");
            return;
        }

        foreach ($definitions as $name => $definition) {
            $encodedName = rawurlencode($name);

            try {
                $requestJson('GET', '/repos/' . $this->repository . '/labels/' . $encodedName);
                $requestJson('PATCH', '/repos/' . $this->repository . '/labels/' . $encodedName, [
                    'new_name' => $name,
                    'color' => $definition['color'],
                    'description' => $definition['description'],
                ]);
            } catch (HttpStatusRuntimeException $exception) {
                if ($exception->status() !== 404) {
                    throw $exception;
                }

                $requestJson('POST', '/repos/' . $this->repository . '/labels', [
                    'name' => $name,
                    'color' => $definition['color'],
                    'description' => $definition['description'],
                ]);
            }
        }
    }
}
