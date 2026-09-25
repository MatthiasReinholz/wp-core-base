<?php

declare(strict_types=1);

use WpOrgPluginUpdater\UpdateHealthReport;

/** @param callable(bool,string):void $assert */
function run_update_health_contract_tests(callable $assert): void
{
    $policy = new UpdateHealthReport();
    $now = 1800000000;
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
