<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

final class SourceCatalog
{
    /** @param array<string,mixed> $data */
    private function __construct(
        public readonly string $latestVersion,
        public readonly string $latestReleaseAt,
        private readonly array $data,
    ) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data, string $source): self
    {
        $context = sprintf('Source %s catalog', $source);
        return new self(
            SourceRecordValues::requiredString($data, 'latest_version', $context),
            SourceRecordValues::timestamp($data, 'latest_release_at', $context),
            $data
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [...$this->data, 'latest_version' => $this->latestVersion, 'latest_release_at' => $this->latestReleaseAt];
    }
}
