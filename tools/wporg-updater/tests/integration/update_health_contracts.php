<?php

declare(strict_types=1);

use WpOrgPluginUpdater\UpdateHealthReport;

/** @param callable(bool,string):void $assert */
function run_update_health_contract_tests(callable $assert): void
{
    $policy = new UpdateHealthReport();
    $now = 1800000000;
    $oldRun = ['workflow_id' => 10, 'id' => 100, 'event' => 'pull_request', 'created_at' => gmdate(DATE_ATOM, $now - 3600), 'status' => 'completed', 'conclusion' => 'action_required'];
    $newRun = array_replace($oldRun, ['id' => 101, 'created_at' => gmdate(DATE_ATOM, $now), 'conclusion' => 'success']);
    $assert($policy->latestWorkflowRuns([$oldRun, $newRun]) === [$newRun], 'An old approval-required run must not mask the latest successful execution.');
    $assert($policy->latestWorkflowRuns([$newRun, $oldRun]) === [$newRun], 'Workflow selection must be independent of API ordering.');
    $otherWorkflow = array_replace($oldRun, ['workflow_id' => 11]);
    $assert(count($policy->latestWorkflowRuns([$oldRun, $newRun, $otherWorkflow])) === 2, 'A newer run of another workflow must not hide a blocked workflow.');
    $otherEvent = array_replace($oldRun, ['event' => 'push']);
    $assert(count($policy->latestWorkflowRuns([$newRun, $otherEvent])) === 2, 'Separate workflow events must retain their own current execution.');
    $rerun = array_replace($newRun, ['run_attempt' => 2, 'conclusion' => 'failure']);
    $assert($policy->latestWorkflowRuns([$rerun, $newRun]) === [$rerun], 'A newer attempt of the same run must remain authoritative.');
    $invalidRun = array_replace($oldRun, ['created_at' => 'invalid']);
    try {
        $policy->latestWorkflowRuns([$invalidRun]);
        $assert(false, 'Malformed workflow metadata must prevent a healthy report.');
    } catch (RuntimeException $exception) {
        $assert(str_contains($exception->getMessage(), 'incomplete workflow run'), 'Malformed workflow metadata fails with an actionable diagnostic.');
    }
    $pr = ['number' => 1, 'url' => 'https://example.invalid/1', 'created_at' => gmdate(DATE_ATOM, $now - 7200),
        'queued' => false, 'checks' => ['quality' => 'SUCCESS'], 'runs' => []];
    $assert($policy->evaluate([$pr], ['quality'], [], $now)['status'] === 'healthy', 'Fresh checked update should be healthy.');
    $approval = $pr;
    $approval['runs'] = [['status' => 'completed', 'conclusion' => 'action_required']];
    $assert($policy->evaluate([$approval], ['quality'], [], $now)['status'] === 'action_required', 'Approval-required workflow must not be masked by a successful check.');
    $missing = $pr;
    $missing['created_at'] = gmdate(DATE_ATOM, $now - 172800);
    $missing['checks'] = [];
    $assert($policy->evaluate([$missing], ['quality'], [], $now)['status'] === 'action_required', 'Missing required checks must become actionable.');
    $queued = $pr;
    $queued['created_at'] = gmdate(DATE_ATOM, $now - 3600 * 200);
    $queued['queued'] = true;
    $assert($policy->evaluate([$queued], ['quality'], [], $now)['pull_requests'][0]['state'] === 'queued', 'Expected dependency queueing must remain distinct from failed checks.');
    $queued['checks']['quality'] = 'FAILURE';
    $assert($policy->evaluate([$queued], ['quality'], [], $now)['status'] === 'action_required', 'Queue status must not hide failed checks.');
    $failedSource = [['name' => 'source', 'status' => 'completed', 'conclusion' => 'failure', 'url' => 'https://example.invalid/run', 'created_at' => gmdate(DATE_ATOM, $now - 3600)]];
    $assert($policy->evaluate([], ['quality'], $failedSource, $now)['actionable_count'] === 1, 'Source failures remain visible even without an open PR.');
    $failedSource[0]['conclusion'] = 'success';
    $failedSource[0]['created_at'] = gmdate(DATE_ATOM, $now - 49 * 3600);
    $assert($policy->evaluate([], ['quality'], $failedSource, $now)['source_failures'][0]['conclusion'] === 'stale_schedule', 'An old green run must not hide a stopped schedule.');
}
