<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use DateTimeImmutable;
use DOMDocument;
use DOMNode;
use DOMXPath;
use RuntimeException;

final class WordPressOrgClient implements WordPressOrgSource
{
    public function __construct(private readonly HttpClient $httpClient)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchComponentInfo(string $kind, string $slug): array
    {
        return match ($kind) {
            'plugin', 'mu-plugin-package' => $this->fetchPluginInfo($slug),
            'theme' => $this->fetchThemeInfo($slug),
            default => throw new RuntimeException(sprintf('Unsupported WordPress.org kind: %s', $kind)),
        };
    }

    public function latestVersion(string $kind, array $info): string
    {
        $version = $info['version'] ?? null;

        if (! is_string($version) || $version === '') {
            throw new RuntimeException(sprintf('%s info does not contain a latest version.', $kind));
        }

        return $version;
    }

    public function latestReleaseAt(string $kind, array $info): string
    {
        $value = $info['last_updated'] ?? null;

        if (! is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('%s info does not contain last_updated.', $kind));
        }

        return (new DateTimeImmutable($value))->format(DATE_ATOM);
    }

    public function downloadUrlForVersion(string $kind, array $info, string $version): string
    {
        if ($kind === 'theme') {
            $versions = $info['versions'] ?? null;

            if (is_array($versions)) {
                $url = $versions[$version] ?? null;

                if (is_string($url) && $url !== '') {
                    return $url;
                }
            }

            $downloadLink = $info['download_link'] ?? null;

            if (is_string($downloadLink) && $downloadLink !== '' && $version === $this->latestVersion($kind, $info)) {
                return $downloadLink;
            }

            throw new RuntimeException(sprintf('No download URL found for WordPress.org theme version %s.', $version));
        }

        $versions = $info['versions'] ?? null;

        if (! is_array($versions)) {
            throw new RuntimeException('Plugin info does not contain version download links.');
        }

        $url = $versions[$version] ?? null;

        if (! is_string($url) || $url === '') {
            throw new RuntimeException(sprintf('No download URL found for version %s.', $version));
        }

        return $url;
    }

    public function extractReleaseNotes(string $kind, array $info, string $targetVersion): string
    {
        $sections = $info['sections'] ?? null;
        $changelog = is_array($sections) ? (string) ($sections['changelog'] ?? '') : '';

        if ($changelog === '') {
            return sprintf('<p><em>Release notes unavailable for %s %s.</em></p>', $kind, htmlspecialchars($targetVersion, ENT_QUOTES));
        }

        try {
            return $this->extractChangelogSection($changelog, $targetVersion);
        } catch (RuntimeException) {
            return sprintf('<p><em>Release notes unavailable for version %s.</em></p>', htmlspecialchars($targetVersion, ENT_QUOTES));
        }
    }

    public function htmlToText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim((string) $text);
    }

    public function componentUrl(string $kind, string $slug): string
    {
        return match ($kind) {
            'plugin', 'mu-plugin-package' => sprintf('https://wordpress.org/plugins/%s/', rawurlencode($slug)),
            'theme' => sprintf('https://wordpress.org/themes/%s/', rawurlencode($slug)),
            default => throw new RuntimeException(sprintf('Unsupported WordPress.org kind: %s', $kind)),
        };
    }

    public function supportUrl(string $slug): string
    {
        return sprintf('https://wordpress.org/support/plugin/%s/', rawurlencode($slug));
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchPluginInfo(string $slug): array
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

    /**
     * @return array<string, mixed>
     */
    private function fetchThemeInfo(string $slug): array
    {
        $query = http_build_query([
            'action' => 'theme_information',
            'request' => [
                'slug' => $slug,
                'fields' => [
                    'sections' => 1,
                    'versions' => 1,
                ],
            ],
        ]);

        return $this->httpClient->getJson('https://api.wordpress.org/themes/info/1.2/?' . $query);
    }

    private function extractChangelogSection(string $changelogHtml, string $targetVersion): string
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
}
