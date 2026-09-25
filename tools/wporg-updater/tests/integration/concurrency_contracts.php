<?php

declare(strict_types=1);

use WpOrgPluginUpdater\BranchRollbackGuard;
use WpOrgPluginUpdater\GitCommandRunner;
use WpOrgPluginUpdater\MutationLock;

/** @param callable(bool,string):void $assert */
function run_concurrency_contract_tests(callable $assert, string $repoRoot): void
{
    $root = sys_get_temp_dir() . '/wp-core-base-concurrency-' . bin2hex(random_bytes(6));
    mkdir($root, 0700, true);
    $run = static function (string $cwd, array $command, bool $mustSucceed = true): array {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start concurrency test process.');
        }
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($mustSucceed && $status !== 0) {
            throw new RuntimeException(implode(' ', $command) . ': ' . $out . $err);
        }
        return [$status, trim((string) $out)];
    };
    $expectFailure = static function (callable $operation, string $message) use ($assert): void {
        $failed = false;
        try {
            $operation();
        } catch (RuntimeException) {
            $failed = true;
        }
        $assert($failed, $message);
    };
    try {
        $lockRoot = $root . '/locking';
        mkdir($lockRoot);
        file_put_contents($lockRoot . '/manifest.txt', 'old');
        $lock = new MutationLock();
        $lease = $lock->acquire($lockRoot, 'sync');
        $nested = (new MutationLock())->acquire($lockRoot, 'framework-sync');
        $nested->close();
        $assert(is_file($lockRoot . '/.wp-core-base/build/locks/mutation.lock'), 'Different operation names use one persistent repository lock.');
        $worker = $root . '/lock-worker.php';
        file_put_contents($worker, '<?php require ' . var_export($repoRoot . '/tools/wporg-updater/src/Autoload.php', true) . ';'
            . '$lease=(new WpOrgPluginUpdater\\MutationLock())->acquire($argv[1], "add-dependency");'
            . 'file_put_contents($argv[1]."/result.txt", file_get_contents($argv[1]."/manifest.txt"));');
        $process = proc_open([PHP_BINARY, $worker, $lockRoot], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start competing lock worker.');
        }
        usleep(300000);
        $assert(! file_exists($lockRoot . '/result.txt'), 'A different operation blocks while the first operation owns the lock.');
        file_put_contents($lockRoot . '/manifest.txt', 'new');
        $lease->close();
        stream_get_contents($pipes[1]);
        $workerError = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $assert(proc_close($process) === 0, 'Waiting lock worker completed: ' . $workerError);
        $assert(file_get_contents($lockRoot . '/result.txt') === 'new', 'A waiting operation reads state only after obtaining its lease.');

        // Exercise the actual entrypoint: format-manifest must not capture a
        // stale Config while another command still owns the repository lease.
        $manifestPath = $lockRoot . '/.wp-core-base/manifest.php';
        $manifest = require $repoRoot . '/.wp-core-base/manifest.php';
        file_put_contents($manifestPath, '<?php return ' . var_export($manifest, true) . ';');
        $lease = $lock->acquire($lockRoot, 'add-dependency');
        $process = proc_open([PHP_BINARY, $repoRoot . '/tools/wporg-updater/bin/wporg-updater.php', 'format-manifest', '--repo-root=' . $lockRoot], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start CLI lock contract.');
        }
        usleep(300000);
        $assert(proc_get_status($process)['running'], 'The actual CLI waits for the repository lease before loading Config.');
        $manifest['dependencies'][0]['name'] = 'Changed by preceding writer';
        file_put_contents($manifestPath, '<?php return ' . var_export($manifest, true) . ';');
        $lease->close();
        stream_get_contents($pipes[1]);
        $cliError = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $assert(proc_close($process) === 0, 'Waiting format-manifest completed: ' . $cliError);
        $reloaded = require $manifestPath;
        $assert($reloaded['dependencies'][0]['name'] === 'Changed by preceding writer', "The actual CLI preserves the preceding writer's manifest update.");

        $unsafeRoot = $root . '/unsafe';
        mkdir($unsafeRoot);
        symlink($lockRoot . '/.wp-core-base', $unsafeRoot . '/.wp-core-base');
        $expectFailure(fn () => $lock->acquire($unsafeRoot), 'A symlink ancestor cannot redirect the repository lock.');
        $lockPath = $lockRoot . '/.wp-core-base/build/locks/mutation.lock';
        unlink($lockPath);
        symlink($lockRoot . '/manifest.txt', $lockPath);
        $expectFailure(fn () => $lock->acquire($lockRoot), 'A symlink lock cannot truncate another file.');
        $assert(file_get_contents($lockRoot . '/manifest.txt') === 'new', 'Rejected lock symlinks preserve their target.');

        $remote = $root . '/remote.git';
        $one = $root . '/one';
        $two = $root . '/two';
        $run($root, ['git', 'init', '--bare', $remote]);
        $run($root, ['git', 'init', $one]);
        $run($one, ['git', 'config', 'user.email', 'test@example.com']);
        $run($one, ['git', 'config', 'user.name', 'Concurrency Test']);
        $run($one, ['git', 'checkout', '-b', 'main']);
        file_put_contents($one . '/tracked.txt', "baseline\n");
        file_put_contents($one . '/.gitignore', ".wp-core-base/build/\n");
        $run($one, ['git', 'add', '.']);
        $run($one, ['git', 'commit', '-m', 'Baseline']);
        $run($one, ['git', 'remote', 'add', 'origin', $remote]);
        $run($one, ['git', 'push', '-u', 'origin', 'main']);
        $run($root, ['git', 'clone', '--branch', 'main', $remote, $two]);
        $run($two, ['git', 'config', 'user.email', 'test@example.com']);
        $run($two, ['git', 'config', 'user.name', 'Concurrent Actor']);
        $git = new GitCommandRunner($one);
        $baseline = $git->currentRevision();
        // Local refs need the same observation/ownership boundary as remote refs.
        $run($one, ['git', 'branch', 'codex/precheckout', $baseline]);
        $git->expectRemoteRevision('codex/precheckout', null);
        $git->expectLocalRevision('codex/precheckout', $baseline);
        $run($one, ['git', 'checkout', 'codex/precheckout']);
        $run($one, ['git', 'commit', '--allow-empty', '-m', 'External local commit before checkout']);
        $precheckoutActor = $git->currentRevision();
        $run($one, ['git', 'checkout', 'main']);
        $expectFailure(fn () => $git->checkoutBranch('main', 'codex/precheckout', true), 'Checkout must reject a local ref changed since observation.');
        $assert($git->localBranchRevision('codex/precheckout') === $precheckoutActor, 'Checkout must not overwrite an external local commit.');
        $assert($git->currentBranch() === 'main', 'A failed pre-checkout lease must preserve the current checkout.');

        $snapshot = static function (string $checkout) use ($run): array {
            return [
                'head' => $run($checkout, ['git', 'rev-parse', 'HEAD'])[1],
                'branch' => $run($checkout, ['git', 'symbolic-ref', '--quiet', 'HEAD'], false)[1],
                'index' => $run($checkout, ['git', 'ls-files', '--stage', '-z'])[1],
                'status' => $run($checkout, ['git', 'status', '--porcelain', '-z'])[1],
                'contents' => file_get_contents($checkout . '/tracked.txt'),
            ];
        };
        $failedCheckout = static function (GitCommandRunner $runner, string $branch) use ($one): void {
            $guard = new BranchRollbackGuard($one, $runner);
            $guard->begin();
            $guard->trackBranch($branch);
            try {
                $runner->checkoutBranch('main', $branch, true);
                $guard->recordCheckout($branch);
            } catch (Throwable $failure) {
                $guard->rollback($failure);
            }
            throw new LogicException('Fixture checkout unexpectedly succeeded.');
        };

        // update-ref must not bypass Git's protection for another worktree's
        // checked-out branch, even when the observed revision still matches.
        $linked = $root . '/linked checkout';
        $run($one, ['git', 'worktree', 'add', '-b', 'codex/linked-checkout', $linked, $precheckoutActor]);
        file_put_contents($linked . '/tracked.txt', "foreign staged change\n");
        $run($linked, ['git', 'add', 'tracked.txt']);
        file_put_contents($linked . '/tracked.txt', "foreign unstaged change\n");
        $originalState = $snapshot($one);
        $linkedState = $snapshot($linked);
        $linkedRunner = new GitCommandRunner($one);
        $expectFailure(fn () => $failedCheckout($linkedRunner, 'codex/linked-checkout'), 'Checkout rejects a target attached to another linked worktree.');
        $assert($snapshot($one) === $originalState, 'Rejecting a linked checkout preserves the original HEAD, branch, index, status, and files.');
        $assert($snapshot($linked) === $linkedState, 'Rejecting a linked checkout preserves the foreign HEAD, branch, index, staged and unstaged files.');
        $expectFailure(fn () => $linkedRunner->compareAndSwapLocalBranch('codex/linked-checkout', $baseline, $precheckoutActor), 'Direct local CAS cannot rewrite a branch attached to another worktree.');
        $expectFailure(fn () => $linkedRunner->compareAndSwapLocalBranch('codex/linked-checkout', null, $precheckoutActor), 'Direct local CAS cannot delete a branch attached to another worktree.');
        $assert($snapshot($linked) === $linkedState && $snapshot($one) === $originalState, 'Rejected direct CAS preserves both worktrees.');
        $run($one, ['git', 'worktree', 'remove', '--force', $linked]);

        // Hooks report failure after checkout has changed HEAD. Keep failing
        // during recovery too: success cannot depend on a one-shot hook fault.
        $hookPath = $one . '/.git/hooks/post-checkout';
        $hookCountPath = $one . '/.git/checkout-hook-count';
        $hookTemplate = <<<'SH'
#!/bin/sh
count_file="$(git rev-parse --git-path checkout-hook-count)"
count=0
if [ -f "$count_file" ]; then count="$(cat "$count_file")"; fi
count=$((count + 1))
printf '%s\n' "$count" > "$count_file"
if [ "$count" -ge FAILURE_COUNT ]; then exit 1; fi
SH;
        foreach (['detach' => 1, 'attach' => 2] as $phase => $failureCount) {
            $branch = 'codex/hook-' . $phase;
            $run($one, ['git', 'branch', $branch, $precheckoutActor]);
            $before = $snapshot($one);
            file_put_contents($hookPath, str_replace('FAILURE_COUNT', (string) $failureCount, $hookTemplate) . "\n");
            chmod($hookPath, 0755);
            $hookRunner = new GitCommandRunner($one);
            try {
                $expectFailure(fn () => $failedCheckout($hookRunner, $branch), sprintf('A failing %s checkout hook reports the original operation failure.', $phase));
                $assert($snapshot($one) === $before, sprintf('A persistently failing %s hook still restores the original checkout, index, and files.', $phase));
                $assert($hookRunner->localBranchRevision($branch) === $precheckoutActor, sprintf('A failing %s hook restores the previous target ref.', $phase));
                $assert($hookRunner->ownedCheckoutRevision($branch) === null, sprintf('A recovered %s hook failure clears the temporary checkout ownership receipt.', $phase));
            } finally {
                unlink($hookPath);
                if (is_file($hookCountPath)) {
                    unlink($hookCountPath);
                }
                $run($one, ['git', 'checkout', 'main']);
            }
        }

        // A hook stands in for another actor changing a ref during checkout.
        // That ref was observed but never owned by the failed operation.
        $externalBranch = 'codex/hook-external-ref';
        $run($one, ['git', 'branch', $externalBranch, $baseline]);
        $before = $snapshot($one);
        file_put_contents($hookPath, "#!/bin/sh\n"
            . 'git update-ref refs/heads/' . $externalBranch . ' ' . $precheckoutActor . ' ' . $baseline . " || exit 0\nexit 1\n");
        chmod($hookPath, 0755);
        $externalRunner = new GitCommandRunner($one);
        try {
            $expectFailure(fn () => $failedCheckout($externalRunner, $externalBranch), 'A checkout failure after an external ref change is reported.');
            $assert($externalRunner->localBranchRevision($externalBranch) === $precheckoutActor, 'Checkout recovery preserves an external ref update made by the failing hook.');
            $assert($snapshot($one) === $before, 'A hook changing only an external ref still permits recovery of the original checkout.');
        } finally {
            unlink($hookPath);
        }

        $dirtyBranch = 'codex/hook-external-edit';
        $dirtyHook = <<<'SH'
#!/bin/sh
if [ "$(git symbolic-ref --quiet --short HEAD)" = codex/hook-external-edit ]; then
    printf 'external edit during failed checkout\n' > tracked.txt
    exit 1
fi
SH;
        file_put_contents($hookPath, $dirtyHook . "\n");
        chmod($hookPath, 0755);
        $dirtyRunner = new GitCommandRunner($one);
        try {
            $expectFailure(fn () => $failedCheckout($dirtyRunner, $dirtyBranch), 'An external worktree edit during a failed checkout blocks destructive recovery.');
            $assert(file_get_contents($one . '/tracked.txt') === "external edit during failed checkout\n", 'Checkout recovery preserves an external uncommitted edit.');
            $assert($dirtyRunner->localBranchRevision($dirtyBranch) === $baseline, 'Failed recovery retains the created ref as a recovery handle.');
        } finally {
            unlink($hookPath);
            $run($one, ['git', 'restore', '--worktree', '--', 'tracked.txt']);
            $run($one, ['git', 'checkout', 'main']);
        }

        $guard = new BranchRollbackGuard($one, $git);
        $guard->begin();
        $guard->trackBranch('codex/owned');
        $git->checkoutBranch('main', 'codex/owned');
        $guard->recordCheckout('codex/owned');
        $guard->trackMutationPaths(['tracked.txt']);
        file_put_contents($one . '/tracked.txt', "tool update\n");
        $guard->commitAndPush('codex/owned', 'Tool update', ['tracked.txt']);
        $toolRevision = $git->currentRevision();
        $run($two, ['git', 'fetch', 'origin', 'codex/owned']);
        $run($two, ['git', 'checkout', '-b', 'codex/owned', 'origin/codex/owned']);
        file_put_contents($two . '/actor.txt', "actor\n");
        $run($two, ['git', 'add', 'actor.txt']);
        $run($two, ['git', 'commit', '-m', 'Concurrent actor']);
        $run($two, ['git', 'push', 'origin', 'codex/owned']);
        $actorRevision = $run($two, ['git', 'rev-parse', 'HEAD'])[1];
        file_put_contents($one . '/unrelated-sentinel.txt', "keep\n");
        $expectFailure(fn () => $guard->rollback(new RuntimeException('Later API failure')), 'Rollback reports a conflicting remote lease.');
        $assert($git->remoteBranchRevision('codex/owned') === $actorRevision, 'Rollback preserves an intervening remote push.');
        $assert($git->localBranchRevision('codex/owned') === $toolRevision, 'A failed remote rollback retains the local recovery ref.');
        $assert(file_get_contents($one . '/unrelated-sentinel.txt') === "keep\n", 'Rollback preserves unrelated untracked files.');
        $assert($git->currentBranch() === 'main', 'Rollback restores the original checkout independently of a remote conflict.');
        unlink($one . '/unrelated-sentinel.txt');

        // A successful owned push can be deleted only while its exact SHA remains.
        $guard = new BranchRollbackGuard($one, $git);
        $guard->begin();
        $guard->trackBranch('codex/new');
        $git->checkoutBranch('main', 'codex/new');
        $guard->recordCheckout('codex/new');
        file_put_contents($one . '/tracked.txt', "new branch\n");
        $guard->commitAndPush('codex/new', 'New branch', ['tracked.txt']);
        $expectFailure(fn () => $guard->rollback(new RuntimeException('PR creation failed')), 'Owned branch rollback rethrows the original error.');
        $assert($git->remoteBranchRevision('codex/new') === null, 'Rollback deletes the exact newly created remote ref.');
        $assert($git->localBranchRevision('codex/new') === null, 'Rollback deletes the exact newly created local ref.');

        // Merely observing an existing branch never authorizes remote rollback.
        $guard = new BranchRollbackGuard($one, $git);
        $guard->begin();
        $guard->trackBranch('codex/owned');
        $expectFailure(fn () => $guard->rollback(new RuntimeException('Download failed before checkout')), 'Pre-push failure propagates.');
        $assert($git->remoteBranchRevision('codex/owned') === $actorRevision, 'Pre-push failure does not write any remote ref.');

        // Scoped recovery leaves other paths intact, including unusual names.
        file_put_contents($one . '/ space file.txt', "baseline whitespace\n");
        $run($one, ['git', 'add', ' space file.txt']);
        $run($one, ['git', 'commit', '-m', 'Whitespace path baseline']);
        $whitespaceBaseline = $git->currentRevision();
        file_put_contents($one . '/ space file.txt', "changed\n");
        file_put_contents($one . '/untracked.txt', "external\n");
        $git->restoreMutationPaths($whitespaceBaseline, [' space file.txt']);
        $assert(file_get_contents($one . '/ space file.txt') === "baseline whitespace\n", 'Scoped rollback preserves exact whitespace-bearing filenames.');
        $assert(file_get_contents($one . '/untracked.txt') === "external\n", 'Scoped rollback leaves unrelated new files intact.');
        unlink($one . '/untracked.txt');

        $guard = new BranchRollbackGuard($one, $git);
        $guard->begin();
        $guard->trackBranch('codex/local-actor');
        $git->checkoutBranch('main', 'codex/local-actor');
        $guard->recordCheckout('codex/local-actor');
        $guard->trackMutationPaths(['tracked.txt']);
        file_put_contents($one . '/tracked.txt', "external local commit\n");
        $run($one, ['git', 'commit', '-am', 'External local commit']);
        $externalLocal = $git->currentRevision();
        $expectFailure(fn () => $guard->rollback(new RuntimeException('Operation failed')), 'Local ownership changes produce a recovery error.');
        $assert($git->localBranchRevision('codex/local-actor') === $externalLocal, 'Local compare-and-swap recovery preserves an intervening commit.');

        $git->expectRemoteRevision('codex/same-checkout', null);
        $git->checkoutBranch('main', 'codex/same-checkout');
        $sameBaseline = $git->currentRevision();
        $run($one, ['git', 'push', 'origin', 'codex/same-checkout']);
        $guard = new BranchRollbackGuard($one, $git);
        $guard->begin();
        $guard->trackBranch('codex/same-checkout');
        $git->checkoutBranch('main', 'codex/same-checkout');
        $guard->recordCheckout('codex/same-checkout');
        file_put_contents($one . '/tracked.txt', "same branch update\n");
        $guard->commitAndPush('codex/same-checkout', 'Same branch update', ['tracked.txt']);
        $expectFailure(fn () => $guard->rollback(new RuntimeException('Later API failure')), 'Rollback rethrows an error when started on its managed branch.');
        $assert($git->currentBranch() === 'codex/same-checkout' && $git->currentRevision() === $sameBaseline, 'Local CAS safely restores an originally checked-out managed branch.');
        $assert($git->remoteBranchRevision('codex/same-checkout') === $sameBaseline, 'Remote CAS restores the originally checked-out branch.');
        $git->checkoutRef('main');

        // A force refresh uses the pre-mutation lease, never a fresh observation.
        $git->expectRemoteRevision('codex/owned', $actorRevision);
        $git->checkoutBranch('main', 'codex/owned', true);
        file_put_contents($two . '/actor.txt', "second actor change\n");
        $run($two, ['git', 'commit', '-am', 'Second actor update']);
        $run($two, ['git', 'push', 'origin', 'codex/owned']);
        $secondActor = $run($two, ['git', 'rev-parse', 'HEAD'])[1];
        file_put_contents($one . '/tracked.txt', "rebuilt refresh\n");
        $expectFailure(fn () => $git->commitAndPush('codex/owned', 'Refresh', ['tracked.txt'], true), 'Force refresh rejects a changed remote lease.');
        $assert($git->remoteBranchRevision('codex/owned') === $secondActor, 'Forward refresh preserves an intervening remote push.');
        $assert($git->currentRevision() !== $baseline && $git->currentRevision() !== $toolRevision, 'Rejected push preserves its local recovery commit.');
        $run($one, ['git', 'remote', 'set-url', 'origin', $root . '/missing.git']);
        $expectFailure(fn () => $git->remoteBranchRevision('missing'), 'Remote transport errors cannot be interpreted as absent branches.');
    } finally {
        $remove = static function (string $path) use (&$remove): void {
            if (is_link($path) || is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                foreach (scandir($path) ?: [] as $entry) {
                    if ($entry !== '.' && $entry !== '..') {
                        $remove($path . '/' . $entry);
                    }
                }
                rmdir($path);
            }
        };
        $remove($root);
    }
}
