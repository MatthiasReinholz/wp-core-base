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
        if (($metadata['support_scan_complete'] ?? true) === false) {
            $supportHeading = "Support scan incomplete; coverage of the release window is not established. " .
                "Previously observed topics and support labels are retained and may include earlier release observations.\n\n" .
                ($supportTopics === [] ? 'No topics are available from this partial scan; this does not establish that no new issues exist.' : $supportHeading);
        }
        $provenanceRows = $this->provenanceRows($metadata);
        $reviewerWarning = $this->reviewerWarning($metadata);
        $automationNote = match ($metadata['source'] ?? '') {
            'github-release' => 'This PR is managed by the GitHub release updater automation.',
            'gitlab-release' => 'This PR is managed by the GitLab release updater automation.',
            'generic-json' => 'This PR is managed by the generic JSON metadata updater automation.',
            'premium' => 'This PR is managed by the premium provider updater automation.',
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

## Artifact Provenance

| Field | Value |
| --- | --- |
{$provenanceRows}

## Automation Notes

- {$automationNote}
- {$reviewerWarning}
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
        $provenanceRows = $this->provenanceRows($metadata);
        $reviewerWarning = $this->reviewerWarning($metadata);

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

## Artifact Provenance

| Field | Value |
| --- | --- |
{$provenanceRows}

## Automation Notes

- This PR is managed by the WordPress core updater automation.
- {$reviewerWarning}
- If a newer patch release lands on the same release line before merge, this PR will be updated in place.
- If a newer minor or major release lands before merge, the automation will open a separate blocked PR.

<!-- wporg-update-metadata: {$this->encodeMetadata($metadata)} -->
MARKDOWN);
    }

    /**
     * @param list<string> $labels
     * @param array<string, string> $notesSections
     * @param list<string> $skippedManagedFiles
     * @param array<string, mixed> $metadata
     */
    public function renderFrameworkUpdate(
        string $currentVersion,
        string $targetVersion,
        string $releaseScope,
        string $releaseAt,
        array $labels,
        string $sourceReferenceLabel,
        string $sourceReference,
        string $sourceReferenceUrl,
        string $releaseUrl,
        string $currentBaseline,
        string $targetBaseline,
        array $notesSections,
        array $skippedManagedFiles,
        array $metadata,
    ): string {
        $releaseDate = new DateTimeImmutable($releaseAt);
        $blockedBy = $metadata['blocked_by'] ?? [];
        $blockedLine = $blockedBy === []
            ? 'None'
            : implode(', ', array_map(static fn (int $number): string => '#' . $number, $blockedBy));
        $labelLines = implode("\n", array_map(static fn (string $label): string => '- `' . $label . '`', $labels));
        $sectionBlocks = [];

        foreach ($notesSections as $heading => $body) {
            $sectionBlocks[] = sprintf("## %s\n\n%s", $heading, trim($body) === '' ? '_No details provided._' : trim($body));
        }

        $driftSection = $skippedManagedFiles === []
            ? 'No managed scaffold files were skipped.'
            : implode("\n", array_map(static fn (string $path): string => sprintf('- `%s`', $path), $skippedManagedFiles));

        return trim(<<<MARKDOWN
## Summary

| Field | Value |
| --- | --- |
| Component | `wp-core-base` |
| {$sourceReferenceLabel} | [`{$sourceReference}`]({$sourceReferenceUrl}) |
| Release | [Open]({$releaseUrl}) |
| Installed version on base branch | `{$currentVersion}` |
| Target version | `{$targetVersion}` |
| Release scope | `{$releaseScope}` |
| Release timestamp (UTC) | `{$releaseDate->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s \U\T\C')}` |
| Bundled WordPress baseline on base branch | `{$currentBaseline}` |
| Bundled WordPress baseline after update | `{$targetBaseline}` |
| Blocked by older update PRs | {$blockedLine} |

## Derived Labels

{$labelLines}

{$this->joinMarkdownBlocks($sectionBlocks)}

## Scaffold Refresh Notes

{$driftSection}

## Automation Notes

- This PR is managed by the `wp-core-base` framework self-update automation.
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

        // Release notes may contain arbitrary comments, including incomplete
        // metadata lookalikes. Only the last opener can identify our footer;
        // never fall back to an earlier block if that final marker is malformed.
        $markerCount = preg_match_all('/<!--\s*wporg-update-metadata\b/', $body, $markers, PREG_OFFSET_CAPTURE);
        if ($markerCount === false || $markerCount === 0) {
            return null;
        }

        $lastMarker = $markers[0][$markerCount - 1];
        $payloadStart = $lastMarker[1] + strlen($lastMarker[0]);
        $commentEnd = self::metadataCommentEnd($body, $payloadStart);
        if ($commentEnd === null || preg_match('/\A\s*:\s*(\{.*\})\s*\z/s', substr($body, $payloadStart, $commentEnd - $payloadStart), $matches) !== 1) {
            return null;
        }
        $decoded = json_decode($matches[1], true);

        return is_array($decoded) ? $decoded : null;
    }

    private static function metadataCommentEnd(string $body, int $payloadStart): ?int
    {
        // Older renderers did not escape HTML delimiters in JSON string values.
        // Only a delimiter outside a quoted JSON string can close their footer.
        $inString = false;
        $escaped = false;
        $length = strlen($body);

        for ($offset = $payloadStart; $offset < $length; ++$offset) {
            $character = $body[$offset];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $inString = false;
                }
            } elseif ($character === '"') {
                $inString = true;
            } elseif ($character === '-' && substr($body, $offset, 3) === '-->') {
                return $offset;
            }
        }

        return null;
    }

    /**
     * @return list<array{title:string, url:string, opened_at:string}>
     */
    public static function extractSupportTopics(?string $body): array
    {
        if (! is_string($body) || $body === '') {
            return [];
        }

        // The generated section follows release notes, which can contain the
        // same heading. Select its final whole-line occurrence independently.
        $headingCount = preg_match_all('/^## Support Topics Opened After Release[ \t]*\r?$/m', $body, $headings, PREG_OFFSET_CAPTURE);
        if ($headingCount === false || $headingCount === 0) {
            return [];
        }
        $lastHeading = $headings[0][$headingCount - 1];
        $following = substr($body, $lastHeading[1] + strlen($lastHeading[0]));
        if (preg_match('/^##[ \t]+/m', $following, $nextHeading, PREG_OFFSET_CAPTURE) !== 1) {
            return [];
        }
        $section = trim(substr($following, 0, $nextHeading[0][1]));

        if ($section === '') {
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
        // Keep comment delimiters and marker-like values inside JSON strings
        // from being interpreted as additional metadata comments.
        return json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    }

    /**
     * @param list<string> $blocks
     */
    private function joinMarkdownBlocks(array $blocks): string
    {
        return implode("\n\n", array_values(array_filter($blocks, static fn (string $block): bool => trim($block) !== '')));
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function provenanceRows(array $metadata): string
    {
        $state = DependencyTrustState::normalize((string) ($metadata['trust_state'] ?? DependencyTrustState::METADATA_ONLY));
        $details = trim((string) ($metadata['trust_details'] ?? 'Archive authenticity was not independently verified.'));

        if ($details === '') {
            $details = 'Archive authenticity was not independently verified.';
        }

        return implode("\n", [
            sprintf('| Trust state | `%s` |', $state),
            sprintf('| Provenance details | %s |', $details),
        ]);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function reviewerWarning(array $metadata): string
    {
        return DependencyTrustState::reviewerWarning((string) ($metadata['trust_state'] ?? DependencyTrustState::METADATA_ONLY));
    }
}
