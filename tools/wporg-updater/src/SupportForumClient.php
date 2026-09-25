<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use Closure;
use DateTimeImmutable;
use DOMDocument;
use DOMXPath;
use Exception;
use RuntimeException;
use SimpleXMLElement;

final class SupportForumClient
{
    private const MAX_FEED_BYTES = 2 * 1024 * 1024;
    private const MAX_PAGE_BYTES = 3 * 1024 * 1024;

    private readonly SupportForumScanLimits $limits;
    private readonly int $maxPages;
    /** @var Closure(string,array<string,mixed>):string */
    private readonly Closure $request;
    /** @var Closure():float */
    private readonly Closure $clock;
    /** @var array<string,array{requests:int,pages:int,topics:int,elapsed:float}> */
    private array $spentBySlug = [];

    /**
     * @param Closure(string):void|null $progress
     * @param Closure(string,array<string,mixed>):string|null $request Optional transport seam; production uses HttpClient.
     * @param Closure():float|null $clock Optional monotonic clock seam, in seconds.
     */
    public function __construct(
        HttpClient $httpClient,
        int $maxPages,
        ?SupportForumScanLimits $limits = null,
        private readonly ?Closure $progress = null,
        ?Closure $request = null,
        ?Closure $clock = null,
    ) {
        $this->limits = $limits ?? new SupportForumScanLimits(maxPages: $maxPages);
        if ($maxPages < 1 || $maxPages > 100) {
            throw new RuntimeException('Support forum max_pages must be between 1 and 100.');
        }
        $this->maxPages = min($maxPages, $this->limits->maxPages);
        $this->request = $request ?? static fn (string $url, array $options): string => $httpClient->getWithOptions($url, [], $options);
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1_000_000_000;
    }

    /**
     * @return list<array{title:string, url:string, opened_at:string}>
     */
    public function fetchTopicsOpenedAfter(
        string $slug,
        DateTimeImmutable $releaseAt,
        ?DateTimeImmutable $windowStart = null,
        ?int $maxPages = null,
    ): array
    {
        $result = $this->scanTopicsOpenedAfter($slug, $releaseAt, $windowStart, $maxPages);
        if (! $result->complete) {
            throw new RuntimeException($result->warning ?? 'Support forum scan is incomplete.');
        }
        return $result->topics;
    }

    public function scanTopicsOpenedAfter(
        string $slug,
        DateTimeImmutable $releaseAt,
        ?DateTimeImmutable $windowStart = null,
        ?int $maxPages = null,
    ): SupportForumScanResult {
        WordPressOrgSlugValidator::assertValid($slug);
        if ($maxPages !== null && ($maxPages < 1 || $maxPages > 100)) {
            throw new RuntimeException('Support forum max_pages must be between 1 and 100.');
        }
        $pageLimit = min($this->maxPages, $maxPages ?? $this->maxPages);
        $this->spentBySlug[$slug] ??= ['requests' => 0, 'pages' => 0, 'topics' => 0, 'elapsed' => 0.0];
        $budget = &$this->spentBySlug[$slug];
        $before = $budget;
        $started = ($this->clock)();
        $scanStartedAt = (new DateTimeImmutable())->format(DATE_ATOM);
        $deadline = $started + max(0, $this->limits->maxSeconds - $budget['elapsed']);
        $windowStart = $windowStart === null || $windowStart < $releaseAt ? $releaseAt : $windowStart;
        $topics = [];
        $complete = false;
        $warning = null;
        try {
            $feedItems = $this->parseFeed($this->fetch($slug, $this->feedUrl($slug), 'feed', $budget, $deadline, $pageLimit));
            foreach ($feedItems as $item) {
                $this->assertTimeRemaining($deadline);
                if (new DateTimeImmutable($item['opened_at']) > $windowStart) {
                    $topics[$item['url']] = $item;
                }
            }
            if (! $this->feedCoversReleaseWindow($feedItems, $windowStart)) {
                $this->crawlSupportPages($slug, $windowStart, $topics, $budget, $deadline, $pageLimit);
            }
            $this->assertTimeRemaining($deadline);
            $complete = true;
        } catch (Exception $exception) {
            $warning = OutputRedactor::redact(sprintf('Support scan for %s is incomplete: %s', $slug, $exception->getMessage()));
        } finally {
            $elapsed = max(0.0, ($this->clock)() - $started);
            $budget['elapsed'] += $elapsed;
        }
        $result = new SupportForumScanResult($this->sortTopics($topics), $complete, $warning,
            $budget['requests'] - $before['requests'], $budget['pages'] - $before['pages'], $budget['topics'] - $before['topics'],
            $elapsed, $scanStartedAt);
        $this->reportProgress(sprintf('Support scan %s: %s; %d requests, %d listing pages, %d topic pages, %.1fs active time (%.1fs/%ds per-plugin budget).',
            $slug, $complete ? 'complete' : 'incomplete', $result->requests, $result->pages, $result->topicsChecked,
            $elapsed, $budget['elapsed'], $this->limits->maxSeconds));
        return $result;
    }

    /**
     * @return list<array{title:string, url:string, opened_at:string}>
     */
    public function parseFeed(string $xml): array
    {
        $feed = @simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);

        if (! $feed instanceof SimpleXMLElement || $feed->getName() !== 'rss' || ! isset($feed->channel)) {
            throw new RuntimeException('Failed to parse support feed XML.');
        }

        $items = [];

        foreach ($feed->channel->item as $item) {
            $title = trim(html_entity_decode(strip_tags((string) $item->title), ENT_QUOTES | ENT_HTML5));
            $url = $this->canonicalSupportTopicUrl((string) $item->link);
            $pubDate = trim((string) $item->pubDate);

            if ($title === '' || $url === '' || $pubDate === '') {
                throw new RuntimeException('Support feed contains an item without a title, topic URL or published timestamp.');
            }

            $items[] = [
                'title' => $title,
                'url' => $url,
                'opened_at' => (new DateTimeImmutable($pubDate))->format(DATE_ATOM),
            ];
        }

        return $items;
    }

    /**
     * @return list<array{title:string, url:string}>
     */
    public function parseSupportListing(string $html): array
    {
        if (trim($html) === '') {
            throw new RuntimeException('Support listing response is empty.');
        }
        $document = new DOMDocument('1.0', 'UTF-8');
        @$document->loadHTML($html, LIBXML_NONET);

        $xpath = new DOMXPath($document);
        $links = $xpath->query('//a[contains(@class, "bbp-topic-permalink")]');

        if ($links === false) {
            throw new RuntimeException('Failed to parse support listing.');
        }

        $topics = [];

        foreach ($links as $link) {
            $title = trim(html_entity_decode(strip_tags($link->textContent ?? ''), ENT_QUOTES | ENT_HTML5));
            $url = $this->canonicalSupportTopicUrl((string) $link->attributes?->getNamedItem('href')?->nodeValue);

            if ($title === '' || $url === '') {
                continue;
            }

            $topics[$url] = [
                'title' => $title,
                'url' => $url,
            ];
        }

        return array_values($topics);
    }

    public function extractTopicPublishedAt(string $html): DateTimeImmutable
    {
        if (trim($html) === '') {
            throw new RuntimeException('Support topic response is empty.');
        }
        $document = new DOMDocument('1.0', 'UTF-8');
        @$document->loadHTML($html, LIBXML_NONET);

        $xpath = new DOMXPath($document);
        $published = $xpath->query('//meta[@property="article:published_time"]/@content');

        if ($published !== false && $published->length > 0) {
            $value = trim((string) $published->item(0)?->nodeValue);
            if ($value !== '') {
                return new DateTimeImmutable($value);
            }
        }

        $fallback = $xpath->query('//p[contains(@class, "bbp-topic-post-date")]/a/@title');

        if ($fallback !== false && $fallback->length > 0) {
            $value = trim((string) $fallback->item(0)?->nodeValue);
            if ($value !== '') {
                return new DateTimeImmutable($value);
            }
        }

        throw new RuntimeException('Could not determine topic published time.');
    }

    /**
     * @param array<string,array{title:string,url:string,opened_at:string}> $topics
     * @param array{requests:int,pages:int,topics:int,elapsed:float} $budget
     */
    private function crawlSupportPages(string $slug, DateTimeImmutable $windowStart, array &$topics, array &$budget, float $deadline, int $maxPages): void
    {
        $remainingPages = $maxPages - $budget['pages'];
        $firstPageHtml = $this->fetch($slug, $this->supportUrl($slug), 'listing', $budget, $deadline, $maxPages);
        $pageCount = $this->extractPageCount($firstPageHtml);

        if ($pageCount > $remainingPages) {
            throw new RuntimeException(sprintf(
                'Support forum for %s spans %d pages, which exceeds the remaining page budget of %d (configured limit %d).',
                $slug,
                $pageCount,
                $remainingPages,
                $maxPages
            ));
        }

        $seen = array_fill_keys(array_keys($topics), true);
        for ($page = 1; $page <= $pageCount; $page++) {
            $this->reportProgress(sprintf('Support scan %s: processing listing page %d/%d.', $slug, $page, $pageCount));
            $html = $page === 1 ? $firstPageHtml : $this->fetch($slug, $this->supportUrl($slug) . 'page/' . $page . '/', 'listing', $budget, $deadline, $maxPages);
            $listings = $this->parseSupportListing($html);
            if ($listings === []) {
                // An HTML challenge or changed selector is not proof that the
                // forum has no topics. Preserve the previous coverage window.
                throw new RuntimeException('Support listing did not contain recognizable topics; coverage could not be established.');
            }
            foreach ($listings as $topic) {
                $this->assertTimeRemaining($deadline);
                if (isset($seen[$topic['url']])) {
                    continue;
                }
                $seen[$topic['url']] = true;
                $topicHtml = $this->fetch($slug, $topic['url'], 'topic', $budget, $deadline, $maxPages);
                $openedAt = $this->extractTopicPublishedAt($topicHtml);
                if ($openedAt > $windowStart) {
                    $topics[$topic['url']] = ['title' => $topic['title'], 'url' => $topic['url'], 'opened_at' => $openedAt->format(DATE_ATOM)];
                }
            }
        }
    }

    /** @param array{requests:int,pages:int,topics:int,elapsed:float} $budget */
    private function fetch(string $slug, string $url, string $kind, array &$budget, float $deadline, int $maxPages): string
    {
        $this->assertTimeRemaining($deadline);
        if ($budget['requests'] >= $this->limits->maxRequests) {
            throw new RuntimeException(sprintf('Request budget of %d is exhausted.', $this->limits->maxRequests));
        }
        if ($kind === 'listing' && $budget['pages'] >= $maxPages) {
            throw new RuntimeException(sprintf('Listing page budget of %d is exhausted.', $maxPages));
        }
        if ($kind === 'topic' && $budget['topics'] >= $this->limits->maxTopics) {
            throw new RuntimeException(sprintf('Topic page budget of %d is exhausted.', $this->limits->maxTopics));
        }
        $this->reportProgress(sprintf('Support scan %s: requesting %s (%d/%d requests used).', $slug, $kind, $budget['requests'], $this->limits->maxRequests));
        // One attempt and no redirects ensure cURL's timeout bounds the entire
        // request; Retry-After and per-hop timeouts cannot multiply the budget.
        $timeout = min($this->limits->requestTimeoutSeconds, (int) floor($deadline - ($this->clock)()));
        if ($timeout < 1) {
            throw new RuntimeException(sprintf('Active scan time budget of %d seconds is exhausted.', $this->limits->maxSeconds));
        }
        ++$budget['requests'];
        if ($kind === 'listing') {
            ++$budget['pages'];
        } elseif ($kind === 'topic') {
            ++$budget['topics'];
        }
        $body = ($this->request)($url, [
            'max_body_bytes' => $kind === 'feed' ? self::MAX_FEED_BYTES : self::MAX_PAGE_BYTES,
            'allowed_redirect_hosts' => ['wordpress.org'],
            'retry_attempts' => 1,
            'retry_initial_delay_milliseconds' => 0,
            'follow_redirects' => false,
            'max_redirects' => 0,
            'timeout_seconds' => $timeout,
            'connect_timeout_seconds' => min(5, $timeout),
        ]);
        $this->assertTimeRemaining($deadline);
        $this->reportProgress(sprintf('Support scan %s: received %s (%d/%d requests used).', $slug, $kind, $budget['requests'], $this->limits->maxRequests));
        return $body;
    }

    private function assertTimeRemaining(float $deadline): void
    {
        if (($this->clock)() >= $deadline) {
            throw new RuntimeException(sprintf('Active scan time budget of %d seconds is exhausted.', $this->limits->maxSeconds));
        }
    }

    private function reportProgress(string $message): void
    {
        if ($this->progress !== null) {
            ($this->progress)($message);
        }
    }

    private function extractPageCount(string $html): int
    {
        if (trim($html) === '') {
            throw new RuntimeException('Support listing response is empty.');
        }
        $document = new DOMDocument('1.0', 'UTF-8');
        @$document->loadHTML($html, LIBXML_NONET);

        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//div[contains(@class, "bbp-pagination-links")]//a[contains(@class, "page-numbers")]');

        if ($nodes === false || $nodes->length === 0) {
            return 1;
        }

        $pageCount = 1;

        foreach ($nodes as $node) {
            $value = trim($node->textContent ?? '');

            if (ctype_digit($value)) {
                $pageCount = max($pageCount, (int) $value);
            }
        }

        return $pageCount;
    }

    /**
     * @param list<array{title:string, url:string, opened_at:string}> $feedItems
     */
    private function feedCoversReleaseWindow(array $feedItems, DateTimeImmutable $releaseAt): bool
    {
        if ($feedItems === []) {
            return false;
        }

        $oldest = null;

        foreach ($feedItems as $item) {
            $openedAt = new DateTimeImmutable($item['opened_at']);
            $oldest = $oldest === null || $openedAt < $oldest ? $openedAt : $oldest;
        }

        return $oldest <= $releaseAt;
    }

    /**
     * @param array<string, array{title:string, url:string, opened_at:string}> $topics
     * @return list<array{title:string, url:string, opened_at:string}>
     */
    private function sortTopics(array $topics): array
    {
        $sorted = array_values($topics);

        usort($sorted, static function (array $left, array $right): int {
            return strcmp($right['opened_at'], $left['opened_at']);
        });

        return $sorted;
    }

    private function supportUrl(string $slug): string
    {
        WordPressOrgSlugValidator::assertValid($slug);

        return sprintf('https://wordpress.org/support/plugin/%s/', rawurlencode($slug));
    }

    private function feedUrl(string $slug): string
    {
        WordPressOrgSlugValidator::assertValid($slug);

        return sprintf('https://wordpress.org/support/plugin/%s/feed/', rawurlencode($slug));
    }

    private function canonicalSupportTopicUrl(string $url): string
    {
        $trimmed = trim($url);

        if ($trimmed === '') {
            return '';
        }

        $parts = parse_url($trimmed);

        if (! is_array($parts)) {
            throw new RuntimeException(sprintf('Support topic URL is invalid: %s', $url));
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        if ($scheme !== 'https' || $host !== 'wordpress.org' || ! str_starts_with($path, '/support/')) {
            throw new RuntimeException(sprintf('Support topic URL must stay on wordpress.org/support: %s', $url));
        }

        return 'https://wordpress.org' . $path;
    }
}
