<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

/** Pure policy for read-only automation monitoring; it never approves checks. */
final class UpdateHealthReport
{
    /**
     * Select current attempts independently of API ordering. An older blocked or
     * failed run must not override a newer run of the same workflow and event.
     *
     * @param list<array<string,mixed>> $runs
     * @return list<array<string,mixed>>
     */
    public function latestWorkflowRuns(array $runs): array
    {
        $latest = [];
        foreach ($runs as $run) {
            $workflow = $run['workflow_id'] ?? null;
            $event = $run['event'] ?? null;
            $created = $run['created_at'] ?? null;
            $id = $run['id'] ?? null;
            if (! is_int($workflow) || ! is_string($event) || $event === '' || ! is_string($created)
                || strtotime($created) === false || ! is_int($id)) {
                throw new \RuntimeException('GitHub returned an incomplete workflow run; current automation health cannot be established.');
            }
            $key = $workflow . ':' . $event;
            $previous = $latest[$key] ?? null;
            if ($previous === null || [strtotime($created), $id, (int) ($run['run_attempt'] ?? 1)]
                > [strtotime((string) $previous['created_at']), $previous['id'], (int) ($previous['run_attempt'] ?? 1)]) {
                $latest[$key] = $run;
            }
        }

        return array_values($latest);
    }

    /**
     * @param list<array{number:int,url:string,created_at:string,queued:bool,checks:array<string,string>,runs:list<array{status:string,conclusion:string}>}> $pullRequests
     * @param list<string> $requiredChecks
     * @param list<array{name:string,status:string,conclusion:string,url:string,created_at:string}> $sourceRuns
     * @return array{status:string,pull_requests:list<array{number:int,url:string,age_hours:int,state:string,reasons:list<string>}>,source_failures:list<array{name:string,status:string,conclusion:string,url:string,created_at:string}>,actionable_count:int}
     */
    public function evaluate(array $pullRequests, array $requiredChecks, array $sourceRuns, int $now, int $maxAgeHours = 168, int $checkGraceHours = 24): array
    {
        $reports = [];
        $actionable = 0;
        foreach ($pullRequests as $pr) {
            $created = strtotime($pr['created_at']);
            if ($created === false) {
                throw new \RuntimeException('Invalid creation timestamp for update PR #' . $pr['number']);
            }
            $age = max(0, (int) floor(($now - $created) / 3600));
            $reasons = [];
            foreach ($pr['runs'] as $run) {
                if (strtolower($run['conclusion']) === 'action_required') {
                    $reasons[] = 'A workflow requires approval before its checks can run.';
                    break;
                }
            }
            foreach ($requiredChecks as $name) {
                $state = strtoupper($pr['checks'][$name] ?? 'MISSING');
                if (in_array($state, ['FAILURE', 'ERROR', 'CANCELLED', 'TIMED_OUT', 'ACTION_REQUIRED', 'STARTUP_FAILURE'], true)) {
                    $reasons[] = 'Required check needs attention: ' . $name . ' (' . $state . ').';
                } elseif ($age >= $checkGraceHours && ! in_array($state, ['SUCCESS', 'NEUTRAL', 'SKIPPED'], true)) {
                    $reasons[] = 'Required check has not completed within the grace period: ' . $name . ' (' . $state . ').';
                }
            }
            if (! $pr['queued'] && $age >= $maxAgeHours) {
                $reasons[] = 'Update exceeds the review age target.';
            }
            $state = $reasons !== [] ? 'action_required' : ($pr['queued'] ? 'queued' : 'healthy');
            if ($reasons !== []) {
                $actionable++;
            }
            $reports[] = ['number' => $pr['number'], 'url' => $pr['url'], 'age_hours' => $age, 'state' => $state, 'reasons' => $reasons];
        }
        $sourceFailures = [];
        foreach ($sourceRuns as $run) {
            $created = strtotime($run['created_at']);
            if ($created === false) {
                throw new \RuntimeException('Invalid source workflow timestamp: ' . $run['name']);
            }
            if ($now - $created >= 48 * 3600) {
                $run['conclusion'] = 'stale_schedule';
                $sourceFailures[] = $run;
            } elseif ($run['status'] === 'completed' && ! in_array($run['conclusion'], ['success', 'neutral', 'skipped'], true)) {
                $sourceFailures[] = $run;
            }
        }
        $actionable += count($sourceFailures);

        return ['status' => $actionable > 0 ? 'action_required' : 'healthy', 'pull_requests' => $reports,
            'source_failures' => $sourceFailures, 'actionable_count' => $actionable];
    }
}
