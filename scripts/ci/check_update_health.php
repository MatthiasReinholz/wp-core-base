<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/tools/wporg-updater/src/Autoload.php';

use WpOrgPluginUpdater\PrBodyRenderer;
use WpOrgPluginUpdater\UpdateHealthReport;
use WpOrgPluginUpdater\AutomationPullRequestGuard;
use WpOrgPluginUpdater\ManagedPullRequestBranchIdentity;

$options = ['repo' => getenv('GITHUB_REPOSITORY') ?: '', 'max-age-hours' => '168', 'check-grace-hours' => '24',
    'cleanup-lookback-days' => '30', 'cleanup-grace-hours' => '1'];
$json = false;
$failOnActionable = false;
foreach (array_slice(array_values(array_map('strval', $GLOBALS['argv'] ?? [])), 1) as $argument) {
    if ($argument === '--json') {
        $json = true;
    } elseif ($argument === '--fail-on-actionable') {
        $failOnActionable = true;
    } elseif (preg_match('/^--(repo|max-age-hours|check-grace-hours|cleanup-lookback-days|cleanup-grace-hours)=(.+)$/D', $argument, $match) === 1) {
        $options[$match[1]] = $match[2];
    } else {
        throw new RuntimeException('Unsupported update-health argument: ' . $argument);
    }
}
if (preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~D', $options['repo']) !== 1) {
    throw new RuntimeException('Provide --repo=owner/repository.');
}
foreach (['max-age-hours', 'check-grace-hours', 'cleanup-lookback-days', 'cleanup-grace-hours'] as $name) {
    if (! ctype_digit($options[$name]) || (int) $options[$name] < 1) {
        throw new RuntimeException('--' . $name . ' must be a positive integer.');
    }
}
if ((int) $options['cleanup-lookback-days'] > 365 || (int) $options['cleanup-grace-hours'] > 8760) {
    throw new RuntimeException('Cleanup lookback must be at most 365 days and grace at most 8760 hours.');
}
$repository = $options['repo'];
$policy = new UpdateHealthReport();
$now = time();
$repositoryData = healthGithubJson(['api', 'repos/' . $repository]);
$base = (string) ($repositoryData['default_branch'] ?? '');
if ($base === '') {
    throw new RuntimeException('GitHub did not report the default branch.');
}
$rules = healthGithubJson(['api', 'repos/' . $repository . '/rules/branches/' . rawurlencode($base)]);
$required = [];
foreach ($rules as $rule) {
    if (($rule['type'] ?? null) === 'required_status_checks') {
        foreach ($rule['parameters']['required_status_checks'] ?? [] as $check) {
            $required[] = (string) $check['context'];
        }
    }
}
if ($required === []) {
    throw new RuntimeException('No effective required checks were returned; refusing to report unverified automation as healthy.');
}
$pullRequests = [];
foreach (['automation:dependency-update', 'automation:framework-update'] as $label) {
    $labelled = healthGithubJson(['pr', 'list', '--repo', $repository, '--state', 'open', '--label', $label, '--limit', '1000',
        '--json', 'number,url,createdAt,body,headRefOid,headRefName,statusCheckRollup']);
    if (count($labelled) >= 1000) {
        throw new RuntimeException('PR inventory reached the query limit; refusing to report potentially incomplete health.');
    }
    foreach ($labelled as $pr) {
        $pullRequests[(int) $pr['number']] = $pr;
    }
}
$pullRequests = array_values($pullRequests);
$openNumbers = array_map(static fn (array $pr): int => (int) $pr['number'], $pullRequests);
$normalized = [];
foreach ($pullRequests as $pr) {
    $checks = [];
    foreach ($pr['statusCheckRollup'] ?? [] as $check) {
        $name = (string) ($check['name'] ?? $check['context'] ?? '');
        $checks[$name] = (string) (($check['conclusion'] ?? '') !== '' ? $check['conclusion'] : ($check['state'] ?? $check['status'] ?? 'UNKNOWN'));
    }
    $runs = healthGithubJson(['api', 'repos/' . $repository . '/actions/runs?event=pull_request&head_sha=' . rawurlencode((string) $pr['headRefOid']) . '&branch=' . rawurlencode((string) $pr['headRefName']) . '&per_page=100']);
    if ((int) ($runs['total_count'] ?? 0) > 100) {
        throw new RuntimeException('PR workflow history reached the query limit; refusing to report potentially incomplete health.');
    }
    $metadata = PrBodyRenderer::extractMetadata((string) $pr['body']);
    $normalized[] = ['number' => (int) $pr['number'], 'url' => (string) $pr['url'], 'created_at' => (string) $pr['createdAt'],
        'queued' => array_intersect((array) ($metadata['blocked_by'] ?? []), $openNumbers) !== [], 'checks' => $checks,
        'runs' => array_map(static fn (array $run): array => ['status' => (string) $run['status'], 'conclusion' => (string) ($run['conclusion'] ?? '')], $policy->latestWorkflowRuns($runs['workflow_runs'] ?? []))];
}
$sourceRuns = [];
foreach (['wporg-updates.yml', 'wporg-updates-reconcile.yml'] as $workflow) {
    // A bounded recent query avoids mistaking a cached historical first page for
    // the latest execution. Always select by timestamp, never response order.
    $endpoint = 'repos/' . $repository . '/actions/workflows/' . $workflow . '/runs?event=schedule&branch=' . rawurlencode($base) . '&per_page=100';
    $runs = healthGithubJson(['api', $endpoint . '&created=' . rawurlencode('>=' . gmdate('Y-m-d\TH:i:s\Z', $now - 48 * 3600))]);
    if (($runs['workflow_runs'] ?? []) === []) {
        $runs = healthGithubJson(['api', $endpoint]);
    }
    if (($runs['workflow_runs'] ?? []) === []) {
        throw new RuntimeException('No scheduled execution was found for ' . $workflow . '; automation health cannot be established.');
    }
    foreach ($policy->latestWorkflowRuns($runs['workflow_runs'] ?? []) as $run) {
        $sourceRuns[] = ['name' => (string) $run['name'], 'status' => (string) $run['status'], 'conclusion' => (string) ($run['conclusion'] ?? ''), 'url' => (string) $run['html_url'], 'created_at' => (string) $run['created_at']];
    }
}
$report = $policy->evaluate($normalized, array_values(array_unique($required)), $sourceRuns, $now, (int) $options['max-age-hours'], (int) $options['check-grace-hours']);
$cleanup = $policy->evaluateCleanup(healthCleanupCandidates($repository, $base, $now, (int) $options['cleanup-lookback-days']), $now, (int) $options['cleanup-grace-hours']);
$report['cleanup'] = ['lookback_days' => (int) $options['cleanup-lookback-days'], 'grace_hours' => (int) $options['cleanup-grace-hours'],
    'query_limit_per_label' => 200, 'pull_requests' => $cleanup['pull_requests'], 'actionable_count' => $cleanup['actionable_count']];
$report['actionable_count'] += $cleanup['actionable_count'];
$report['status'] = $report['actionable_count'] > 0 ? 'action_required' : 'healthy';
if ($json) {
    fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
} else {
    fwrite(STDOUT, sprintf("Update automation: %s (%d actionable items)\n", $report['status'], $report['actionable_count']));
    foreach ($report['pull_requests'] as $pr) {
        fwrite(STDOUT, sprintf("#%d %s; age %dh; %s\n", $pr['number'], $pr['state'], $pr['age_hours'], $pr['url']));
        foreach ($pr['reasons'] as $reason) {
            fwrite(STDOUT, '  - ' . $reason . "\n");
        }
    }
    foreach ($report['source_failures'] as $run) {
        fwrite(STDOUT, sprintf("Source workflow needs attention: %s (%s) %s\n", $run['name'], $run['conclusion'], $run['url']));
    }
    fwrite(STDOUT, sprintf("Managed cleanup: %d recent closed PRs checked; %d need attention (lookback %d days, grace %d hours).\n",
        count($cleanup['pull_requests']), $cleanup['actionable_count'], (int) $options['cleanup-lookback-days'], (int) $options['cleanup-grace-hours']));
    foreach ($cleanup['pull_requests'] as $closure) {
        if (in_array($closure['state'], ['cleanup_required', 'manual_review'], true)) {
            fwrite(STDOUT, sprintf("Closed PR #%d %s: %s %s\n", $closure['number'], $closure['state'], $closure['reason'], $closure['url']));
        }
    }
}
exit($failOnActionable && $report['actionable_count'] > 0 ? 1 : 0);

/** @param list<string> $arguments
 *  @return array<mixed>
 */
function healthGithubJson(array $arguments): array
{
    $process = proc_open(['gh', ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Unable to invoke GitHub CLI.');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    if (proc_close($process) !== 0 || ! is_string($output)) {
        throw new RuntimeException('GitHub health data could not be read; the report is incomplete.');
    }
    $data = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($data)) {
        throw new RuntimeException('GitHub returned an unexpected health response.');
    }
    return $data;
}

/**
 * @return list<array{number:int,url:string,closed_at:string,branch:string,expected_head:string,observed_head:?string,reused_by_open_pr:bool,identity_error:?string}>
 */
function healthCleanupCandidates(string $repository, string $base, int $now, int $lookbackDays): array
{
    $cutoff = $now - $lookbackDays * 86400;
    $numbers = [];
    foreach (['automation:dependency-update', 'automation:framework-update'] as $label) {
        // is:closed includes merged PRs as well as manually closed PRs.
        $inventory = healthGithubJson(['pr', 'list', '--repo', $repository, '--state', 'all', '--search',
            'is:closed closed:>=' . gmdate('Y-m-d', $cutoff), '--label', $label, '--limit', '200', '--json', 'number']);
        if (! array_is_list($inventory) || count($inventory) >= 200) {
            throw new RuntimeException('Recent closed-PR inventory is invalid or reached its limit; reduce --cleanup-lookback-days before reporting health.');
        }
        foreach ($inventory as $entry) {
            if (! is_array($entry) || ! is_int($entry['number'] ?? null) || $entry['number'] < 1) {
                throw new RuntimeException('GitHub returned an incomplete closed-PR inventory.');
            }
            $numbers[$entry['number']] = true;
        }
    }
    if ($numbers === []) {
        return [];
    }
    // A readable known ref distinguishes absent branches from missing contents access.
    if (healthGithubBranchRevision($repository, $base) === null) {
        throw new RuntimeException('The default branch could not be read; closed-PR cleanup health is unverified.');
    }
    $candidates = [];
    $revisions = [];
    $reuse = [];
    foreach (array_keys($numbers) as $number) {
        $pr = healthGithubJson(['api', 'repos/' . $repository . '/pulls/' . $number]);
        if (($pr['number'] ?? null) !== $number || ! in_array($pr['state'] ?? null, ['open', 'closed'], true)
            || ! is_array($pr['base'] ?? null) || ! is_array($pr['head'] ?? null)
            || strtolower((string) ($pr['base']['repo']['full_name'] ?? '')) !== strtolower($repository)) {
            throw new RuntimeException('GitHub returned an incomplete or mismatched closed-PR record for #' . $number . '.');
        }
        if ($pr['state'] === 'open') {
            continue; // A reopened PR is no longer a cleanup candidate.
        }
        $closedAt = $pr['closed_at'] ?? null;
        $url = $pr['html_url'] ?? null;
        if (! is_string($closedAt) || strtotime($closedAt) === false || strtotime($closedAt) > $now
            || ! is_string($url) || $url === '' || ! is_array($pr['labels'] ?? null) || ! array_is_list($pr['labels'])
            || ! is_string($pr['head']['ref'] ?? null) || $pr['head']['ref'] === '' || ! is_string($pr['base']['ref'] ?? null)
            || (isset($pr['body']) && ! is_string($pr['body']))) {
            throw new RuntimeException('GitHub returned incomplete closure metadata for PR #' . $number . '.');
        }
        foreach ($pr['labels'] as $label) {
            if (! is_array($label) || ! is_string($label['name'] ?? null) || $label['name'] === '') {
                throw new RuntimeException('GitHub returned invalid closure ownership labels for PR #' . $number . '.');
            }
        }
        if (strtotime($closedAt) < $cutoff) {
            continue;
        }
        $identityError = null;
        $branch = '';
        $observed = null;
        $reused = false;
        try {
            $branch = ManagedPullRequestBranchIdentity::validate($pr, $base);
        } catch (RuntimeException $exception) {
            $identityError = $exception->getMessage();
        }
        if ($identityError === null) {
            if (! array_key_exists($branch, $revisions)) {
                $revisions[$branch] = healthGithubBranchRevision($repository, $branch);
            }
            $observed = $revisions[$branch];
            if ($observed !== null) {
                if (! array_key_exists($branch, $reuse)) {
                    $reuse[$branch] = healthBranchUsedByOpenPullRequest($repository, $branch);
                }
                $reused = $reuse[$branch];
            }
        }
        $head = $pr['head']['sha'] ?? '';
        if (! is_string($head)) {
            throw new RuntimeException('GitHub returned an invalid PR head revision for #' . $number . '.');
        }
        $candidates[] = ['number' => $number, 'url' => $url, 'closed_at' => $closedAt, 'branch' => $branch,
            'expected_head' => $head, 'observed_head' => $observed, 'reused_by_open_pr' => $reused, 'identity_error' => $identityError];
    }

    return $candidates;
}

/** Read an exact ref; only an actual HTTP 404 establishes absence. */
function healthGithubBranchRevision(string $repository, string $branch): ?string
{
    $endpoint = 'repos/' . $repository . '/git/ref/heads/' . rawurlencode($branch);
    $process = proc_open(['gh', 'api', $endpoint, '--include'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Unable to invoke GitHub CLI for branch-ref health.');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $exit = proc_close($process);
    if (! is_string($output) || preg_match('/^HTTP\/\S+\s+(\d{3})(?:\s|\r?\n)/', $output, $status) !== 1) {
        throw new RuntimeException('GitHub branch-ref response was incomplete; cleanup health is unverified.');
    }
    $code = (int) $status[1];
    if ($code === 404 && $exit !== 0) {
        return null;
    }
    if ($code !== 200 || $exit !== 0) {
        throw new RuntimeException(sprintf('GitHub branch-ref read failed (HTTP %d, exit %d); cleanup health is unverified.', $code, $exit));
    }
    $parts = preg_split('/\r?\n\r?\n/', $output, 2);
    $ref = json_decode($parts[1] ?? '', true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($ref) || ($ref['ref'] ?? null) !== 'refs/heads/' . $branch
        || ($ref['object']['type'] ?? null) !== 'commit' || ! is_string($ref['object']['sha'] ?? null)
        || preg_match('/^[a-f0-9]{40}(?:[a-f0-9]{24})?$/D', $ref['object']['sha']) !== 1) {
        throw new RuntimeException('GitHub returned an invalid exact branch ref; cleanup health is unverified.');
    }

    return $ref['object']['sha'];
}

function healthBranchUsedByOpenPullRequest(string $repository, string $branch): bool
{
    $owner = explode('/', $repository, 2)[0];
    $prs = healthGithubJson(['api', 'repos/' . $repository . '/pulls?state=open&head=' . rawurlencode($owner . ':' . $branch) . '&per_page=100']);
    if (! array_is_list($prs) || count($prs) >= 100) {
        throw new RuntimeException('Open-PR branch reuse inventory is invalid or incomplete.');
    }
    foreach ($prs as $pr) {
        if (! is_array($pr) || ! is_int($pr['number'] ?? null) || $pr['number'] < 1 || ! in_array($pr['state'] ?? null, ['open', 'closed'], true)
            || ! is_array($pr['head'] ?? null) || ! is_array($pr['base'] ?? null)) {
            throw new RuntimeException('GitHub returned incomplete branch reuse metadata.');
        }
        if ($pr['state'] === 'open' && ($pr['head']['ref'] ?? null) === $branch
            && strtolower((string) ($pr['base']['repo']['full_name'] ?? '')) === strtolower($repository)
            && AutomationPullRequestGuard::isSameRepositoryAutomationPullRequest($pr)) {
            return true;
        }
    }

    return false;
}
