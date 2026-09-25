<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class SourceRelease
{
    /** @param array<string,mixed> $data */
    private function __construct(
        public readonly string $version,
        public readonly string $releaseAt,
        private readonly array $data,
    ) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data, string $source, string $requestedVersion): self
    {
        $context = sprintf('Source %s release', $source);
        $version = SourceRecordValues::requiredString($data, 'version', $context);
        if ($version !== $requestedVersion) {
            throw new RuntimeException(sprintf('%s resolved version %s while %s was requested.', $context, $version, $requestedVersion));
        }
        return new self($version, SourceRecordValues::timestamp($data, 'release_at', $context), $data);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [...$this->data, 'version' => $this->version, 'release_at' => $this->releaseAt];
    }
}
