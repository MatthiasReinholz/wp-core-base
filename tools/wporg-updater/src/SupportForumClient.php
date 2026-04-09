<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use DateTimeImmutable;
use DOMDocument;
use DOMXPath;
use RuntimeException;
use SimpleXMLElement;

final class SupportForumClient
{
    private const MAX_FEED_BYTES = 2 * 1024 * 1024;
    private const MAX_PAGE_BYTES = 3 * 1024 * 1024;

    public function __construct(
        private readonly HttpClient $httpClient,
        private readonly int $maxPages,
    ) {
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
        $windowStart = $windowStart === null || $windowStart < $releaseAt ? $releaseAt : $windowStart;
        $topics = [];
        $feedItems = $this->parseFeed($this->httpClient->getWithOptions($this->feedUrl($slug), [], [
            'max_body_bytes' => self::MAX_FEED_BYTES,
            'allowed_redirect_hosts' => ['wordpress.org'],
        ]));

        foreach ($feedItems as $item) {
            $openedAt = new DateTimeImmutable($item['opened_at']);

            if ($openedAt > $windowStart) {
                $topics[$item['url']] = $item;
            }
        }

        if ($this->feedCoversReleaseWindow($feedItems, $windowStart)) {
            return $this->sortTopics($topics);
        }

        foreach ($this->crawlSupportPages($slug, $maxPages ?? $this->maxPages) as $listing) {
            if (isset($topics[$listing['url']])) {
                continue;
            }

            $topicHtml = $this->httpClient->getWithOptions($listing['url'], [], [
                'max_body_bytes' => self::MAX_PAGE_BYTES,
                'allowed_redirect_hosts' => ['wordpress.org'],
            ]);
            $openedAt = $this->extractTopicPublishedAt($topicHtml);

            if ($openedAt > $windowStart) {
                $topics[$listing['url']] = [
                    'title' => $listing['title'],
                    'url' => $listing['url'],
                    'opened_at' => $openedAt->format(DATE_ATOM),
                ];
            }
        }

        return $this->sortTopics($topics);
    }

    /**
     * @return list<array{title:string, url:string, opened_at:string}>
     */
    public function parseFeed(string $xml): array
    {
        $feed = simplexml_load_string($xml);

        if (! $feed instanceof SimpleXMLElement) {
            throw new RuntimeException('Failed to parse support feed XML.');
        }

        $items = [];

        foreach ($feed->channel->item as $item) {
            $title = trim(html_entity_decode(strip_tags((string) $item->title), ENT_QUOTES | ENT_HTML5));
            $url = $this->canonicalSupportTopicUrl((string) $item->link);
            $pubDate = trim((string) $item->pubDate);

            if ($title === '' || $url === '' || $pubDate === '') {
                continue;
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
        $document = new DOMDocument('1.0', 'UTF-8');
        @$document->loadHTML($html);

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
        $document = new DOMDocument('1.0', 'UTF-8');
        @$document->loadHTML($html);

        $xpath = new DOMXPath($document);
        $published = $xpath->query('//meta[@property="article:published_time"]/@content');

        if ($published !== false && $published->length > 0) {
            return new DateTimeImmutable(trim((string) $published->item(0)?->nodeValue));
        }

        $fallback = $xpath->query('//p[contains(@class, "bbp-topic-post-date")]/a/@title');

        if ($fallback !== false && $fallback->length > 0) {
            return new DateTimeImmutable(trim((string) $fallback->item(0)?->nodeValue));
        }

        throw new RuntimeException('Could not determine topic published time.');
    }

    /**
     * @return list<array{title:string, url:string}>
     */
    private function crawlSupportPages(string $slug, int $maxPages): array
    {
        $firstPageHtml = $this->httpClient->getWithOptions($this->supportUrl($slug), [], [
            'max_body_bytes' => self::MAX_PAGE_BYTES,
            'allowed_redirect_hosts' => ['wordpress.org'],
        ]);
        $pageCount = $this->extractPageCount($firstPageHtml);

        if ($pageCount > $maxPages) {
            throw new RuntimeException(sprintf(
                'Support forum for %s spans %d pages, which exceeds the configured limit of %d.',
                $slug,
                $pageCount,
                $maxPages
            ));
        }

        $topics = [];

        foreach ($this->parseSupportListing($firstPageHtml) as $topic) {
            $topics[$topic['url']] = $topic;
        }

        for ($page = 2; $page <= $pageCount; $page++) {
            $html = $this->httpClient->getWithOptions($this->supportUrl($slug) . 'page/' . $page . '/', [], [
                'max_body_bytes' => self::MAX_PAGE_BYTES,
                'allowed_redirect_hosts' => ['wordpress.org'],
            ]);

            foreach ($this->parseSupportListing($html) as $topic) {
                $topics[$topic['url']] = $topic;
            }
        }

        return array_values($topics);
    }

    private function extractPageCount(string $html): int
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        @$document->loadHTML($html);

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

        return $oldest !== null && $oldest <= $releaseAt;
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
        return sprintf('https://wordpress.org/support/plugin/%s/', rawurlencode($slug));
    }

    private function feedUrl(string $slug): string
    {
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
