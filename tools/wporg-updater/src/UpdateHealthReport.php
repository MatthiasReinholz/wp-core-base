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
     * @param list<array{number:int,url:string,closed_at:string,branch:string,expected_head:string,observed_head:?string,reused_by_open_pr:bool,identity_error:?string}> $closures
     * @return array{pull_requests:list<array{number:int,url:string,closed_at:string,branch:string,age_hours:int,state:string,reason:string}>,actionable_count:int}
     */
    public function evaluateCleanup(array $closures, int $now, int $graceHours = 1): array
    {
        $reports = [];
        $actionable = 0;
        foreach ($closures as $closure) {
            $closed = strtotime($closure['closed_at']);
            if ($closed === false || $closed > $now) {
                throw new \RuntimeException('Invalid closure timestamp for update PR #' . $closure['number']);
            }
            $age = (int) floor(($now - $closed) / 3600);
            if ($closure['identity_error'] === null && $closure['observed_head'] === null) {
                $state = 'cleaned';
                $reason = 'The managed branch is absent.';
            } elseif ($closure['identity_error'] === null && $closure['reused_by_open_pr']) {
                $state = 'reused';
                $reason = 'A current same-repository open PR uses this branch; preserve it.';
            } elseif ($age < $graceHours) {
                $state = 'pending';
                $reason = 'The close event is still within the cleanup grace period.';
            } else {
                $actionable++;
                if ($closure['identity_error'] !== null) {
                    $state = 'manual_review';
                    $reason = 'Cleanup ownership cannot be established: ' . $closure['identity_error'];
                } elseif ($closure['expected_head'] === '' || $closure['expected_head'] !== $closure['observed_head']) {
                    $state = 'manual_review';
                    $reason = 'The retained branch does not match the closed PR head; review ownership before any cleanup.';
                } else {
                    $state = 'cleanup_required';
                    $reason = 'The unchanged managed branch remains after the cleanup grace period.';
                }
            }
            $reports[] = ['number' => $closure['number'], 'url' => $closure['url'], 'closed_at' => $closure['closed_at'],
                'branch' => $closure['branch'], 'age_hours' => $age, 'state' => $state, 'reason' => $reason];
        }

        return ['pull_requests' => $reports, 'actionable_count' => $actionable];
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
