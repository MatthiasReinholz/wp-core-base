<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use DateTimeImmutable;
use DOMDocument;
use DOMXPath;
use RuntimeException;
use SimpleXMLElement;

final class WordPressCoreClient
{
    public function __construct(private readonly HttpClient $httpClient)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchLatestStableRelease(): array
    {
        $payload = $this->httpClient->getJson('https://api.wordpress.org/core/version-check/1.7/?locale=en_US');
        $candidate = $this->parseLatestStableOffer($payload);

        return array_merge($candidate, $this->findReleaseAnnouncement((string) $candidate['version']));
    }

    /**
     * @param array<string, mixed>|null $latestRelease
     * @return array<string, mixed>
     */
    public function releaseForVersion(string $version, ?array $latestRelease = null): array
    {
        if ($latestRelease !== null && ($latestRelease['version'] ?? null) === $version) {
            return $latestRelease;
        }

        return array_merge(
            [
                'version' => $version,
                'download_url' => sprintf('https://downloads.wordpress.org/release/wordpress-%s.zip', $version),
                'packages' => [],
            ],
            $this->findReleaseAnnouncement($version)
        );
    }

    /**
     * @return array{title:string, release_at:string, release_url:string, release_html:string, release_text:string}
     */
    public function findReleaseAnnouncement(string $version): array
    {
        $xml = $this->httpClient->get('https://wordpress.org/news/category/releases/feed/');
        return $this->findReleaseAnnouncementInFeed($xml, $version);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function parseLatestStableOffer(array $payload): array
    {
        $offers = $payload['offers'] ?? null;

        if (! is_array($offers)) {
            throw new RuntimeException('WordPress core version-check response did not contain offers.');
        }

        $candidate = null;

        foreach ($offers as $offer) {
            if (! is_array($offer)) {
                continue;
            }

            $version = $offer['current'] ?? null;
            $downloadUrl = $offer['packages']['full'] ?? null;

            if (
                ! is_string($version) ||
                ! preg_match('/^\d+(?:\.\d+)+$/', $version) ||
                ! is_string($downloadUrl) ||
                $downloadUrl === ''
            ) {
                continue;
            }

            if ($candidate === null || version_compare($version, (string) $candidate['version'], '>')) {
                $candidate = [
                    'version' => $version,
                    'download_url' => $downloadUrl,
                    'packages' => is_array($offer['packages'] ?? null) ? $offer['packages'] : [],
                ];
            }
        }

        if ($candidate === null) {
            throw new RuntimeException('Could not find a stable WordPress core offer.');
        }

        return $candidate;
    }

    /**
     * @return array{title:string, release_at:string, release_url:string, release_html:string, release_text:string}
     */
    public function findReleaseAnnouncementInFeed(string $xml, string $version): array
    {
        $feed = simplexml_load_string($xml);

        if (! $feed instanceof SimpleXMLElement) {
            throw new RuntimeException('Failed to parse the official WordPress releases feed.');
        }

        foreach ($feed->channel->item as $item) {
            $title = trim((string) $item->title);

            if (! str_contains($title, 'WordPress ' . $version)) {
                continue;
            }

            $content = (string) $item->children('http://purl.org/rss/1.0/modules/content/')->encoded;
            $summaryHtml = $this->summarizeReleaseHtml($content);

            return [
                'title' => $title,
                'release_at' => (new DateTimeImmutable((string) $item->pubDate))->format(DATE_ATOM),
                'release_url' => trim((string) $item->link),
                'release_html' => $summaryHtml,
                'release_text' => $this->htmlToText($summaryHtml),
            ];
        }

        throw new RuntimeException(sprintf('Could not find a release announcement for WordPress %s.', $version));
    }

    public function htmlToText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim((string) $text);
    }

    private function summarizeReleaseHtml(string $html): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        @$document->loadHTML('<div id="release-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//*[@id="release-root"]/*');

        if ($nodes === false || $nodes->length === 0) {
            throw new RuntimeException('Release announcement feed item did not contain parsable HTML.');
        }

        $buffer = '';
        $paragraphs = 0;

        foreach ($nodes as $node) {
            $tagName = strtolower($node->nodeName);

            if (! in_array($tagName, ['h2', 'h3', 'p', 'ul', 'ol'], true)) {
                continue;
            }

            $buffer .= $document->saveHTML($node);

            if ($tagName === 'p') {
                $paragraphs++;
            }

            if ($paragraphs >= 4) {
                break;
            }
        }

        return trim($buffer);
    }
}
