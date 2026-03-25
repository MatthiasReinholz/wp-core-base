<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class ExtractedPayloadLocator
{
    public static function locateForAuthoring(
        string $extractPath,
        string $archiveSubdir,
        string $slug,
        string $kind,
        DependencyMetadataResolver $metadataResolver,
        ?string $mainFile = null,
    ): string {
        $matches = [];

        foreach (self::candidateBases($extractPath) as $candidateBase) {
            $candidatePath = self::candidatePath($candidateBase, $archiveSubdir);

            if (! file_exists($candidatePath)) {
                continue;
            }

            try {
                if (in_array($kind, ['mu-plugin-file', 'runtime-file'], true)) {
                    if (! is_file($candidatePath)) {
                        continue;
                    }

                    $matches[] = [
                        'path' => $candidatePath,
                        'score' => self::scoreFileCandidate($candidatePath, $slug),
                    ];
                    continue;
                }

                if (! is_dir($candidatePath)) {
                    continue;
                }

                $resolvedMainFile = $metadataResolver->resolveMainFile($candidatePath, $kind, $mainFile);
                $matches[] = [
                    'path' => $candidatePath,
                    'score' => self::scoreDirectoryCandidate($candidatePath, $resolvedMainFile, $slug, $extractPath),
                ];
            } catch (RuntimeException) {
                continue;
            }
        }

        return self::resolveSingleMatch(
            $matches,
            sprintf('Could not locate the extracted dependency payload for %s.', $slug),
            sprintf('Extracted archive for %s matched multiple candidate dependency payloads.', $slug),
        );
    }

    public static function locateByExpectedEntry(
        string $extractPath,
        string $archiveSubdir,
        string $expectedEntry,
        string $slug,
        bool $isFile,
    ): string {
        $matches = [];

        foreach (self::candidateBases($extractPath) as $candidateBase) {
            $candidatePath = self::candidatePath($candidateBase, $archiveSubdir);

            if ($isFile) {
                $candidateFile = rtrim($candidatePath, '/') . '/' . trim($expectedEntry, '/');

                if (is_file($candidateFile)) {
                    $matches[] = [
                        'path' => $candidateFile,
                        'score' => self::scoreFileCandidate($candidateFile, $slug),
                    ];
                }

                continue;
            }

            if (! is_file(rtrim($candidatePath, '/') . '/' . trim($expectedEntry, '/'))) {
                continue;
            }

            $matches[] = [
                'path' => $candidatePath,
                'score' => self::scoreDirectoryCandidate($candidatePath, $expectedEntry, $slug, $extractPath),
            ];
        }

        return self::resolveSingleMatch(
            $matches,
            sprintf(
                'Could not locate the extracted dependency payload for %s. Expected to find %s inside the archive.',
                $slug,
                $expectedEntry
            ),
            sprintf('Extracted archive for %s matched multiple candidate dependency payloads.', $slug),
        );
    }

    /**
     * @return list<string>
     */
    private static function candidateBases(string $extractPath): array
    {
        $entries = array_values(array_filter(scandir($extractPath) ?: [], static fn (string $entry): bool => $entry !== '.' && $entry !== '..'));
        $candidateBases = [$extractPath];

        foreach ($entries as $entry) {
            $candidate = $extractPath . '/' . $entry;

            if (is_dir($candidate)) {
                $candidateBases[] = $candidate;
            }
        }

        return $candidateBases;
    }

    private static function candidatePath(string $candidateBase, string $archiveSubdir): string
    {
        if ($archiveSubdir === '') {
            return $candidateBase;
        }

        return rtrim($candidateBase, '/') . '/' . trim($archiveSubdir, '/');
    }

    private static function scoreDirectoryCandidate(string $candidatePath, string $resolvedMainFile, string $slug, string $extractPath): int
    {
        $score = 0;
        $basename = basename($candidatePath);

        if ($basename === $slug) {
            $score += 100;
        }

        if ($candidatePath !== $extractPath) {
            $score += 10;
        }

        $depth = substr_count(trim(str_replace('\\', '/', $resolvedMainFile), '/'), '/');
        $score -= $depth * 10;

        return $score;
    }

    private static function scoreFileCandidate(string $candidatePath, string $slug): int
    {
        $score = 0;
        $basename = pathinfo($candidatePath, PATHINFO_FILENAME);

        if ($basename === $slug) {
            $score += 100;
        }

        return $score;
    }

    /**
     * @param list<array{path:string, score:int}> $matches
     */
    private static function resolveSingleMatch(array $matches, string $notFoundMessage, string $ambiguousMessage): string
    {
        $uniqueMatches = [];

        foreach ($matches as $match) {
            $uniqueMatches[$match['path']] = $match['score'];
        }

        if ($uniqueMatches === []) {
            throw new RuntimeException($notFoundMessage);
        }

        arsort($uniqueMatches);
        $paths = array_keys($uniqueMatches);
        $scores = array_values($uniqueMatches);

        if (count($paths) === 1 || $scores[0] > ($scores[1] ?? PHP_INT_MIN)) {
            return $paths[0];
        }

        throw new RuntimeException($ambiguousMessage);
    }
}
