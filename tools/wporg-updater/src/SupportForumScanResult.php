<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

/** Incomplete enrichment must not advance support coverage or discard old topics. */
final class SupportForumScanResult
{
    /** @param list<array{title:string,url:string,opened_at:string}> $topics */
    public function __construct(
        public readonly array $topics,
        public readonly bool $complete,
        public readonly ?string $warning,
        public readonly int $requests,
        public readonly int $pages,
        public readonly int $topicsChecked,
        public readonly float $elapsedSeconds,
        public readonly string $scanStartedAt,
    ) {
    }
}
