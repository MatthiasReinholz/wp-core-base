<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use DateTimeImmutable;
use DateTimeZone;

final class PrBodyRenderer
{
    /**
     * @param list<string> $labels
     * @param list<array{label:string, value:string}> $sourceDetails
     * @param list<array{title:string, url:string, opened_at:string}> $supportTopics
     * @param array<string, mixed> $metadata
     */
    public function renderDependencyUpdate(
        string $dependencyName,
        string $dependencySlug,
        string $dependencyKind,
        string $dependencyPath,
        string $currentVersion,
        string $targetVersion,
        string $releaseScope,
        string $releaseAt,
        array $labels,
        array $sourceDetails,
        string $releaseNotesHeading,
        string $releaseNotesBody,
        array $supportTopics,
        array $metadata,
    ): string {
        $releaseDate = new DateTimeImmutable($releaseAt);
        $blockedBy = $metadata['blocked_by'] ?? [];
        $blockedLine = $blockedBy === []
            ? 'None'
            : implode(', ', array_map(static fn (int $number): string => '#' . $number, $blockedBy));
        $labelLines = implode("\n", array_map(static fn (string $label): string => '- `' . $label . '`', $labels));
        $detailRows = implode("\n", array_map(static fn (array $row): string => sprintf('| %s | %s |', $row['label'], $row['value']), $sourceDetails));
        $supportHeading = $supportTopics === [] ? 'No support topics matched the release window.' : implode("\n", array_map(
            static fn (array $topic): string => sprintf('- [%s](%s)', $topic['title'], $topic['url']),
            $supportTopics
        ));
        $automationNote = match ($metadata['source'] ?? '') {
            'github-release' => 'This PR is managed by the GitHub release updater automation.',
            default => 'This PR is managed by the WordPress.org updater automation.',
        };

        return trim(<<<MARKDOWN
## Summary

| Field | Value |
| --- | --- |
| Dependency | `{$dependencyName}` |
| Slug | `{$dependencySlug}` |
| Kind | `{$dependencyKind}` |
| Path | `{$dependencyPath}` |
| Installed version on base branch | `{$currentVersion}` |
| Target version | `{$targetVersion}` |
| Release scope | `{$releaseScope}` |
| Release timestamp (UTC) | `{$releaseDate->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s \U\T\C')}` |
| Blocked by older update PRs | {$blockedLine} |
{$detailRows}

## Derived Labels

{$labelLines}

## {$releaseNotesHeading}

{$releaseNotesBody}

## Support Topics Opened After Release

{$supportHeading}

## Automation Notes

- {$automationNote}
- If a newer patch release lands on the same release line before merge, this PR will be updated in place.
- If a newer minor or major release lands before merge, the automation will open a separate blocked PR.

<!-- wporg-update-metadata: {$this->encodeMetadata($metadata)} -->
MARKDOWN);
    }

    /**
     * @param list<string> $labels
     * @param array<string, mixed> $metadata
     */
    public function renderCoreUpdate(
        string $currentVersion,
        string $targetVersion,
        string $releaseScope,
        string $releaseAt,
        array $labels,
        string $releaseUrl,
        string $downloadUrl,
        string $releaseHtml,
        array $metadata,
    ): string {
        $releaseDate = new DateTimeImmutable($releaseAt);
        $blockedBy = $metadata['blocked_by'] ?? [];
        $blockedLine = $blockedBy === []
            ? 'None'
            : implode(', ', array_map(static fn (int $number): string => '#' . $number, $blockedBy));
        $labelLines = implode("\n", array_map(static fn (string $label): string => '- `' . $label . '`', $labels));

        return trim(<<<MARKDOWN
## Summary

| Field | Value |
| --- | --- |
| Component | `WordPress core` |
| Installed version on base branch | `{$currentVersion}` |
| Target version | `{$targetVersion}` |
| Release scope | `{$releaseScope}` |
| Release timestamp (UTC) | `{$releaseDate->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s \U\T\C')}` |
| Release announcement | [Open]({$releaseUrl}) |
| Download package | [zip]({$downloadUrl}) |
| Blocked by older update PRs | {$blockedLine} |

## Derived Labels

{$labelLines}

## Release Notes

{$releaseHtml}

## Automation Notes

- This PR is managed by the WordPress core updater automation.
- If a newer patch release lands on the same release line before merge, this PR will be updated in place.
- If a newer minor or major release lands before merge, the automation will open a separate blocked PR.

<!-- wporg-update-metadata: {$this->encodeMetadata($metadata)} -->
MARKDOWN);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function extractMetadata(?string $body): ?array
    {
        if (! is_string($body) || $body === '') {
            return null;
        }

        if (preg_match('/<!--\s*wporg-update-metadata:\s*(\{.*\})\s*-->/', $body, $matches) !== 1) {
            return null;
        }

        $decoded = json_decode($matches[1], true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return list<array{title:string, url:string, opened_at:string}>
     */
    public static function extractSupportTopics(?string $body): array
    {
        if (! is_string($body) || $body === '') {
            return [];
        }

        if (preg_match('/## Support Topics Opened After Release\s+(.*?)\s+## Automation Notes/s', $body, $matches) !== 1) {
            return [];
        }

        $section = trim($matches[1]);

        if ($section === '' || str_contains($section, 'No support topics matched')) {
            return [];
        }

        if (preg_match_all('/^- \[(.+?)\]\((https?:\/\/[^\s)]+)\)$/m', $section, $topicMatches, PREG_SET_ORDER) === false) {
            return [];
        }

        $topics = [];

        foreach ($topicMatches as $topicMatch) {
            $topics[] = [
                'title' => trim(html_entity_decode($topicMatch[1], ENT_QUOTES | ENT_HTML5)),
                'url' => trim($topicMatch[2]),
                'opened_at' => '',
            ];
        }

        return $topics;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function encodeMetadata(array $metadata): string
    {
        return json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
