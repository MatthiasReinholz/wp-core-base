<?php

declare(strict_types=1);

use WpOrgPluginUpdater\HttpClient;
use WpOrgPluginUpdater\SupportForumClient;
use WpOrgPluginUpdater\SupportForumScanLimits;

/** @param callable(bool,string):void $assert */
function run_support_forum_scan_contract_tests(callable $assert, string $repoRoot): void
{
    $feed = static function (array $topics): string {
        $xml = '<rss version="2.0"><channel>';
        foreach ($topics as $slug => $date) {
            $xml .= '<item><title>' . $slug . '</title><link>https://wordpress.org/support/topic/' . $slug . '/</link><pubDate>' . $date . '</pubDate></item>';
        }
        return $xml . '</channel></rss>';
    };
    $listing = static function (array $slugs, int $pages = 1): string {
        $html = '<html><body><div class="bbp-pagination-links"><a class="page-numbers">' . $pages . '</a></div>';
        foreach ($slugs as $slug) {
            $html .= '<a class="bbp-topic-permalink" href="https://wordpress.org/support/topic/' . $slug . '/">' . $slug . '</a>';
        }
        return $html . '</body></html>';
    };
    $topic = static fn (string $date): string => '<html><head><meta property="article:published_time" content="' . $date . '"></head></html>';
    $make = static function (array $responses, ?SupportForumScanLimits $limits = null, ?int $legacyPages = null): array {
        $limits ??= new SupportForumScanLimits();
        $state = (object) ['responses' => $responses, 'requests' => [], 'progress' => [], 'now' => 1000.0];
        $request = static function (string $url, array $options) use ($state): string {
            $state->requests[] = ['url' => $url, 'options' => $options];
            if ($state->responses === []) {
                throw new RuntimeException('Unexpected fixture request.');
            }
            $response = array_shift($state->responses);
            if (is_array($response)) {
                $state->now += $response['seconds'];
                $response = $response['body'];
            }
            if ($response instanceof Exception) {
                throw $response;
            }
            return $response;
        };
        $client = new SupportForumClient(new HttpClient(), $legacyPages ?? $limits->maxPages, $limits,
            static function (string $message) use ($state): void { $state->progress[] = $message; },
            $request, static fn (): float => $state->now);
        return [$client, $state];
    };
    $release = new DateTimeImmutable('2026-09-10T00:00:00Z');
    $newDate = '2026-09-24T00:00:00Z';
    $oldDate = '2026-09-01T00:00:00Z';
    $completeFeed = $feed(['new-topic' => $newDate, 'old-topic' => $oldDate]);
    $emptyFeed = $feed([]);

    [$client, $state] = $make([$completeFeed]);
    $result = $client->scanTopicsOpenedAfter('example-plugin', $release);
    $assert($result->complete && $result->warning === null, 'RSS covering the release window completes without listing requests.');
    $assert(array_column($result->topics, 'title') === ['new-topic'], 'Complete RSS retains only topics newer than the requested window.');
    $assert($result->requests === 1 && $result->pages === 0 && $result->topicsChecked === 0, 'RSS fast path reports accurate counters.');
    $assert(count($state->requests) === 1 && str_ends_with($state->requests[0]['url'], '/example-plugin/feed/'), 'RSS fast path sends only its canonical WordPress request.');
    $assert((new DateTimeImmutable($result->scanStartedAt))->format(DATE_ATOM) === $result->scanStartedAt, 'Result supplies a scan-start timestamp for watermark updates.');
    $options = $state->requests[0]['options'];
    $assert($options['retry_attempts'] === 1 && $options['retry_initial_delay_milliseconds'] === 0, 'Forum requests cannot enter Retry-After or retry sleeps.');
    $assert($options['follow_redirects'] === false && $options['max_redirects'] === 0, 'Forum request deadlines cannot multiply through redirects.');
    $assert($options['timeout_seconds'] === 10 && $options['connect_timeout_seconds'] === 5, 'Individual request and connect deadlines are bounded.');
    $assert($options['max_body_bytes'] === 2 * 1024 * 1024 && $options['allowed_redirect_hosts'] === ['wordpress.org'], 'RSS retains its byte and host policy.');
    $assert(count($state->progress) === 3 && str_contains($state->progress[0], 'requesting feed') && str_contains($state->progress[2], 'complete'), 'Progress describes request start, completion and scan summary.');

    [$client, $state] = $make([$completeFeed, $completeFeed], new SupportForumScanLimits(maxRequests: 1));
    $assert($client->scanTopicsOpenedAfter('example-plugin', $release)->complete, 'First request may use the per-plugin allowance.');
    $exhausted = $client->scanTopicsOpenedAfter('example-plugin', $release);
    $assert(! $exhausted->complete && $exhausted->requests === 0 && str_contains((string) $exhausted->warning, 'Request budget'), 'Another PR version cannot reset the same plugin request budget.');
    $assert($client->scanTopicsOpenedAfter('another-plugin', $release)->complete && count($state->requests) === 2, 'A separate plugin receives its own bounded support allowance.');

    [$client, $state] = $make([
        ['body' => $completeFeed, 'seconds' => 2.0], ['body' => $completeFeed, 'seconds' => 2.0], ['body' => $completeFeed, 'seconds' => 0.5],
    ], new SupportForumScanLimits(maxSeconds: 5, requestTimeoutSeconds: 4));
    $first = $client->scanTopicsOpenedAfter('example-plugin', $release);
    $state->now += 5000.0; // Unrelated dependency work is not charged to this source.
    $second = $client->scanTopicsOpenedAfter('example-plugin', $release);
    $third = $client->scanTopicsOpenedAfter('example-plugin', $release);
    $fourth = $client->scanTopicsOpenedAfter('example-plugin', $release);
    $assert($first->complete && $second->complete && $third->complete, 'Active scan time is cumulative without charging unrelated processing gaps.');
    $assert(array_column(array_column($state->requests, 'options'), 'timeout_seconds') === [4, 3, 1], 'Each request timeout shrinks with the shared active-time allowance.');
    $assert(! $fourth->complete && $fourth->requests === 0 && str_contains((string) $fourth->warning, 'time budget'), 'Less than one remaining second cannot start an integer-timeout HTTP request.');
    $assert($first->elapsedSeconds === 2.0 && $second->elapsedSeconds === 2.0 && $third->elapsedSeconds === 0.5, 'Result duration reports per-call active time.');
    [$client, $state] = $make([['body' => $completeFeed, 'seconds' => 5.0]], new SupportForumScanLimits(maxSeconds: 5));
    $late = $client->scanTopicsOpenedAfter('example-plugin', $release);
    $assert(! $late->complete && count($state->requests) === 1, 'A response arriving at the deadline cannot establish complete coverage.');

    [$client, $state] = $make([$emptyFeed, $listing(['one'], 2)], new SupportForumScanLimits(maxPages: 1));
    $pageLimited = $client->scanTopicsOpenedAfter('example-plugin', $release);
    $assert(! $pageLimited->complete && str_contains((string) $pageLimited->warning, 'page budget'), 'An advertised forum larger than the allowance remains incomplete.');
    $assert($pageLimited->requests === 2 && $pageLimited->pages === 1 && $pageLimited->topicsChecked === 0, 'Oversized forums stop after feed and first listing without topic requests.');
    [$client, $state] = $make([$emptyFeed, $listing(['one'], 2)], new SupportForumScanLimits(), 1);
    $assert(! $client->scanTopicsOpenedAfter('example-plugin', $release)->complete, 'The legacy constructor page limit remains effective alongside explicit budgets.');

    [$client, $state] = $make([$emptyFeed, $listing(['one', 'two']), $topic($newDate)], new SupportForumScanLimits(maxRequests: 3));
    $requestLimited = $client->scanTopicsOpenedAfter('example-plugin', $release);
    $assert(! $requestLimited->complete && $requestLimited->requests === 3 && count($state->requests) === 3, 'Request exhaustion stops before the next HTTP attempt.');
    $assert(array_column($requestLimited->topics, 'title') === ['one'], 'Incomplete results retain discovered topics for conservative merging.');
    [$client, $state] = $make([$emptyFeed, $listing(['one', 'two']), $topic($oldDate)], new SupportForumScanLimits(maxTopics: 1));
    $topicLimited = $client->scanTopicsOpenedAfter('example-plugin', $release);
    $assert(! $topicLimited->complete && $topicLimited->topicsChecked === 1 && str_contains((string) $topicLimited->warning, 'Topic page budget'), 'Old topics still consume topic requests and do not establish coverage of newer bumped listings.');

    [$client, $state] = $make([
        $emptyFeed, $listing(['old-bumped', 'one'], 2), $topic($oldDate), $topic($newDate),
        $listing(['old-bumped', 'two']), $topic($newDate),
    ]);
    $full = $client->scanTopicsOpenedAfter('example-plugin', $release);
    $assert($full->complete && $full->pages === 2 && $full->topicsChecked === 3 && $full->requests === 6, 'A full bounded crawl covers every page and deduplicates old and new topic URLs.');
    $assert(array_column($full->topics, 'title') === ['one', 'two'], 'A bumped old topic does not stop scanning remaining listings.');
    $assert($state->requests[4]['url'] === 'https://wordpress.org/support/plugin/example-plugin/page/2/', 'Later listing pages use canonical URLs.');
    $assert($state->requests[1]['options']['max_body_bytes'] === 3 * 1024 * 1024, 'Listing and topic responses retain their byte bound.');
    $assert(count(array_filter($state->progress, static fn (string $line): bool => str_contains($line, 'processing listing page'))) === 2, 'Every listing page emits visible crawl progress.');

    [$client, $state] = $make([$emptyFeed, $listing(['one']), $topic($newDate), $emptyFeed], new SupportForumScanLimits(maxPages: 1));
    $assert($client->scanTopicsOpenedAfter('example-plugin', $release)->complete, 'First bounded listing completes.');
    $repeatedPages = $client->scanTopicsOpenedAfter('example-plugin', $release);
    $assert(! $repeatedPages->complete && $repeatedPages->requests === 1 && $repeatedPages->pages === 0, 'Listing-page allowance is shared across PR windows for a plugin.');
    [$client, $state] = $make([$emptyFeed, $listing(['one']), $topic($newDate), $emptyFeed, $listing(['two'])], new SupportForumScanLimits(maxTopics: 1));
    $assert($client->scanTopicsOpenedAfter('example-plugin', $release)->complete, 'First bounded topic fetch completes.');
    $repeatedTopics = $client->scanTopicsOpenedAfter('example-plugin', $release);
    $assert(! $repeatedTopics->complete && $repeatedTopics->requests === 2 && $repeatedTopics->topicsChecked === 0, 'Topic-page allowance is shared across PR windows for a plugin.');

    [$client, $state] = $make([$feed(['new-topic' => $newDate]), new RuntimeException('Request failed https://wordpress.org/support/?token=inert-fixture-secret')]);
    $failedListing = $client->scanTopicsOpenedAfter('example-plugin', $release);
    $assert(! $failedListing->complete && array_column($failedListing->topics, 'title') === ['new-topic'], 'Listing failure retains RSS discoveries but never completes a partial window.');
    $assert(! str_contains((string) $failedListing->warning, 'inert-fixture-secret') && str_contains((string) $failedListing->warning, '[REDACTED]'), 'Nonfatal warnings redact transport credentials.');
    foreach (['<html><body>Temporary challenge</body></html>', ''] as $unrecognized) {
        [$client, $state] = $make([$emptyFeed, $unrecognized]);
        $invalidListing = $client->scanTopicsOpenedAfter('example-plugin', $release);
        $assert(! $invalidListing->complete && $invalidListing->requests === 2, 'Unrecognized or empty HTML cannot masquerade as a complete empty forum.');
    }
    foreach (['not XML', '<html><body>Temporary challenge</body></html>'] as $invalidFeed) {
        [$client, $state] = $make([$invalidFeed]);
        $assert(! $client->scanTopicsOpenedAfter('example-plugin', $release)->complete && count($state->requests) === 1, 'Malformed or non-RSS feed data is a bounded nonfatal warning.');
    }
    foreach (['title', 'link', 'pubDate'] as $missingField) {
        $malformedItem = '<item><title>unknown-topic</title><link>https://wordpress.org/support/topic/unknown-topic/</link><pubDate>' . $newDate . '</pubDate></item>';
        $malformedItem = preg_replace('~<' . $missingField . '>.*?</' . $missingField . '>~', '<' . $missingField . '></' . $missingField . '>', $malformedItem);
        $mixedFeed = str_replace('</channel>', $malformedItem . '</channel>', $feed(['old-topic' => $oldDate]));
        [$client, $state] = $make([$mixedFeed]);
        $mixed = $client->scanTopicsOpenedAfter('example-plugin', $release);
        $assert(! $mixed->complete && count($state->requests) === 1, 'An older valid RSS item cannot establish coverage when another item lacks ' . $missingField . '.');
    }
    foreach (['<html><body>No date</body></html>', $topic('not-a-date'), $topic(''), '<p class="bbp-topic-post-date"><a title=""></a></p>', ''] as $invalidTopic) {
        [$client, $state] = $make([$emptyFeed, $listing(['one']), $invalidTopic]);
        $assert(! $client->scanTopicsOpenedAfter('example-plugin', $release)->complete, 'Unknown, malformed or empty topic dates cannot establish complete coverage.');
    }
    [$client, $state] = $make([$emptyFeed, $listing(['one']), '<meta property="article:published_time" content=""><p class="bbp-topic-post-date"><a title="' . $newDate . '"></a></p>']);
    $fallbackDate = $client->scanTopicsOpenedAfter('example-plugin', $release);
    $assert($fallbackDate->complete && $fallbackDate->topics[0]['opened_at'] === '2026-09-24T00:00:00+00:00', 'An empty preferred timestamp may use a valid explicit fallback without inventing the current time.');
    [$client, $state] = $make([$emptyFeed, '<a class="bbp-topic-permalink" href="https://elsewhere.example/topic/">Other</a>']);
    $assert(! $client->scanTopicsOpenedAfter('example-plugin', $release)->complete && count($state->requests) === 2, 'A foreign topic URL is rejected before any request to it.');
    [$client, $state] = $make([$completeFeed]);
    $assert(count($client->fetchTopicsOpenedAfter('example-plugin', $release)) === 1, 'Legacy array-return API remains available for complete scans.');
    [$client, $state] = $make([new RuntimeException('Synthetic unavailable response')]);
    $legacyRejected = false;
    try {
        $client->fetchTopicsOpenedAfter('example-plugin', $release);
    } catch (RuntimeException $exception) {
        $legacyRejected = str_contains($exception->getMessage(), 'incomplete');
    }
    $assert($legacyRejected, 'Legacy callers receive an error rather than silently trusting partial coverage.');

    foreach (['maxPages' => 100, 'maxRequests' => 1000, 'maxTopics' => 1000, 'maxSeconds' => 300, 'requestTimeoutSeconds' => 30] as $name => $ceiling) {
        foreach ([0, $ceiling + 1] as $invalid) {
            $rejected = false;
            try {
                new SupportForumScanLimits(...[$name => $invalid]);
            } catch (RuntimeException) {
                $rejected = true;
            }
            $assert($rejected, 'Reject out-of-range support scan limit ' . $name . ': ' . $invalid);
        }
    }
}
