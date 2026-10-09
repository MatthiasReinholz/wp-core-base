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
     * @param callable(string,string,?array<string,mixed>=):(array<string, mixed>|list<mixed>) $requestJson
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

            $path = '/repos/' . $this->repository . '/labels/' . $encodedName;
            try {
                $requestJson('GET', $path);
            } catch (HttpStatusRuntimeException $exception) {
                if ($exception->status() !== 404) {
                    throw $exception;
                }

                try {
                    $requestJson('POST', '/repos/' . $this->repository . '/labels', [
                        'name' => $name,
                        'color' => $definition['color'],
                        'description' => $definition['description'],
                    ]);
                    continue;
                } catch (HttpStatusRuntimeException $creationFailure) {
                    if ($creationFailure->status() !== 422 || $creationFailure->errors() !== [
                        ['resource' => 'Label', 'field' => 'name', 'code' => 'already_exists'],
                    ]) {
                        throw $creationFailure;
                    }
                    // GitHub label names are case-insensitive; require the requested
                    // label to exist before applying the normal metadata policy.
                    $existing = $requestJson('GET', $path);
                    if (! is_string($existing['name'] ?? null) || strcasecmp($existing['name'], $name) !== 0) {
                        throw $creationFailure;
                    }
                }
            }

            // A failed metadata update is not evidence that the label is missing.
            $requestJson('PATCH', $path, [
                'new_name' => $name,
                'color' => $definition['color'],
                'description' => $definition['description'],
            ]);
        }
    }
}
