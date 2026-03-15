<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use DOMDocument;
use DOMNode;
use DOMXPath;
use RuntimeException;

final class WordPressOrgClient
{
    public function __construct(private readonly HttpClient $httpClient)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchPluginInfo(string $slug): array
    {
        $query = http_build_query([
            'action' => 'plugin_information',
            'request' => [
                'slug' => $slug,
                'fields' => [
                    'sections' => 1,
                    'versions' => 1,
                ],
            ],
        ]);

        return $this->httpClient->getJson('https://api.wordpress.org/plugins/info/1.2/?' . $query);
    }

    public function latestVersion(array $pluginInfo): string
    {
        $version = $pluginInfo['version'] ?? null;

        if (! is_string($version) || $version === '') {
            throw new RuntimeException('Plugin info does not contain a latest version.');
        }

        return $version;
    }

    public function latestReleaseAt(array $pluginInfo): string
    {
        $value = $pluginInfo['last_updated'] ?? null;

        if (! is_string($value) || $value === '') {
            throw new RuntimeException('Plugin info does not contain last_updated.');
        }

        return (new \DateTimeImmutable($value))->format(DATE_ATOM);
    }

    public function downloadUrlForVersion(array $pluginInfo, string $version): string
    {
        $versions = $pluginInfo['versions'] ?? null;

        if (! is_array($versions)) {
            throw new RuntimeException('Plugin info does not contain version download links.');
        }

        $url = $versions[$version] ?? null;

        if (! is_string($url) || $url === '') {
            throw new RuntimeException(sprintf('No download URL found for version %s.', $version));
        }

        return $url;
    }

    public function extractChangelogSection(string $changelogHtml, string $targetVersion): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        @$document->loadHTML('<div id="root">' . $changelogHtml . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//*[@id="root"]/*');

        if ($nodes === false) {
            throw new RuntimeException('Failed to parse changelog HTML.');
        }

        $collecting = false;
        $buffer = '';

        /** @var DOMNode $node */
        foreach ($nodes as $node) {
            if ($node->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }

            $tagName = strtolower($node->nodeName);

            if (in_array($tagName, ['h2', 'h3', 'h4'], true)) {
                $headingText = trim(html_entity_decode($node->textContent ?? '', ENT_QUOTES | ENT_HTML5));

                if ($collecting && $headingText !== $targetVersion) {
                    break;
                }

                if ($headingText === $targetVersion) {
                    $collecting = true;
                }
            }

            if ($collecting) {
                $buffer .= $document->saveHTML($node);
            }
        }

        if ($buffer === '') {
            throw new RuntimeException(sprintf('Could not find changelog section for version %s.', $targetVersion));
        }

        return trim($buffer);
    }

    public function htmlToText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim((string) $text);
    }

    public function pluginUrl(string $slug): string
    {
        return sprintf('https://wordpress.org/plugins/%s/', rawurlencode($slug));
    }

    public function supportUrl(string $slug): string
    {
        return sprintf('https://wordpress.org/support/plugin/%s/', rawurlencode($slug));
    }
}
