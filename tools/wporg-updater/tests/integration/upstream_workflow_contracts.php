<?php

declare(strict_types=1);

/**
 * @param callable(bool,string):void $assert
 */
function run_upstream_workflow_contract_tests(
    callable $assert,
    string $repoRoot,
    string $checkoutActionSha,
    string $setupPhpActionSha
): void {
    $upstreamUpdatesWorkflow = (string) file_get_contents($repoRoot . '/.github/workflows/wporg-updates.yml');
    $upstreamReconcileWorkflow = (string) file_get_contents($repoRoot . '/.github/workflows/wporg-updates-reconcile.yml');
    $upstreamValidateWorkflow = (string) file_get_contents($repoRoot . '/.github/workflows/wporg-validate-runtime.yml');
    $upstreamFinalizeWorkflow = (string) file_get_contents($repoRoot . '/.github/workflows/finalize-wp-core-base-release.yml');
    $upstreamRecoveryReleaseWorkflow = (string) file_get_contents($repoRoot . '/.github/workflows/release-wp-core-base.yml');
    $upstreamBlockerWorkflow = (string) file_get_contents($repoRoot . '/.github/workflows/wporg-update-pr-blocker.yml');
    $upstreamPrepareReleaseWorkflow = (string) file_get_contents($repoRoot . '/.github/workflows/prepare-wp-core-base-release.yml');

    $assert(str_contains($upstreamUpdatesWorkflow, $checkoutActionSha), 'Expected upstream updates workflow to pin actions/checkout by full commit SHA.');
    $assert(str_contains($upstreamUpdatesWorkflow, $setupPhpActionSha), 'Expected upstream updates workflow to pin setup-php by full commit SHA.');
    $assert(! str_contains($upstreamUpdatesWorkflow, 'pull_request_target:'), 'Expected upstream updates workflow to keep scheduled/manual execution separate from PR reconciliation.');
    $assert(str_contains($upstreamReconcileWorkflow, $checkoutActionSha), 'Expected upstream reconciliation workflow to pin actions/checkout by full commit SHA.');
    $assert(str_contains($upstreamReconcileWorkflow, $setupPhpActionSha), 'Expected upstream reconciliation workflow to pin setup-php by full commit SHA.');
    $assert(str_contains($upstreamValidateWorkflow, "push:\n    branches:\n      - main"), 'Expected upstream CI workflow to validate the exact merged release commit on pushes to main.');
    $assert(str_contains($upstreamReconcileWorkflow, "github.event.pull_request.merged == true"), 'Expected upstream reconciliation workflow to narrow closed-PR reconciliation to merged PRs.');
    $assert(str_contains($upstreamReconcileWorkflow, 'managed-pr-cleanup --pr-number="$PR_NUMBER"'), 'Expected upstream reconciliation workflow to clean branches for closed managed PRs.');
    $assert(str_contains($upstreamReconcileWorkflow, 'ref: ${{ github.event.repository.default_branch }}'), 'Expected upstream managed branch cleanup to execute trusted default-branch code.');
    $assert(str_contains($upstreamReconcileWorkflow, "automation:framework-update"), 'Expected upstream reconciliation workflow to limit closed-PR reconciliation to framework automation PRs.');
    $assert(str_contains($upstreamReconcileWorkflow, 'workflow_dispatch:'), 'Expected upstream reconciliation workflow to include manual recovery trigger coverage.');
    $assert(str_contains($upstreamReconcileWorkflow, 'schedule:'), 'Expected upstream reconciliation workflow to include scheduled recovery trigger coverage.');
    $assert(str_contains($upstreamReconcileWorkflow, "github.event_name == 'workflow_dispatch'"), 'Expected upstream reconciliation workflow to run sync during manual recovery dispatch.');
    $assert(str_contains($upstreamReconcileWorkflow, "github.event_name == 'schedule'"), 'Expected upstream reconciliation workflow to run sync during scheduled recovery runs.');
    $assert(str_contains($upstreamReconcileWorkflow, "sync:\n    timeout-minutes: 30"), 'Expected upstream reconciliation sync to have a bounded runtime.');
    $assert(preg_match('/^concurrency:/m', $upstreamReconcileWorkflow) !== 1, 'Expected upstream reconciliation to retain each PR cleanup instead of coalescing entire workflow runs.');
    $assert(str_contains($upstreamReconcileWorkflow, "    concurrency:\n      group: wp-core-base-managed-pr-cleanup-\${{ github.event.pull_request.number }}\n      cancel-in-progress: false"), 'Expected upstream cleanup concurrency to distinguish closed PRs and preserve active cleanup.');
    $assert(str_contains($upstreamReconcileWorkflow, "sync:\n    timeout-minutes: 30\n    concurrency:\n      group: wp-core-base-dependency-sync\n      cancel-in-progress: false"), 'Expected upstream sync alone to share the updater mutation queue.');
    $assert(
        str_contains($upstreamPrepareReleaseWorkflow, 'peter-evans/create-pull-request@c0f553fe549906ede9cf27b5156039d195d2ece0'),
        'Expected prepare release workflow to pin peter-evans/create-pull-request by full commit SHA.'
    );
    $assert(str_contains($upstreamBlockerWorkflow, 'pr-blocker-reconcile'), 'Expected upstream blocker workflow to include reconciliation scan mode.');
    $assert(str_contains($upstreamBlockerWorkflow, 'workflow_dispatch:'), 'Expected upstream blocker workflow to include manual retry trigger coverage.');
    $assert(str_contains($upstreamBlockerWorkflow, 'schedule:'), 'Expected upstream blocker workflow to include scheduled retry trigger coverage.');
    $assert(str_contains($upstreamFinalizeWorkflow, 'wp-core-base-vendor-snapshot.zip.sha256'), 'Expected finalize release workflow to publish a SHA-256 checksum asset.');
    $assert(str_contains($upstreamFinalizeWorkflow, 'wp-core-base-vendor-snapshot.zip.sha256.sig'), 'Expected finalize release workflow to publish a detached checksum signature asset.');
    $assert(str_contains($upstreamFinalizeWorkflow, 'build-release-artifact'), 'Expected finalize release workflow to build the vendored snapshot through the framework artifact builder.');
    $assert(str_contains($upstreamFinalizeWorkflow, 'release-sign'), 'Expected finalize release workflow to create a detached release signature.');
    $assert(str_contains($upstreamFinalizeWorkflow, 'check_framework_release_ci.sh'), 'Expected finalize release workflow to verify the merged release PR passed CI before publishing.');
    $assert(str_contains($upstreamFinalizeWorkflow, 'publish_framework_release.sh'), 'Expected finalize workflow to delegate publication to ownership-aware helper.');
    $assert(str_contains($upstreamFinalizeWorkflow, '--source-revision="$SOURCE_COMMIT"'), 'Expected finalize workflow to build the exact verified commit.');
    $assert(! str_contains($upstreamFinalizeWorkflow, 'failure()') && ! str_contains($upstreamFinalizeWorkflow, 'git push --delete'), 'Expected no unconditional workflow rollback of pre-existing releases/tags.');
    $assert(str_contains($upstreamFinalizeWorkflow, "group: wp-core-base-release-\${{ github.event.pull_request.head.ref }}"), 'Expected finalize release workflow to serialize publication by release branch/version.');
    $assert(str_contains($upstreamRecoveryReleaseWorkflow, 'wp-core-base-vendor-snapshot.zip.sha256'), 'Expected manual release workflow to publish a SHA-256 checksum asset.');
    $assert(str_contains($upstreamRecoveryReleaseWorkflow, 'wp-core-base-vendor-snapshot.zip.sha256.sig'), 'Expected manual release workflow to publish a detached checksum signature asset.');
    $assert(str_contains($upstreamRecoveryReleaseWorkflow, 'build-release-artifact'), 'Expected manual release workflow to build the vendored snapshot through the framework artifact builder.');
    $assert(str_contains($upstreamRecoveryReleaseWorkflow, 'release-sign'), 'Expected manual release workflow to create a detached release signature.');
    $assert(str_contains($upstreamRecoveryReleaseWorkflow, 'check_framework_release_ci.sh'), 'Expected manual recovery release workflow to verify the merged release PR passed CI before publishing.');
    $assert(str_contains($upstreamRecoveryReleaseWorkflow, 'publish_framework_release.sh'), 'Expected manual recovery to share ownership-aware publication.');
    foreach ([$upstreamFinalizeWorkflow, $upstreamRecoveryReleaseWorkflow] as $publisher) {
        $assert(str_contains($publisher, "pull-requests: read") && str_contains($publisher, "actions: read"), 'Expected publication gates to declare permission to read PR and Actions evidence.');
        $assert(str_contains($publisher, "if: always()") && str_contains($publisher, 'cat "$journal"') && str_contains($publisher, '"$GITHUB_STEP_SUMMARY"'), 'Expected publication receipts to survive disposable hosted runners in the job summary.');
    }
    $assert(! str_contains($upstreamRecoveryReleaseWorkflow, '--clobber'), 'Expected existing published assets to remain immutable during recovery.');
    $assert(str_contains($upstreamRecoveryReleaseWorkflow, "group: wp-core-base-release-release/\${{ inputs.version }}"), 'Expected manual recovery release workflow to serialize publication by release version.');
    $assert(str_contains($upstreamValidateWorkflow, '--artifact=dist/wp-core-base-vendor-snapshot.zip'), 'Expected CI release verification to validate the built release artifact, not only release metadata.');
    $assert(str_contains($upstreamValidateWorkflow, '--checksum-file=dist/wp-core-base-vendor-snapshot.zip.sha256'), 'Expected CI release verification to validate the built checksum sidecar.');
    $assert(str_contains($upstreamValidateWorkflow, '--signature-file=dist/wp-core-base-vendor-snapshot.zip.sha256.sig'), 'Expected CI release verification to validate the detached checksum signature.');
    $assert(str_contains($upstreamValidateWorkflow, 'phpstan.phar" analyse --configuration=phpstan.neon.dist'), 'Expected CI to run PHPStan as a framework integrity check.');
    $assert(str_contains($upstreamValidateWorkflow, 'actionlint" -color'), 'Expected CI to lint GitHub workflows with actionlint.');
    $assert(str_contains($upstreamValidateWorkflow, 'shellcheck" scripts/ci/*.sh'), 'Expected CI to lint critical release scripts with shellcheck.');
    $assert(str_contains($upstreamValidateWorkflow, 'bash scripts/ci/test_release_scripts.sh'), 'Expected CI to execute fixture-driven release helper script tests.');
    $assert(str_contains($upstreamValidateWorkflow, 'verify_downstream_fixture.php --profile="$FIXTURE_PROFILE"'), 'Expected CI to exercise both downstream fixture profiles.');
}
