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

    $assert(str_contains($upstreamUpdatesWorkflow, $checkoutActionSha), 'Expected upstream updates workflow to pin actions/checkout by full commit SHA.');
    $assert(str_contains($upstreamUpdatesWorkflow, $setupPhpActionSha), 'Expected upstream updates workflow to pin setup-php by full commit SHA.');
    $assert(! str_contains($upstreamUpdatesWorkflow, 'pull_request_target:'), 'Expected upstream updates workflow to keep scheduled/manual execution separate from PR reconciliation.');
    $assert(str_contains($upstreamReconcileWorkflow, $checkoutActionSha), 'Expected upstream reconciliation workflow to pin actions/checkout by full commit SHA.');
    $assert(str_contains($upstreamReconcileWorkflow, $setupPhpActionSha), 'Expected upstream reconciliation workflow to pin setup-php by full commit SHA.');
    $assert(str_contains($upstreamValidateWorkflow, "push:\n    branches:\n      - main"), 'Expected upstream CI workflow to validate the exact merged release commit on pushes to main.');
    $assert(str_contains($upstreamReconcileWorkflow, "github.event.pull_request.merged == true"), 'Expected upstream reconciliation workflow to narrow closed-PR reconciliation to merged PRs.');
    $assert(str_contains($upstreamReconcileWorkflow, "automation:framework-update"), 'Expected upstream reconciliation workflow to limit closed-PR reconciliation to framework automation PRs.');
    $assert(str_contains($upstreamReconcileWorkflow, 'workflow_dispatch:'), 'Expected upstream reconciliation workflow to include manual recovery trigger coverage.');
    $assert(str_contains($upstreamReconcileWorkflow, 'schedule:'), 'Expected upstream reconciliation workflow to include scheduled recovery trigger coverage.');
    $assert(str_contains($upstreamReconcileWorkflow, "github.event_name == 'workflow_dispatch'"), 'Expected upstream reconciliation workflow to run sync during manual recovery dispatch.');
    $assert(str_contains($upstreamReconcileWorkflow, "github.event_name == 'schedule'"), 'Expected upstream reconciliation workflow to run sync during scheduled recovery runs.');
    $assert(str_contains($upstreamBlockerWorkflow, 'pr-blocker-reconcile'), 'Expected upstream blocker workflow to include reconciliation scan mode.');
    $assert(str_contains($upstreamBlockerWorkflow, 'workflow_dispatch:'), 'Expected upstream blocker workflow to include manual retry trigger coverage.');
    $assert(str_contains($upstreamBlockerWorkflow, 'schedule:'), 'Expected upstream blocker workflow to include scheduled retry trigger coverage.');
    $assert(str_contains($upstreamFinalizeWorkflow, 'wp-core-base-vendor-snapshot.zip.sha256'), 'Expected finalize release workflow to publish a SHA-256 checksum asset.');
    $assert(str_contains($upstreamFinalizeWorkflow, 'wp-core-base-vendor-snapshot.zip.sha256.sig'), 'Expected finalize release workflow to publish a detached checksum signature asset.');
    $assert(str_contains($upstreamFinalizeWorkflow, 'build-release-artifact'), 'Expected finalize release workflow to build the vendored snapshot through the framework artifact builder.');
    $assert(str_contains($upstreamFinalizeWorkflow, 'release-sign'), 'Expected finalize release workflow to create a detached release signature.');
    $assert(str_contains($upstreamFinalizeWorkflow, 'check_framework_release_ci.sh'), 'Expected finalize release workflow to verify the merged release PR passed CI before publishing.');
    $assert(str_contains($upstreamFinalizeWorkflow, 'check_framework_release_assets.sh'), 'Expected finalize release workflow to verify the published release assets match the built snapshot.');
    $assert(str_contains($upstreamFinalizeWorkflow, 'overwrite_files: true'), 'Expected finalize release workflow to allow idempotent release asset repair.');
    $assert(str_contains($upstreamFinalizeWorkflow, "git push --delete origin"), 'Expected finalize release workflow to roll back the pushed tag when release publishing fails.');
    $assert(str_contains($upstreamFinalizeWorkflow, "group: wp-core-base-release-\${{ github.event.pull_request.head.ref }}"), 'Expected finalize release workflow to serialize publication by release branch/version.');
    $assert(str_contains($upstreamRecoveryReleaseWorkflow, 'wp-core-base-vendor-snapshot.zip.sha256'), 'Expected manual release workflow to publish a SHA-256 checksum asset.');
    $assert(str_contains($upstreamRecoveryReleaseWorkflow, 'wp-core-base-vendor-snapshot.zip.sha256.sig'), 'Expected manual release workflow to publish a detached checksum signature asset.');
    $assert(str_contains($upstreamRecoveryReleaseWorkflow, 'build-release-artifact'), 'Expected manual release workflow to build the vendored snapshot through the framework artifact builder.');
    $assert(str_contains($upstreamRecoveryReleaseWorkflow, 'release-sign'), 'Expected manual release workflow to create a detached release signature.');
    $assert(str_contains($upstreamRecoveryReleaseWorkflow, 'check_framework_release_ci.sh'), 'Expected manual recovery release workflow to verify the merged release PR passed CI before publishing.');
    $assert(str_contains($upstreamRecoveryReleaseWorkflow, 'check_framework_release_assets.sh'), 'Expected manual recovery release workflow to compare existing release assets to the current built snapshot.');
    $assert(str_contains($upstreamRecoveryReleaseWorkflow, 'overwrite_files: true'), 'Expected manual recovery release workflow to repair stale release assets in place.');
    $assert(str_contains($upstreamRecoveryReleaseWorkflow, 'already contains the current verified assets; nothing to publish.'), 'Expected manual recovery release workflow to skip only when the GitHub Release already matches the current verified assets.');
    $assert(str_contains($upstreamRecoveryReleaseWorkflow, "group: wp-core-base-release-release/\${{ inputs.version }}"), 'Expected manual recovery release workflow to serialize publication by release version.');
    $assert(str_contains($upstreamValidateWorkflow, '--artifact=dist/wp-core-base-vendor-snapshot.zip'), 'Expected CI release verification to validate the built release artifact, not only release metadata.');
    $assert(str_contains($upstreamValidateWorkflow, '--checksum-file=dist/wp-core-base-vendor-snapshot.zip.sha256'), 'Expected CI release verification to validate the built checksum sidecar.');
    $assert(str_contains($upstreamValidateWorkflow, '--signature-file=dist/wp-core-base-vendor-snapshot.zip.sha256.sig'), 'Expected CI release verification to validate the detached checksum signature.');
    $assert(str_contains($upstreamValidateWorkflow, 'phpstan analyse --configuration=phpstan.neon.dist'), 'Expected CI to run PHPStan as a framework integrity check.');
    $assert(str_contains($upstreamValidateWorkflow, '/tmp/actionlint -color'), 'Expected CI to lint GitHub workflows with actionlint.');
    $assert(str_contains($upstreamValidateWorkflow, 'shellcheck scripts/ci/*.sh'), 'Expected CI to lint critical release scripts with shellcheck.');
    $assert(str_contains($upstreamValidateWorkflow, 'bash scripts/ci/test_release_scripts.sh'), 'Expected CI to execute fixture-driven release helper script tests.');
    $assert(str_contains($upstreamValidateWorkflow, 'verify_downstream_fixture.php --profile=${{ matrix.profile }}'), 'Expected CI to exercise both downstream fixture profiles.');
}
