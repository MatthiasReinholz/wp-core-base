<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

/** Per-plugin support enrichment budget for one updater process. */
final class SupportForumScanLimits
{
    public function __construct(
        public readonly int $maxPages = 10,
        public readonly int $maxRequests = 100,
        public readonly int $maxTopics = 75,
        public readonly int $maxSeconds = 30,
        public readonly int $requestTimeoutSeconds = 10,
    ) {
        foreach ([
            'max_pages' => [$maxPages, 100],
            'max_requests' => [$maxRequests, 1000],
            'max_topics' => [$maxTopics, 1000],
            'max_seconds' => [$maxSeconds, 300],
            'request_timeout_seconds' => [$requestTimeoutSeconds, 30],
        ] as $name => [$value, $ceiling]) {
            if ($value < 1 || $value > $ceiling) {
                throw new RuntimeException(sprintf('Support forum %s must be between 1 and %d.', $name, $ceiling));
            }
        }
    }
}
