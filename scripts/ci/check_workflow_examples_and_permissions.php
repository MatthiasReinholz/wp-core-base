<?php

declare(strict_types=1);

use WpOrgPluginUpdater\DownstreamScaffolder;

require dirname(__DIR__, 2) . '/tools/wporg-updater/src/Autoload.php';

$repoRoot = dirname(__DIR__, 2);
$tempRoot = sys_get_temp_dir() . '/wporg-workflow-parity-' . bin2hex(random_bytes(4));

mkdir($tempRoot, 0777, true);

try {
    (new DownstreamScaffolder($repoRoot, $tempRoot))->scaffold('vendor/wp-core-base', 'content-only', 'cms', true);

    $exampleMappings = [
        '.github/workflows/wporg-updates.yml' => 'docs/examples/downstream-workflow.yml',
        '.github/workflows/wporg-updates-reconcile.yml' => 'docs/examples/downstream-updates-reconcile-workflow.yml',
        '.github/workflows/wporg-update-pr-blocker.yml' => 'docs/examples/downstream-pr-blocker-workflow.yml',
        '.github/workflows/wporg-validate-runtime.yml' => 'docs/examples/downstream-validate-runtime-workflow.yml',
        '.github/workflows/wp-core-base-self-update.yml' => 'docs/examples/downstream-framework-self-update-workflow.yml',
    ];

    foreach ($exampleMappings as $scaffoldedPath => $examplePath) {
        $scaffolded = file_get_contents($tempRoot . '/' . $scaffoldedPath);
        $documented = file_get_contents($repoRoot . '/' . $examplePath);

        if ($scaffolded === false || $documented === false) {
            throw new RuntimeException(sprintf('Unable to read workflow parity inputs for %s.', $examplePath));
        }

        if (normalizeWorkflow($scaffolded) !== normalizeWorkflow($documented)) {
            throw new RuntimeException(sprintf(
                'Workflow example drift detected: %s no longer matches scaffolded output %s.',
                $examplePath,
                $scaffoldedPath
            ));
        }
    }

    $expectedPermissions = [
        '.github/workflows/finalize-wp-core-base-release.yml' => [
            'contents' => 'write',
        ],
        '.github/workflows/prepare-wp-core-base-release.yml' => [
            'contents' => 'write',
            'pull-requests' => 'write',
        ],
        '.github/workflows/release-wp-core-base.yml' => [
            'contents' => 'write',
            'pull-requests' => 'read',
        ],
        '.github/workflows/wporg-updates.yml' => [
            'contents' => 'write',
            'pull-requests' => 'write',
            'issues' => 'write',
        ],
        '.github/workflows/wporg-updates-reconcile.yml' => [
            'contents' => 'write',
            'pull-requests' => 'write',
            'issues' => 'write',
        ],
        '.github/workflows/wporg-update-pr-blocker.yml' => [
            'contents' => 'read',
            'pull-requests' => 'read',
            'issues' => 'read',
        ],
        '.github/workflows/wporg-validate-runtime.yml' => [
            'contents' => 'read',
        ],
    ];

    foreach ($expectedPermissions as $workflowPath => $expected) {
        $contents = file_get_contents($repoRoot . '/' . $workflowPath);

        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to read workflow for permission verification: %s', $workflowPath));
        }

        $blocks = parsePermissionsBlocks($contents);
        $topLevel = $blocks['top-level'] ?? null;

        if ($topLevel === null) {
            throw new RuntimeException(sprintf('Workflow %s is missing a parseable top-level permissions block.', $workflowPath));
        }

        if ($topLevel !== $expected) {
            throw new RuntimeException(sprintf(
                'Workflow permission drift detected for %s. Expected %s but found %s.',
                $workflowPath,
                formatPermissions($expected),
                formatPermissions($topLevel)
            ));
        }

        foreach ($blocks as $blockName => $permissions) {
            if ($blockName === 'top-level') {
                continue;
            }

            if (! permissionsWithinBaseline($permissions, $expected)) {
                throw new RuntimeException(sprintf(
                    'Workflow permission override drift detected for %s (%s). Expected at most %s but found %s.',
                    $workflowPath,
                    $blockName,
                    formatPermissions($expected),
                    formatPermissions($permissions)
                ));
            }
        }
    }

    fwrite(STDOUT, "Workflow examples and permissions verified.\n");
} finally {
    clearPath($tempRoot);
}

/**
 * @return array<string, array<string, string>>
 */
function parsePermissionsBlocks(string $contents): array
{
    $lines = preg_split("/\r\n|\n|\r/", $contents) ?: [];
    $blocks = [];

    foreach ($lines as $index => $line) {
        if (! preg_match('/^(\s*)permissions:\s*(.*)$/', $line, $permissionMatches)) {
            continue;
        }

        $indent = strlen($permissionMatches[1]);
        $permissions = parseInlinePermissions(trim($permissionMatches[2]));
        $blockName = $indent === 0 ? 'top-level' : sprintf('line-%d', $index + 1);

        if ($indent === 4) {
            for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
                if (preg_match('/^\s{2}([A-Za-z0-9_-]+):\s*$/', $lines[$cursor], $jobMatches)) {
                    $blockName = 'job:' . $jobMatches[1];
                    break;
                }

                if (trim($lines[$cursor]) !== '') {
                    break;
                }
            }
        }

        if ($permissions === []) {
            for ($cursor = $index + 1, $count = count($lines); $cursor < $count; $cursor++) {
                $candidate = $lines[$cursor];

                if (trim($candidate) === '') {
                    continue;
                }

                if (! preg_match('/^(\s+)([A-Za-z-]+):\s*([A-Za-z-]+)\s*$/', $candidate, $matches)) {
                    break;
                }

                $candidateIndent = strlen($matches[1]);

                if ($candidateIndent <= $indent) {
                    break;
                }

                $permissions[$matches[2]] = $matches[3];
            }
        }

        if ($permissions !== []) {
            $blocks[$blockName] = $permissions;
        }
    }

    return $blocks;
}

/**
 * @return array<string, string>
 */
function parseInlinePermissions(string $value): array
{
    if ($value === '') {
        return [];
    }

    if ($value === 'read-all' || $value === 'write-all') {
        return ['*' => $value];
    }

    if (preg_match('/^\{(.*)\}$/', $value, $matches) !== 1) {
        return ['*' => $value];
    }

    $pairs = [];

    foreach (explode(',', $matches[1]) as $pair) {
        [$scope, $level] = array_pad(explode(':', $pair, 2), 2, null);
        $scope = is_string($scope) ? trim($scope) : '';
        $level = is_string($level) ? trim($level) : '';

        if ($scope === '' || $level === '') {
            return ['*' => $value];
        }

        $pairs[$scope] = $level;
    }

    return $pairs;
}

/**
 * @param array<string, string> $permissions
 */
function formatPermissions(array $permissions): string
{
    $pairs = [];

    foreach ($permissions as $scope => $level) {
        $pairs[] = sprintf('%s=%s', $scope, $level);
    }

    return implode(', ', $pairs);
}

/**
 * @param array<string, string> $actual
 * @param array<string, string> $expected
 */
function permissionsWithinBaseline(array $actual, array $expected): bool
{
    $levels = ['none' => 0, 'read' => 1, 'write' => 2];

    if (($actual['*'] ?? null) === 'write-all') {
        return false;
    }

    if (($actual['*'] ?? null) === 'read-all') {
        foreach ($expected as $level) {
            if (($levels[$level] ?? 0) > $levels['read']) {
                return false;
            }
        }

        return true;
    }

    if (isset($actual['*'])) {
        return false;
    }

    foreach ($actual as $scope => $level) {
        if (! isset($expected[$scope], $levels[$level], $levels[$expected[$scope]])) {
            return false;
        }

        if ($levels[$level] > $levels[$expected[$scope]]) {
            return false;
        }
    }

    return true;
}

function clearPath(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }

    if (! is_dir($path)) {
        return;
    }

    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        clearPath($path . '/' . $entry);
    }

    @rmdir($path);
}

function normalizeWorkflow(string $contents): string
{
    return ltrim((string) preg_replace('/^(?:#.*\R)+\R*/', '', $contents));
}
