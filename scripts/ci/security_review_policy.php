<?php

declare(strict_types=1);

namespace WpCoreBaseCi;

use DateTimeImmutable;
use RuntimeException;

/** Source-repository review governance; this does not sanitize or certify runtime code. */
final class SecurityReviewPolicy
{
    /**
     * @param array<mixed> $register
     * @return array<string, mixed>
     */
    public function validateRegister(array $register, string $repoRoot): array
    {
        $this->validateShape($register);
        $root = realpath($repoRoot);
        $this->require($root !== false && is_dir($root), 'Repository root is not a directory.');
        $frameworkPath = $this->regularFile((string) $root, '.wp-core-base/framework.php');
        $framework = (static function (string $path) { return require $path; })($frameworkPath);
        $this->require(is_array($framework) && is_array($framework['baseline'] ?? null)
            && ($framework['baseline']['wordpress_core'] ?? null) === $register['wordpress_version'],
            'WordPress baseline changed; update the security review explicitly.');

        foreach ($register['sources'] as $path => $digest) {
            $actual = hash_file('sha256', $this->regularFile((string) $root, $path));
            $this->require(is_string($actual) && hash_equals($digest, $actual),
                'Reviewed source changed: ' . $path . '. Renew its review before release.');
        }
        foreach ([...$register['findings'], ...$register['manual_findings']] as $finding) {
            $this->regularFile((string) $root, $finding['review_document']);
        }
        return $register;
    }

    /**
     * Input arrays must be complete API inventories, not truncated pages. The caller
     * establishes retrieval completeness and validates local sources before this call.
     *
     * @param array<mixed> $register
     * @param array<mixed> $analyses
     * @param array<mixed> $alerts Open alerts scoped to the requested ref.
     * @return array<string, mixed>
     */
    public function evaluate(array $register, array $analyses, array $alerts, string $targetSha, string $targetRef, string $repository): array
    {
        $this->validateShape($register);
        $this->require(preg_match('/^[a-f0-9]{40}$/D', $targetSha) === 1, 'Target commit must be a full lowercase SHA.');
        $this->require(preg_match('~^refs/heads/[A-Za-z0-9][A-Za-z0-9._/-]*$~D', $targetRef) === 1
            && ! str_contains($targetRef, '..') && ! str_contains($targetRef, '//'), 'Target ref must identify a branch.');
        $this->require($repository === $register['repository'], 'Review repository does not match the requested repository.');
        $this->require(array_is_list($analyses) && array_is_list($alerts), 'Scanner inventories must be complete JSON arrays.');
        $latest = [];
        $analysisIds = [];
        foreach ($analyses as $analysis) {
            $this->require(is_array($analysis), 'Malformed analysis record.');
            $this->positiveInteger($analysis['id'] ?? null, 'analysis ID');
            $this->require(! isset($analysisIds[$analysis['id']]), 'Duplicate analysis record; inventory may be inconsistent.');
            $analysisIds[$analysis['id']] = true;
            $this->require(is_array($analysis['tool'] ?? null), 'Analysis tool is missing.');
            $this->nonemptyString($analysis['tool']['name'] ?? null, 'analysis tool name');
            $this->nonemptyString($analysis['commit_sha'] ?? null, 'analysis commit');
            $this->nonemptyString($analysis['ref'] ?? null, 'analysis ref');
            if ($analysis['tool']['name'] !== 'CodeQL' || $analysis['commit_sha'] !== $targetSha || $analysis['ref'] !== $targetRef) {
                continue;
            }
            $this->nonemptyString($analysis['category'] ?? null, 'analysis category');
            $category = $analysis['category'];
            $this->require(in_array($category, $register['analysis_categories'], true), 'Unexpected CodeQL analysis category: ' . $category);
            $this->require(($analysis['analysis_key'] ?? null) === $register['analysis_key'], 'Unexpected CodeQL analysis configuration: ' . $category);
            $createdAt = $this->timestamp($analysis['created_at'] ?? null);
            if (! isset($latest[$category]) || $createdAt > $latest[$category]['time']
                || ($createdAt === $latest[$category]['time'] && $analysis['id'] > $latest[$category]['analysis']['id'])) {
                $latest[$category] = ['time' => $createdAt, 'analysis' => $analysis];
            }
        }
        $reviewedAnalyses = [];
        $toolVersion = null;
        foreach ($register['analysis_categories'] as $category) {
            $this->require(isset($latest[$category]), 'Missing exact-revision CodeQL analysis: ' . $category);
            $analysis = $latest[$category]['analysis'];
            $this->require(array_key_exists('error', $analysis) && $analysis['error'] === '', 'Latest CodeQL analysis has an error or missing error status: ' . $category);
            $this->require(array_key_exists('warning', $analysis) && $analysis['warning'] === '', 'Latest CodeQL analysis has a warning or missing warning status: ' . $category);
            $this->positiveInteger($analysis['rules_count'] ?? null, 'analysis rules count');
            $this->require(is_int($analysis['results_count'] ?? null) && $analysis['results_count'] >= 0, 'Analysis result count is missing or invalid.');
            $this->nonemptyString($analysis['tool']['version'] ?? null, 'analysis tool version');
            $this->require($toolVersion === null || $analysis['tool']['version'] === $toolVersion, 'CodeQL categories were analyzed with different tool versions.');
            $toolVersion = $analysis['tool']['version'];
            $reviewedAnalyses[] = ['id' => $analysis['id'], 'category' => $category, 'created_at' => $analysis['created_at']];
        }

        $known = [];
        foreach ($register['findings'] as $finding) {
            $known[$finding['number']] = $finding;
        }
        $seen = [];
        $categoryCounts = [];
        foreach ($alerts as $alert) {
            $this->require(is_array($alert), 'Malformed alert record.');
            $this->positiveInteger($alert['number'] ?? null, 'alert number');
            $number = $alert['number'];
            $this->require(! isset($seen[$number]), 'Duplicate alert record; inventory may be inconsistent.');
            $seen[$number] = true;
            $this->require(isset($known[$number]), 'Unreviewed open scanner finding: ' . $number);
            $finding = $known[$number];
            $this->require(($alert['state'] ?? null) === 'open', 'Alert inventory must contain only open findings.');
            $this->require(is_array($alert['rule'] ?? null) && ($alert['rule']['id'] ?? null) === $finding['rule_id'], 'Scanner rule changed for finding ' . $number);
            $this->require(is_array($alert['tool'] ?? null) && ($alert['tool']['name'] ?? null) === $finding['tool'], 'Scanner tool changed for finding ' . $number);
            $instance = $alert['most_recent_instance'] ?? null;
            $this->require(is_array($instance), 'Finding instance is missing: ' . $number);
            $this->require(($instance['state'] ?? null) === 'open' && ($instance['commit_sha'] ?? null) === $targetSha
                && ($instance['ref'] ?? null) === $targetRef, 'Finding instance is stale or belongs to another revision: ' . $number);
            $this->require(($instance['category'] ?? null) === $finding['category'], 'Scanner category changed for finding ' . $number);
            $analysis = $latest[$finding['category']]['analysis'];
            $categoryCounts[$finding['category']] = ($categoryCounts[$finding['category']] ?? 0) + 1;
            $this->require(($instance['analysis_key'] ?? null) === $analysis['analysis_key'], 'Finding does not match the reviewed analysis key: ' . $number);
            $this->require(($alert['tool']['version'] ?? null) === $analysis['tool']['version'], 'Finding does not match the reviewed tool version: ' . $number);
            $this->require(is_array($instance['location'] ?? null), 'Finding location is missing: ' . $number);
            foreach ($finding['location'] as $key => $value) {
                $this->require(($instance['location'][$key] ?? null) === $value, 'Reviewed location changed for finding ' . $number . ': ' . $key);
            }
        }
        foreach ($known as $number => $finding) {
            $this->require(isset($seen[$number]), 'Reviewed finding disappeared; explicitly renew the register: ' . $number);
        }
        foreach ($categoryCounts as $category => $count) {
            $this->require($latest[$category]['analysis']['results_count'] >= $count, 'Scanner result count contradicts the open finding inventory: ' . $category);
        }
        $numbers = array_keys($seen);
        sort($numbers, SORT_NUMERIC);
        return ['status' => 'reviewed_with_open_findings', 'repository' => $repository, 'commit_sha' => $targetSha,
            'ref' => $targetRef, 'analyses' => $reviewedAnalyses, 'open_findings' => $numbers,
            'manual_findings' => array_column($register['manual_findings'], 'id'),
            'runtime_security_cleared' => false];
    }

    /** @param array<mixed> $register */
    private function validateShape(array $register): void
    {
        $this->keys($register, ['schema_version', 'repository', 'wordpress_version', 'sources', 'analysis_categories', 'analysis_key', 'workflow_path', 'findings', 'manual_findings'], 'review register');
        $this->require($register['schema_version'] === 1, 'Unsupported security review schema version.');
        $this->require(is_string($register['repository']) && preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~D', $register['repository']) === 1, 'Invalid review repository.');
        $this->require(is_string($register['wordpress_version']) && preg_match('/^[0-9]+\.[0-9]+(?:\.[0-9]+)?$/D', $register['wordpress_version']) === 1, 'Invalid WordPress review version.');
        $this->require(is_array($register['sources']), 'Reviewed sources must be a path/digest object.');
        foreach ($register['sources'] as $path => $digest) {
            $this->safePath($path);
            $this->require(is_string($digest) && preg_match('/^[a-f0-9]{64}$/D', $digest) === 1, 'Invalid SHA-256 for reviewed source: ' . $path);
        }
        $this->stringList($register['analysis_categories'], 'analysis categories', false);
        $this->nonemptyString($register['analysis_key'], 'review analysis key');
        $this->nonemptyString($register['workflow_path'], 'review workflow path');
        foreach ($register['analysis_categories'] as $category) {
            $this->require(str_starts_with($category, '/language:'), 'Unsupported CodeQL category: ' . $category);
        }
        $used = [];
        $ids = [];
        foreach (['findings', 'manual_findings'] as $kind) {
            $this->require(is_array($register[$kind]) && array_is_list($register[$kind]), $kind . ' must be an array.');
            foreach ($register[$kind] as $finding) {
                $this->require(is_array($finding), 'Malformed ' . $kind . ' entry.');
                $this->keys($finding, $kind === 'findings'
                    ? ['number', 'rule_id', 'tool', 'category', 'location', 'source_paths', 'review_document']
                    : ['id', 'source_paths', 'review_document'], $kind . ' entry');
                if ($kind === 'findings') {
                    $this->positiveInteger($finding['number'], 'finding number');
                    $id = 'scanner:' . $finding['number'];
                    $this->nonemptyString($finding['rule_id'], 'rule ID');
                    $this->require($finding['tool'] === 'CodeQL', 'Only CodeQL findings are supported by this review policy.');
                    $this->require(is_string($finding['category']) && in_array($finding['category'], $register['analysis_categories'], true), 'Finding category is not required by the review.');
                    $this->require(is_array($finding['location']), 'Finding location must be an object.');
                    $this->keys($finding['location'], ['path', 'start_line', 'end_line', 'start_column', 'end_column'], 'finding location');
                    $this->safePath($finding['location']['path']);
                    foreach (['start_line', 'end_line', 'start_column', 'end_column'] as $coordinate) {
                        $this->positiveInteger($finding['location'][$coordinate], 'location ' . $coordinate);
                    }
                    $location = $finding['location'];
                    $this->require($location['end_line'] >= $location['start_line']
                        && ($location['end_line'] !== $location['start_line'] || $location['end_column'] >= $location['start_column']), 'Invalid finding location range.');
                } else {
                    $this->require(is_string($finding['id']) && preg_match('/^[a-z][a-z0-9-]*$/D', $finding['id']) === 1, 'Invalid manual finding identifier.');
                    $id = 'manual:' . $finding['id'];
                }
                $this->require(! isset($ids[$id]), 'Duplicate reviewed finding: ' . $id);
                $ids[$id] = true;
                $this->stringList($finding['source_paths'], 'finding source paths', false);
                foreach ($finding['source_paths'] as $path) {
                    $this->safePath($path);
                    $this->require(array_key_exists($path, $register['sources']), 'Finding source has no reviewed digest: ' . $path);
                    $used[$path] = true;
                }
                if ($kind === 'findings') {
                    $this->require(in_array($finding['location']['path'], $finding['source_paths'], true), 'Finding location must be one of its reviewed source paths.');
                }
                $this->safePath($finding['review_document']);
                $this->require(str_starts_with($finding['review_document'], 'docs/security-reviews/')
                    && str_ends_with($finding['review_document'], '.md'), 'Review document must be a public security-review Markdown file.');
            }
        }
        $this->require(count($used) === count($register['sources']), 'Every reviewed source must be referenced by a finding.');
    }

    /** @param array<mixed> $value
     * @param list<string> $expected
     */
    private function keys(array $value, array $expected, string $label): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($expected);
        $this->require($actual === $expected, 'Missing or unknown fields in ' . $label . '.');
    }

    /** @param mixed $value */
    private function stringList($value, string $label, bool $allowEmpty): void
    {
        $this->require(is_array($value) && array_is_list($value), $label . ' must be an array.');
        $this->require($allowEmpty || $value !== [], $label . ' must not be empty.');
        $seen = [];
        foreach ($value as $entry) {
            $this->nonemptyString($entry, $label . ' entry');
            $this->require(! isset($seen[$entry]), 'Duplicate ' . $label . ' entry.');
            $seen[$entry] = true;
        }
    }

    /** @param mixed $value */
    private function nonemptyString($value, string $label): void
    {
        $this->require(is_string($value) && $value !== '' && trim($value) === $value
            && preg_match('/[\x00-\x1f\x7f]/', $value) !== 1, 'Invalid ' . $label . '.');
    }

    /** @param mixed $value */
    private function positiveInteger($value, string $label): void
    {
        $this->require(is_int($value) && $value > 0, 'Invalid ' . $label . '.');
    }

    /** @param mixed $path */
    private function safePath($path): void
    {
        $this->require(is_string($path) && preg_match('~^[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*$~D', $path) === 1, 'Invalid repository-relative review path.');
        foreach (explode('/', $path) as $part) {
            $this->require($part !== '.' && $part !== '..' && strtolower($part) !== '.git', 'Unsafe repository-relative review path: ' . $path);
        }
    }

    private function regularFile(string $root, string $path): string
    {
        $this->safePath($path);
        $candidate = $root;
        foreach (explode('/', $path) as $part) {
            $candidate .= '/' . $part;
            $this->require(! is_link($candidate), 'Reviewed paths must not traverse symlinks: ' . $path);
        }
        $resolved = realpath($candidate);
        $this->require($resolved !== false && str_starts_with($resolved, $root . '/') && is_file($resolved), 'Reviewed file is missing or outside the repository: ' . $path);
        return (string) $resolved;
    }

    /** @param mixed $value */
    private function timestamp($value): int
    {
        $this->require(is_string($value), 'Analysis creation timestamp is missing.');
        $time = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        $this->require($time !== false && $time->format('Y-m-d\TH:i:s\Z') === $value, 'Invalid analysis creation timestamp.');
        return $time->getTimestamp();
    }

    /** @phpstan-assert true $condition */
    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }
}
