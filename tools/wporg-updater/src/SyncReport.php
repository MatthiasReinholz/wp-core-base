<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class SyncReport
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_WARNING = 'warning';
    public const STATUS_FAILURE = 'failure';

    public const EXIT_SOURCE_WARNINGS = 3;

    private const ISSUE_TITLE = 'wp-core-base dependency source failures';
    private const ISSUE_LABEL = 'automation:source-failure';

    /**
     * @param list<string> $fatalErrors
     * @param list<string> $dependencyWarnings
     * @return array<string, mixed>
     */
    public static function build(array $fatalErrors, array $dependencyWarnings): array
    {
        $status = self::STATUS_SUCCESS;

        if ($fatalErrors !== []) {
            $status = self::STATUS_FAILURE;
        } elseif ($dependencyWarnings !== []) {
            $status = self::STATUS_WARNING;
        }

        return [
            'generated_at' => gmdate(DATE_ATOM),
            'status' => $status,
            'fatal_errors' => array_values($fatalErrors),
            'dependency_warnings' => array_values($dependencyWarnings),
            'warning_count' => count($dependencyWarnings),
            'fatal_error_count' => count($fatalErrors),
        ];
    }

    /**
     * @param array<string, mixed> $report
     */
    public static function write(array $report, string $path): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create sync report directory: %s', $directory));
        }

        $encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        if (file_put_contents($path, $encoded) === false) {
            throw new RuntimeException(sprintf('Unable to write sync report: %s', $path));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function read(string $path): array
    {
        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new RuntimeException(sprintf('Unable to read sync report: %s', $path));
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            throw new RuntimeException(sprintf('Sync report is not valid JSON: %s', $path));
        }

        return $decoded;
    }

    public static function exists(string $path): bool
    {
        return is_file($path);
    }

    /**
     * @param array<string, mixed> $report
     */
    public static function renderSummary(array $report): string
    {
        $status = (string) ($report['status'] ?? self::STATUS_FAILURE);
        $fatalErrors = self::stringList($report['fatal_errors'] ?? []);
        $dependencyWarnings = self::stringList($report['dependency_warnings'] ?? []);
        $generatedAt = (string) ($report['generated_at'] ?? '');

        $lines = [
            '## wp-core-base Sync Report',
            '',
            sprintf('- Status: `%s`', $status),
            sprintf('- Generated at: `%s`', $generatedAt === '' ? 'unknown' : $generatedAt),
            sprintf('- Dependency source warnings: `%d`', count($dependencyWarnings)),
            sprintf('- Fatal errors: `%d`', count($fatalErrors)),
            '',
        ];

        if ($dependencyWarnings !== []) {
            $lines[] = '### Dependency Source Warnings';
            $lines[] = '';

            foreach ($dependencyWarnings as $warning) {
                $lines[] = '- ' . $warning;
            }

            $lines[] = '';
        }

        if ($fatalErrors !== []) {
            $lines[] = '### Fatal Errors';
            $lines[] = '';

            foreach ($fatalErrors as $error) {
                $lines[] = '- ' . $error;
            }

            $lines[] = '';
        }

        if ($dependencyWarnings === [] && $fatalErrors === []) {
            $lines[] = 'No dependency-source warnings or fatal sync errors were reported.';
        }

        return rtrim(implode("\n", $lines)) . "\n";
    }

    /**
     * @return array<string, array{color:string, description:string}>
     */
    public static function issueLabelDefinitions(): array
    {
        return [
            self::ISSUE_LABEL => [
                'color' => 'b60205',
                'description' => 'Open dependency-source failures detected during wp-core-base sync',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $report
     */
    public static function syncIssue(GitHubClient $gitHubClient, array $report, ?string $runUrl = null): void
    {
        $warnings = self::stringList($report['dependency_warnings'] ?? []);
        $status = (string) ($report['status'] ?? self::STATUS_FAILURE);
        $openIssues = self::matchingOpenIssues($gitHubClient);

        if ($warnings === []) {
            if ($status !== self::STATUS_SUCCESS) {
                return;
            }

            foreach ($openIssues as $issue) {
                $gitHubClient->closeIssue(
                    (int) $issue['number'],
                    'Dependency-source failures cleared in the latest wp-core-base sync run.'
                );
            }

            return;
        }

        $gitHubClient->ensureLabels(self::issueLabelDefinitions());
        $body = self::renderIssueBody($report, $runUrl);
        $canonicalIssue = array_shift($openIssues);

        if ($canonicalIssue === null) {
            $gitHubClient->createIssue(self::ISSUE_TITLE, $body, [self::ISSUE_LABEL]);
            return;
        }

        $gitHubClient->updateIssue((int) $canonicalIssue['number'], self::ISSUE_TITLE, $body);
        $gitHubClient->setLabels((int) $canonicalIssue['number'], [self::ISSUE_LABEL]);

        foreach ($openIssues as $duplicateIssue) {
            $gitHubClient->closeIssue(
                (int) $duplicateIssue['number'],
                'A newer canonical dependency-source failure issue is already open. Closing this duplicate issue.'
            );
        }
    }

    /**
     * @param array<string, mixed> $report
     */
    private static function renderIssueBody(array $report, ?string $runUrl = null): string
    {
        $warnings = self::stringList($report['dependency_warnings'] ?? []);
        $generatedAt = (string) ($report['generated_at'] ?? '');
        $lines = [
            '## Summary',
            '',
            'One or more managed dependency sources failed during the latest `wp-core-base` sync run.',
            'Healthy dependencies may still have been updated successfully.',
            '',
            sprintf('- Generated at: `%s`', $generatedAt === '' ? 'unknown' : $generatedAt),
            sprintf('- Warning count: `%d`', count($warnings)),
        ];

        if (is_string($runUrl) && $runUrl !== '') {
            $lines[] = sprintf('- Workflow run: [Open](%s)', $runUrl);
        }

        $lines[] = '';
        $lines[] = '## Failing Sources';
        $lines[] = '';

        foreach ($warnings as $warning) {
            $lines[] = '- ' . $warning;
        }

        $lines[] = '';
        $lines[] = '## Notes';
        $lines[] = '';
        $lines[] = '- This issue is managed automatically by `wp-core-base`.';
        $lines[] = '- The issue will be updated while failures persist and closed automatically once sync runs clean.';

        return implode("\n", $lines);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function matchingOpenIssues(GitHubClient $gitHubClient): array
    {
        $issues = [];

        foreach ($gitHubClient->listOpenIssues(self::ISSUE_LABEL) as $issue) {
            if ((string) ($issue['title'] ?? '') !== self::ISSUE_TITLE) {
                continue;
            }

            $issues[] = $issue;
        }

        usort($issues, static fn (array $left, array $right): int => ((int) $left['number'] <=> (int) $right['number']));

        return $issues;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map('strval', array_filter($value, static fn (mixed $item): bool => is_string($item) && $item !== '')));
    }
}
