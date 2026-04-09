<?php

declare(strict_types=1);

use WpOrgPluginUpdater\DownstreamScaffolder;
use WpOrgPluginUpdater\FrameworkConfig;

/**
 * @param callable(bool,string):void $assert
 * @param callable(string):string $normalizeWorkflowExample
 */
function run_workflow_contract_tests(
    callable $assert,
    string $repoRoot,
    string $tempScaffoldRoot,
    string $checkoutActionSha,
    string $setupPhpActionSha,
    callable $normalizeWorkflowExample
): void {
    $scaffoldedManifest = (string) file_get_contents($tempScaffoldRoot . '/.wp-core-base/manifest.php');
    $scaffoldedUsage = (string) file_get_contents($tempScaffoldRoot . '/.wp-core-base/USAGE.md');
    $scaffoldedAgents = (string) file_get_contents($tempScaffoldRoot . '/AGENTS.md');
    $scaffoldedWorkflow = (string) file_get_contents($tempScaffoldRoot . '/.github/workflows/wporg-updates.yml');
    $scaffoldedReconcileWorkflow = (string) file_get_contents($tempScaffoldRoot . '/.github/workflows/wporg-updates-reconcile.yml');
    $scaffoldedBlocker = (string) file_get_contents($tempScaffoldRoot . '/.github/workflows/wporg-update-pr-blocker.yml');
    $scaffoldedValidate = (string) file_get_contents($tempScaffoldRoot . '/.github/workflows/wporg-validate-runtime.yml');
    $documentedWorkflow = (string) file_get_contents($repoRoot . '/docs/examples/downstream-workflow.yml');
    $documentedReconcileWorkflow = (string) file_get_contents($repoRoot . '/docs/examples/downstream-updates-reconcile-workflow.yml');
    $documentedBlockerWorkflow = (string) file_get_contents($repoRoot . '/docs/examples/downstream-pr-blocker-workflow.yml');
    $documentedValidateWorkflow = (string) file_get_contents($repoRoot . '/docs/examples/downstream-validate-runtime-workflow.yml');
    $assert(str_contains($scaffoldedManifest, "'profile' => 'content-only'"), 'Expected scaffolded manifest to set the requested profile.');
    $assert(str_contains($scaffoldedManifest, "'content_root' => 'cms'"), 'Expected scaffolded manifest to set the requested content root.');
    $assert(str_contains($scaffoldedManifest, "'manifest_mode' => 'strict'"), 'Expected scaffolded manifest to include manifest mode.');
    $assert(str_contains($scaffoldedManifest, "'validation_mode' => 'source-clean'"), 'Expected scaffolded manifest to include validation mode.');
    $assert(str_contains($scaffoldedManifest, "'ownership_roots' =>"), 'Expected scaffolded manifest to include ownership roots.');
    $assert(str_contains($scaffoldedManifest, "'managed_kinds' => ["), 'Expected scaffolded manifest to include managed_kinds.');
    $assert(str_contains($scaffoldedManifest, "'kind' => 'mu-plugin-file'"), 'Expected scaffolded manifest to document local MU plugin files.');
    $assert(str_contains($scaffoldedManifest, "'kind' => 'runtime-directory'"), 'Expected scaffolded manifest to document runtime directories.');
    $assert(str_contains($scaffoldedUsage, 'vendor/wp-core-base/bin/wp-core-base add-dependency'), 'Expected scaffolded usage guide to point at the vendored wrapper for routine dependency authoring.');
    $assert(str_contains($scaffoldedUsage, '.wp-core-base/manifest.php'), 'Expected scaffolded usage guide to explain the manifest source of truth.');
    $assert(str_contains($scaffoldedAgents, '.wp-core-base/USAGE.md'), 'Expected scaffolded downstream AGENTS.md to point agents at the local usage guide first.');
    $assert(str_contains($scaffoldedAgents, 'Do not start by hand-editing `.wp-core-base/manifest.php`'), 'Expected scaffolded downstream AGENTS.md to steer agents toward the CLI-first workflow.');
    $assert(str_contains($scaffoldedAgents, 'GitHub Release Trust Checks'), 'Expected scaffolded downstream AGENTS.md to include GitHub release trust-check guidance.');
    $assert(str_contains($scaffoldedAgents, 'source_config.checksum_asset_pattern'), 'Expected scaffolded downstream AGENTS.md to tell agents where checksum sidecar patterns belong.');
    $assert(str_contains($scaffoldedAgents, 'Do not guess checksum patterns from tag names alone.'), 'Expected scaffolded downstream AGENTS.md to warn agents against guessing checksum patterns.');
    $assert(str_contains($scaffoldedWorkflow, 'php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php sync'), 'Expected scaffolded workflow to target the configured tool path.');
    $assert(str_contains($scaffoldedWorkflow, 'WPORG_REPO_ROOT: ${{ github.workspace }}'), 'Expected scaffolded workflow to set WPORG_REPO_ROOT so sync runs against the downstream repo.');
    $assert(! str_contains($scaffoldedWorkflow, 'pull_request_target:'), 'Expected scaffolded updates workflow to keep scheduled/manual execution separate from PR reconciliation.');
    $assert(str_contains($scaffoldedBlocker, 'contents: read'), 'Expected scaffolded blocker workflow to grant contents: read for actions/checkout.');
    $assert(str_contains($scaffoldedValidate, 'stage-runtime'), 'Expected scaffolded validation workflow to stage runtime output.');
    $assert(str_contains($scaffoldedWorkflow, $checkoutActionSha), 'Expected scaffolded updates workflow to pin actions/checkout by full commit SHA.');
    $assert(str_contains($scaffoldedWorkflow, $setupPhpActionSha), 'Expected scaffolded updates workflow to pin setup-php by full commit SHA.');
    $assert(str_contains($scaffoldedReconcileWorkflow, $checkoutActionSha), 'Expected scaffolded reconciliation workflow to pin actions/checkout by full commit SHA.');
    $assert(str_contains($scaffoldedReconcileWorkflow, $setupPhpActionSha), 'Expected scaffolded reconciliation workflow to pin setup-php by full commit SHA.');
    $assert(str_contains($scaffoldedReconcileWorkflow, "github.event.pull_request.merged == true"), 'Expected scaffolded reconciliation workflow to narrow closed-PR reconciliation to merged PRs.');
    $assert(str_contains($scaffoldedReconcileWorkflow, "automation:dependency-update"), 'Expected scaffolded reconciliation workflow to gate merged-PR reconciliation to framework automation PRs.');
    $assert(str_contains($scaffoldedBlocker, $checkoutActionSha), 'Expected scaffolded blocker workflow to pin actions/checkout by full commit SHA.');
    $assert(str_contains($scaffoldedBlocker, $setupPhpActionSha), 'Expected scaffolded blocker workflow to pin setup-php by full commit SHA.');
    $assert(str_contains($scaffoldedValidate, $checkoutActionSha), 'Expected scaffolded validation workflow to pin actions/checkout by full commit SHA.');
    $assert(str_contains($scaffoldedValidate, $setupPhpActionSha), 'Expected scaffolded validation workflow to pin setup-php by full commit SHA.');
    $assert($normalizeWorkflowExample($scaffoldedWorkflow) === $normalizeWorkflowExample($documentedWorkflow), 'Expected downstream updates example workflow to match scaffolded output.');
    $assert($normalizeWorkflowExample($scaffoldedReconcileWorkflow) === $normalizeWorkflowExample($documentedReconcileWorkflow), 'Expected downstream reconciliation example workflow to match scaffolded output.');
    $assert($normalizeWorkflowExample($scaffoldedBlocker) === $normalizeWorkflowExample($documentedBlockerWorkflow), 'Expected downstream blocker example workflow to match scaffolded output.');
    $assert($normalizeWorkflowExample($scaffoldedValidate) === $normalizeWorkflowExample($documentedValidateWorkflow), 'Expected downstream validation example workflow to match scaffolded output.');
    $scaffoldedFramework = FrameworkConfig::load($tempScaffoldRoot);
    $assert($scaffoldedFramework->distributionPath() === 'vendor/wp-core-base', 'Expected scaffolded framework metadata to point at the vendored framework path.');
    $scaffoldedFrameworkWorkflow = (string) file_get_contents($tempScaffoldRoot . '/.github/workflows/wp-core-base-self-update.yml');
    $documentedFrameworkWorkflow = (string) file_get_contents($repoRoot . '/docs/examples/downstream-framework-self-update-workflow.yml');
    $assert(str_contains($scaffoldedFrameworkWorkflow, 'framework-sync --repo-root=.'), 'Expected scaffolded self-update workflow to run framework-sync.');
    $assert(str_contains($scaffoldedFrameworkWorkflow, $checkoutActionSha), 'Expected scaffolded framework self-update workflow to pin actions/checkout by full commit SHA.');
    $assert(str_contains($scaffoldedFrameworkWorkflow, $setupPhpActionSha), 'Expected scaffolded framework self-update workflow to pin setup-php by full commit SHA.');
    $assert($normalizeWorkflowExample($scaffoldedFrameworkWorkflow) === $normalizeWorkflowExample($documentedFrameworkWorkflow), 'Expected downstream framework self-update example workflow to match scaffolded output.');
    $renderedManagedFiles = (new DownstreamScaffolder(dirname(__DIR__, 4), $tempScaffoldRoot))->renderFrameworkManagedFiles('vendor/wp-core-base');
    $workflowExampleMap = [
        'docs/examples/downstream-workflow.yml' => '.github/workflows/wporg-updates.yml',
        'docs/examples/downstream-updates-reconcile-workflow.yml' => '.github/workflows/wporg-updates-reconcile.yml',
        'docs/examples/downstream-pr-blocker-workflow.yml' => '.github/workflows/wporg-update-pr-blocker.yml',
        'docs/examples/downstream-validate-runtime-workflow.yml' => '.github/workflows/wporg-validate-runtime.yml',
        'docs/examples/downstream-framework-self-update-workflow.yml' => '.github/workflows/wp-core-base-self-update.yml',
    ];
    $extractWorkflowPermissions = static function (string $contents): string {
        if (preg_match('/^permissions:\n(?:(?:  .*\n)+)/m', $contents, $matches) === 1) {
            return trim($matches[0]);
        }

        throw new RuntimeException('Workflow did not contain a permissions block.');
    };
    $extractRunCommands = static function (string $contents): array {
        preg_match_all('/^\s*run:\s*(.+)$/m', $contents, $matches);
        return array_values(array_map(static fn (string $command): string => trim($command), $matches[1]));
    };

    foreach ($workflowExampleMap as $examplePath => $managedPath) {
        $exampleContents = (string) file_get_contents($repoRoot . '/' . $examplePath);
        $managedContents = (string) $renderedManagedFiles[$managedPath];
        $assert(
            $extractWorkflowPermissions($exampleContents) === $extractWorkflowPermissions($managedContents),
            sprintf('Expected example workflow %s to keep permissions in parity with %s.', $examplePath, $managedPath)
        );

        foreach ($extractRunCommands($managedContents) as $command) {
            $assert(
                in_array($command, $extractRunCommands($exampleContents), true),
                sprintf('Expected example workflow %s to retain command parity for `%s`.', $examplePath, $command)
            );
        }
    }

    $repoWorkflowExpectations = [
        '.github/workflows/wporg-updates.yml' => ['contents: write', 'pull-requests: write', 'issues: write'],
        '.github/workflows/wporg-updates-reconcile.yml' => ['contents: write', 'pull-requests: write', 'issues: write'],
        '.github/workflows/wporg-update-pr-blocker.yml' => ['contents: read', 'pull-requests: read', 'issues: read'],
        '.github/workflows/wporg-validate-runtime.yml' => ['contents: read'],
    ];

    foreach ($repoWorkflowExpectations as $workflowPath => $snippets) {
        $workflowContents = (string) file_get_contents($repoRoot . '/' . $workflowPath);

        foreach ($snippets as $snippet) {
            $assert(
                str_contains($workflowContents, $snippet),
                sprintf('Expected repository workflow %s to retain permission snippet `%s`.', $workflowPath, $snippet)
            );
        }
    }
}
