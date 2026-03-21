<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

final class ReleaseClassifier
{
    private const BUGFIX_KEYWORDS = [
        'fix',
        'fixed',
        'bug',
        'bugfix',
        'security',
        'vulnerability',
        'hardening',
        'patch',
        'regression',
        'fatal',
        'error',
        'compatibility',
        'sanitize',
        'escape',
        'csrf',
        'xss',
    ];

    private const FEATURE_KEYWORDS = [
        'add',
        'added',
        'feature',
        'new',
        'introduce',
        'introduces',
        'support for',
        'enhancement',
        'enhance',
    ];

    private const FORUM_SIGNAL_KEYWORDS = [
        'fatal',
        'error',
        'broken',
        'not working',
        'warning',
        'conflict',
        'security',
        'crash',
        'deprecated',
    ];

    public function classifyScope(string $fromVersion, string $toVersion): string
    {
        if (version_compare($toVersion, $fromVersion, '<=')) {
            return 'none';
        }

        $from = $this->numericSegments($fromVersion);
        $to = $this->numericSegments($toVersion);
        $max = max(count($from), count($to), 3);

        $from = array_pad($from, $max, 0);
        $to = array_pad($to, $max, 0);

        foreach ($to as $index => $segment) {
            if ($segment !== $from[$index]) {
                return match ($index) {
                    0 => 'major',
                    1 => 'minor',
                    default => 'patch',
                };
            }
        }

        return 'patch';
    }

    /**
     * @return list<string>
     */
    public function deriveLabels(string $sourceLabel, string $scope, string $changelogText, array $supportTopics): array
    {
        $labels = ['automation:dependency-update', $sourceLabel];

        if ($scope !== 'none') {
            $labels[] = 'release:' . $scope;
        }

        $normalizedChangelog = mb_strtolower($changelogText);

        if ($scope === 'patch') {
            $labels[] = 'type:security-bugfix';
        } else {
            if ($this->containsKeyword($normalizedChangelog, self::BUGFIX_KEYWORDS)) {
                $labels[] = 'type:security-bugfix';
            }

            if ($this->containsKeyword($normalizedChangelog, self::FEATURE_KEYWORDS)) {
                $labels[] = 'type:feature';
            }
        }

        if ($supportTopics !== []) {
            $labels[] = 'support:new-topics';

            $titles = implode("\n", array_map(static fn (array $topic): string => $topic['title'], $supportTopics));

            if ($this->containsKeyword(mb_strtolower($titles), self::FORUM_SIGNAL_KEYWORDS)) {
                $labels[] = 'support:regression-signal';
            }
        }

        $labels = array_values(array_unique($labels));
        sort($labels);

        return $labels;
    }

    public function samePatchLine(string $versionA, string $versionB): bool
    {
        return $this->releaseLineKey($versionA) === $this->releaseLineKey($versionB);
    }

    public function releaseLineKey(string $version): string
    {
        $segments = array_pad($this->numericSegments($version), 2, 0);
        return sprintf('%d.%d', $segments[0], $segments[1]);
    }

    /**
     * @return list<int>
     */
    private function numericSegments(string $version): array
    {
        preg_match_all('/\d+/', $version, $matches);

        return array_map(static fn (string $value): int => (int) $value, $matches[0]);
    }

    /**
     * @param list<string> $keywords
     */
    private function containsKeyword(string $haystack, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
