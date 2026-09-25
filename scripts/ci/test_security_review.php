<?php

declare(strict_types=1);

require __DIR__ . '/security_review_policy.php';

use WpCoreBaseCi\SecurityReviewPolicy;

$policy = new SecurityReviewPolicy();
$root = sys_get_temp_dir() . '/wp-core-base-security-review-' . bin2hex(random_bytes(12));
if (! mkdir($root, 0700)) {
    throw new RuntimeException('Unable to create security-review fixture.');
}
$assertions = 0;
$check = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
};
$reject = static function (callable $callback, string $message) use ($check): void {
    try {
        $callback();
    } catch (RuntimeException $error) {
        $check(str_contains($error->getMessage(), $message), 'expected rejection mentioning "' . $message . '", got: ' . $error->getMessage());
        return;
    }
    $check(false, 'expected rejection mentioning "' . $message . '"');
};

try {
    mkdir($root . '/.wp-core-base');
    mkdir($root . '/runtime');
    mkdir($root . '/docs/security-reviews', 0700, true);
    file_put_contents($root . '/.wp-core-base/framework.php', "<?php return ['baseline' => ['wordpress_core' => '7.1.2']];\n");
    file_put_contents($root . '/runtime/editor.js', 'ordinary fixture source');
    file_put_contents($root . '/docs/security-reviews/baseline.md', '# Fixture review');
    $sha = str_repeat('a', 40);
    $ref = 'refs/heads/main';
    $repository = 'fixture/project';
    $location = ['path' => 'runtime/editor.js', 'start_line' => 2, 'end_line' => 2, 'start_column' => 3, 'end_column' => 8];
    $register = [
        'schema_version' => 1, 'repository' => $repository, 'wordpress_version' => '7.1.2',
        'sources' => ['runtime/editor.js' => hash_file('sha256', $root . '/runtime/editor.js')],
        'analysis_categories' => ['/language:javascript-typescript', '/language:actions'],
        'analysis_key' => 'dynamic/github-code-scanning/codeql:analyze',
        'workflow_path' => 'dynamic/github-code-scanning/codeql',
        'findings' => [[
            'number' => 230, 'rule_id' => 'js/redos', 'tool' => 'CodeQL', 'category' => '/language:javascript-typescript',
            'location' => $location, 'source_paths' => ['runtime/editor.js'], 'review_document' => 'docs/security-reviews/baseline.md',
        ]],
        'manual_findings' => [[
            'id' => 'inherited-editor-content', 'source_paths' => ['runtime/editor.js'], 'review_document' => 'docs/security-reviews/baseline.md',
        ]],
    ];
    $analyses = [];
    foreach ($register['analysis_categories'] as $index => $category) {
        $analyses[] = [
            'id' => 100 + $index, 'category' => $category, 'ref' => $ref, 'commit_sha' => $sha,
            'created_at' => '2026-09-25T12:00:00Z', 'error' => '', 'warning' => '', 'rules_count' => 17,
            'results_count' => $index === 0 ? 1 : 0, 'analysis_key' => $register['analysis_key'],
            'tool' => ['name' => 'CodeQL', 'version' => '2.27.1'],
        ];
    }
    $alerts = [[
        'number' => 230, 'state' => 'open', 'rule' => ['id' => 'js/redos'],
        'tool' => ['name' => 'CodeQL', 'version' => '2.27.1'],
        'most_recent_instance' => ['state' => 'open', 'ref' => $ref, 'commit_sha' => $sha,
            'category' => '/language:javascript-typescript', 'analysis_key' => $register['analysis_key'], 'location' => $location],
    ]];
    $evaluate = static fn (array $candidateAnalyses, array $candidateAlerts): array => $policy->evaluate($register, $candidateAnalyses, $candidateAlerts, $sha, $ref, $repository);
    $check($policy->validateRegister($register, $root) === $register, 'valid local register preserves source identity');
    $report = $evaluate($analyses, $alerts);
    $check($report['status'] === 'reviewed_with_open_findings', 'review is not described as a runtime fix');
    $check($report['runtime_security_cleared'] === false && $report['open_findings'] === [230], 'known findings remain open');
    $check($report['manual_findings'] === ['inherited-editor-content'], 'manual concern remains visible independently of scanner findings');

    $registerCases = [
        [static function (array &$r): void { $r['extra_policy'] = true; }, 'unknown fields'],
        [static function (array &$r): void { unset($r['analysis_key']); }, 'unknown fields'],
        [static function (array &$r): void { $r['schema_version'] = 2; }, 'schema version'],
        [static function (array &$r): void { $r['repository'] = 'not-a-repository'; }, 'review repository'],
        [static function (array &$r): void { $r['wordpress_version'] = '7.1.3'; }, 'baseline changed'],
        [static function (array &$r): void { $r['wordpress_version'] = '7.1.2-beta'; }, 'review version'],
        [static function (array &$r): void { $r['sources']['runtime/editor.js'] = str_repeat('0', 64); }, 'source changed'],
        [static function (array &$r): void { $r['sources']['runtime/editor.js'] = 'sha256:123'; }, 'SHA-256'],
        [static function (array &$r): void { $r['sources']['runtime/unused.js'] = str_repeat('0', 64); }, 'referenced by a finding'],
        [static function (array &$r): void { $r['sources'] = []; }, 'no reviewed digest'],
        [static function (array &$r): void { $r['analysis_categories'][] = '/language:actions'; }, 'Duplicate analysis categories'],
        [static function (array &$r): void { $r['analysis_categories'] = []; }, 'must not be empty'],
        [static function (array &$r): void { $r['analysis_key'] = ''; }, 'analysis key'],
        [static function (array &$r): void { $r['workflow_path'] = "bad\npath"; }, 'workflow path'],
        [static function (array &$r): void { $r['findings'][] = $r['findings'][0]; }, 'Duplicate reviewed finding'],
        [static function (array &$r): void { $r['manual_findings'][] = $r['manual_findings'][0]; }, 'Duplicate reviewed finding'],
        [static function (array &$r): void { $r['manual_findings'][0]['id'] = 'invalid ID'; }, 'manual finding identifier'],
        [static function (array &$r): void { $r['findings'][0]['number'] = '230'; }, 'finding number'],
        [static function (array &$r): void { $r['findings'][0]['tool'] = 'Other'; }, 'Only CodeQL'],
        [static function (array &$r): void { $r['findings'][0]['category'] = '/language:other'; }, 'category is not required'],
        [static function (array &$r): void { $r['findings'][0]['location']['path'] = 'runtime/other.js'; }, 'one of its reviewed source paths'],
        [static function (array &$r): void { $r['findings'][0]['location']['end_line'] = 1; }, 'location range'],
        [static function (array &$r): void { $r['findings'][0]['location']['start_column'] = 0; }, 'start_column'],
        [static function (array &$r): void { unset($r['findings'][0]['location']['end_column']); }, 'unknown fields'],
        [static function (array &$r): void { $r['findings'][0]['source_paths'][] = 'runtime/editor.js'; }, 'Duplicate finding source paths'],
        [static function (array &$r): void { $r['findings'][0]['review_document'] = 'README.md'; }, 'public security-review'],
        [static function (array &$r): void { $r['findings'][0]['review_document'] = 'docs/security-reviews/missing.md'; }, 'missing or outside'],
    ];
    foreach ($registerCases as [$change, $message]) {
        $candidate = $register;
        $change($candidate);
        $reject(static fn () => $policy->validateRegister($candidate, $root), $message);
    }
    foreach (['../outside.js', '/absolute.js', 'runtime//editor.js', 'runtime/./editor.js', 'runtime/../editor.js', 'C:\\outside.js', "runtime/evil\0.js", '.git/config'] as $unsafePath) {
        $candidate = $register;
        $candidate['sources'][$unsafePath] = str_repeat('0', 64);
        $reject(static fn () => $policy->validateRegister($candidate, $root), 'review path');
    }
    rename($root . '/runtime/editor.js', $root . '/editor-actual.js');
    symlink($root . '/editor-actual.js', $root . '/runtime/editor.js');
    $reject(static fn () => $policy->validateRegister($register, $root), 'symlinks');
    unlink($root . '/runtime/editor.js');
    rename($root . '/editor-actual.js', $root . '/runtime/editor.js');
    rename($root . '/runtime', $root . '/actual-runtime');
    symlink($root . '/actual-runtime', $root . '/runtime');
    $reject(static fn () => $policy->validateRegister($register, $root), 'symlinks');
    unlink($root . '/runtime');
    rename($root . '/actual-runtime', $root . '/runtime');
    unlink($root . '/runtime/editor.js');
    $reject(static fn () => $policy->validateRegister($register, $root), 'missing or outside');
    file_put_contents($root . '/runtime/editor.js', 'ordinary fixture source');
    file_put_contents($root . '/.wp-core-base/framework.php', '<?php return [];');
    $reject(static fn () => $policy->validateRegister($register, $root), 'baseline changed');
    file_put_contents($root . '/.wp-core-base/framework.php', "<?php return ['baseline' => ['wordpress_core' => '7.1.2']];\n");

    $analysisCases = [
        [static function (array &$a): void { $a = []; }, 'Missing exact-revision'],
        [static function (array &$a): void { array_pop($a); }, 'Missing exact-revision'],
        [static function (array &$a): void { $a[0]['commit_sha'] = str_repeat('b', 40); }, 'Missing exact-revision'],
        [static function (array &$a): void { $a[0]['ref'] = 'refs/heads/other'; }, 'Missing exact-revision'],
        [static function (array &$a): void { $a[0]['tool']['name'] = 'Another scanner'; }, 'Missing exact-revision'],
        [static function (array &$a): void { $a[0]['category'] = '/language:other'; }, 'Unexpected CodeQL analysis category'],
        [static function (array &$a): void { $a[0]['analysis_key'] = 'different-configuration'; }, 'configuration'],
        [static function (array &$a): void { $a[0]['error'] = 'analysis failed'; }, 'error'],
        [static function (array &$a): void { unset($a[0]['error']); }, 'error'],
        [static function (array &$a): void { $a[0]['warning'] = 'partial database'; }, 'warning'],
        [static function (array &$a): void { unset($a[0]['warning']); }, 'warning'],
        [static function (array &$a): void { $a[0]['rules_count'] = 0; }, 'rules count'],
        [static function (array &$a): void { $a[0]['results_count'] = -1; }, 'result count'],
        [static function (array &$a): void { $a[0]['results_count'] = 0; }, 'result count contradicts'],
        [static function (array &$a): void { $a[0]['results_count'] = '1'; }, 'result count'],
        [static function (array &$a): void { $a[0]['tool']['version'] = 'older-version'; }, 'different tool versions'],
        [static function (array &$a): void { unset($a[0]['tool']['version']); }, 'tool version'],
        [static function (array &$a): void { $a[0]['created_at'] = '2026-02-30T12:00:00Z'; }, 'creation timestamp'],
        [static function (array &$a): void { $a[0]['created_at'] = 'tomorrow'; }, 'creation timestamp'],
        [static function (array &$a): void { $a[] = $a[0]; }, 'Duplicate analysis'],
        [static function (array &$a): void { $a[0] = 'bad record'; }, 'Malformed analysis'],
        [static function (array &$a): void { unset($a[0]['id']); }, 'analysis ID'],
    ];
    foreach ($analysisCases as [$change, $message]) {
        $candidate = $analyses;
        $change($candidate);
        $reject(static fn () => $evaluate($candidate, $alerts), $message);
    }
    $oldFailure = $analyses[0];
    $oldFailure['id'] = 80;
    $oldFailure['created_at'] = '2026-09-25T11:00:00Z';
    $oldFailure['error'] = 'earlier failed run';
    $check($evaluate([$oldFailure, ...array_reverse($analyses)], $alerts)['open_findings'] === [230], 'chronological selection ignores earlier failure and API order');
    $newFailure = $oldFailure;
    $newFailure['id'] = 200;
    $newFailure['created_at'] = '2026-09-25T13:00:00Z';
    $reject(static fn () => $evaluate([$newFailure, ...$analyses], $alerts), 'error');
    $newFailure['created_at'] = $analyses[0]['created_at'];
    $reject(static fn () => $evaluate([$newFailure, ...$analyses], $alerts), 'error');

    $alertCases = [
        [static function (array &$a): void { $a = []; }, 'disappeared'],
        [static function (array &$a): void { $a[0]['number'] = 999; }, 'Unreviewed open'],
        [static function (array &$a): void { $a[] = $a[0]; }, 'Duplicate alert'],
        [static function (array &$a): void { $a[0]['number'] = '230'; }, 'alert number'],
        [static function (array &$a): void { $a[0]['state'] = 'dismissed'; }, 'only open'],
        [static function (array &$a): void { $a[0]['rule']['id'] = 'js/other'; }, 'rule changed'],
        [static function (array &$a): void { $a[0]['tool']['name'] = 'Other'; }, 'tool changed'],
        [static function (array &$a): void { $a[0]['tool']['version'] = 'older-version'; }, 'tool version'],
        [static function (array &$a): void { unset($a[0]['most_recent_instance']); }, 'instance is missing'],
        [static function (array &$a): void { $a[0]['most_recent_instance']['commit_sha'] = str_repeat('b', 40); }, 'stale'],
        [static function (array &$a): void { $a[0]['most_recent_instance']['ref'] = 'refs/heads/other'; }, 'stale'],
        [static function (array &$a): void { $a[0]['most_recent_instance']['state'] = 'fixed'; }, 'stale'],
        [static function (array &$a): void { $a[0]['most_recent_instance']['category'] = '/language:actions'; }, 'category changed'],
        [static function (array &$a): void { $a[0]['most_recent_instance']['analysis_key'] = 'other'; }, 'analysis key'],
        [static function (array &$a): void { $a[0]['most_recent_instance']['location']['start_column'] = 4; }, 'location changed'],
        [static function (array &$a): void { unset($a[0]['most_recent_instance']['location']['end_line']); }, 'location changed'],
        [static function (array &$a): void { unset($a[0]['most_recent_instance']['location']); }, 'location is missing'],
        [static function (array &$a): void { $a[0] = null; }, 'Malformed alert'],
    ];
    foreach ($alertCases as [$change, $message]) {
        $candidate = $alerts;
        $change($candidate);
        $reject(static fn () => $evaluate($analyses, $candidate), $message);
    }
    $reject(static fn () => $policy->evaluate($register, $analyses, $alerts, 'short', $ref, $repository), 'full lowercase SHA');
    $reject(static fn () => $policy->evaluate($register, $analyses, $alerts, $sha, 'main', $repository), 'identify a branch');
    $reject(static fn () => $policy->evaluate($register, $analyses, $alerts, $sha, $ref, 'other/project'), 'repository does not match');
    $reject(static fn () => $evaluate(['items' => $analyses], $alerts), 'complete JSON arrays');
    $reject(static fn () => $evaluate($analyses, ['items' => $alerts]), 'complete JSON arrays');
    $manualOnly = $register;
    $manualOnly['findings'] = [];
    $check($policy->validateRegister($manualOnly, $root) === $manualOnly, 'manual-only register is supported');
    $manualReport = $policy->evaluate($manualOnly, $analyses, [], $sha, $ref, $repository);
    $check($manualReport['manual_findings'] === ['inherited-editor-content'] && $manualReport['runtime_security_cleared'] === false, 'zero scanner findings do not clear the manual concern');
    fwrite(STDOUT, sprintf("Security review policy: %d assertions passed.\n", $assertions));
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir() && ! $file->isLink()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    rmdir($root);
}
