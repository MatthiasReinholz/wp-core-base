<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/tools/wporg-updater/src/Autoload.php';

use WpOrgPluginUpdater\PrBodyRenderer;
use WpOrgPluginUpdater\UpdateHealthReport;

$options = ['repo' => getenv('GITHUB_REPOSITORY') ?: '', 'max-age-hours' => '168', 'check-grace-hours' => '24'];
$json = false;
$failOnActionable = false;
foreach (array_slice(array_values(array_map('strval', $GLOBALS['argv'] ?? [])), 1) as $argument) {
    if ($argument === '--json') {
        $json = true;
    } elseif ($argument === '--fail-on-actionable') {
        $failOnActionable = true;
    } elseif (preg_match('/^--(repo|max-age-hours|check-grace-hours)=(.+)$/D', $argument, $match) === 1) {
        $options[$match[1]] = $match[2];
    } else {
        throw new RuntimeException('Unsupported update-health argument: ' . $argument);
    }
}
if (preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~D', $options['repo']) !== 1) {
    throw new RuntimeException('Provide --repo=owner/repository.');
}
foreach (['max-age-hours', 'check-grace-hours'] as $name) {
    if (! ctype_digit($options[$name]) || (int) $options[$name] < 1) {
        throw new RuntimeException('--' . $name . ' must be a positive integer.');
    }
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
