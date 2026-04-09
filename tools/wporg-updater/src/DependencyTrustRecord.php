<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

final class DependencyTrustRecord
{
    public function __construct(
        public readonly string $componentKey,
        public readonly string $targetVersion,
        public readonly string $trustState,
        public readonly string $trustDetails,
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function fromMetadata(string $componentKey, string $targetVersion, array $metadata): self
    {
        return new self(
            componentKey: $componentKey,
            targetVersion: $targetVersion,
            trustState: DependencyTrustState::normalize((string) ($metadata['trust_state'] ?? DependencyTrustState::METADATA_ONLY)),
            trustDetails: (string) ($metadata['trust_details'] ?? ''),
        );
    }

    /**
     * @return array{component_key:string,target_version:string,trust_state:string,trust_details:string}
     */
    public function toArray(): array
    {
        return [
            'component_key' => $this->componentKey,
            'target_version' => $this->targetVersion,
            'trust_state' => $this->trustState,
            'trust_details' => $this->trustDetails,
        ];
    }
}
