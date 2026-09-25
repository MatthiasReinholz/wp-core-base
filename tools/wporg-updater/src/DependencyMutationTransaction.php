<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;
use Throwable;

/** Recovery boundary for a dependency payload and its manifest/governance files. */
final class DependencyMutationTransaction
{
    private readonly TempWorkspace $workspace;
    /** @var array<string,array{exists:bool,contents:?string}> */
    private readonly array $states;
    private readonly bool $hadRuntimePath;
    private readonly string $repoRoot;
    private bool $runtimeChanged = false;

    public function __construct(
        Config $current,
        Config $next,
        private readonly ConfigMutationStateManager $stateManager,
        private readonly RuntimeInspector $inspector,
        private readonly ?string $runtimePath,
    ) {
        $this->repoRoot = $current->repoRoot;
        $this->states = $stateManager->snapshot($current, $next);
        $this->workspace = TempWorkspace::create($current->repoRoot, 'dependency-recovery');
        $encoded = [];
        foreach ($this->states as $path => $state) {
            $this->assertSafePath($path);
            $encoded[$path] = ['exists' => $state['exists'], 'contents_base64' => $state['contents'] === null ? null : base64_encode($state['contents'])];
        }
        if (file_put_contents($this->workspace->path() . '/recovery.json', json_encode([
            'schema' => 1, 'runtime_path' => $runtimePath, 'config_files' => $encoded,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)) === false) {
            throw new RuntimeException('Unable to persist configuration recovery data.');
        }
        $this->hadRuntimePath = $runtimePath !== null && (file_exists($runtimePath) || is_link($runtimePath));
        if ($runtimePath !== null) {
            $this->assertSafePath($runtimePath, true);
        }
        if ($this->hadRuntimePath) {
            $this->inspector->copyPath((string) $runtimePath, $this->workspace->path() . '/runtime');
        }
        // Configuration-only operations also require recovery if interrupted
        // between their manifest and governance writes.
        $this->workspace->retainRecovery();
    }

    public function beginRuntimeMutation(): void
    {
        if ($this->runtimePath !== null) {
            $this->assertSafePath($this->runtimePath, true);
        }
        $this->runtimeChanged = true;
    }

    /** Called after the operation's commit point, outside its rollback catch. */
    public function commit(): void
    {
        $this->workspace->discardRecovery();
    }

    public function rollback(Throwable $original): never
    {
        $failures = [];
        if ($this->runtimeChanged && $this->runtimePath !== null) {
            try {
                $this->assertSafePath($this->runtimePath, true);
                $this->inspector->clearPath($this->runtimePath);
                if ($this->hadRuntimePath) {
                    $this->inspector->copyPath($this->workspace->path() . '/runtime', $this->runtimePath);
                }
            } catch (Throwable $failure) {
                $failures[] = $failure->getMessage();
            }
        }
        // A failed payload restore must not prevent independent config restores.
        foreach ($this->states as $path => $state) {
            try {
                $this->assertSafePath($path);
                $this->stateManager->restore([$path => $state]);
            } catch (Throwable $failure) {
                $failures[] = $failure->getMessage();
            }
        }
        if ($failures !== []) {
            try {
                $this->workspace->preserve();
            } catch (Throwable $preservationFailure) {
                $failures[] = $preservationFailure->getMessage();
            }
            throw new RuntimeException(sprintf('%s Recovery is incomplete; backups retained at %s. %s',
                $original->getMessage(), $this->workspace->path(), implode(' ', $failures)), 0, $original);
        }
        try {
            $this->workspace->discardRecovery();
        } catch (Throwable $cleanupFailure) {
            throw new RuntimeException($original->getMessage() . ' Recovery succeeded, but cleanup failed: ' . $cleanupFailure->getMessage(), 0, $original);
        }
        throw $original;
    }

    private function assertSafePath(string $path, bool $isRuntimePayload = false): void
    {
        $root = rtrim($this->repoRoot, '/') . '/';
        if (! str_starts_with($path, $root)) {
            throw new RuntimeException('Dependency recovery path must be inside the repository.');
        }
        $relative = ConfigPathRules::normalizedRelativePath(substr($path, strlen($root)), 'dependency recovery path');
        if ($relative === '.') {
            throw new RuntimeException('Dependency mutation cannot replace the repository root.');
        }
        if ($isRuntimePayload) {
            // Ignored entries may document control paths, but never authorize
            // authoring commands to delete or replace those paths.
            ConfigPathRules::assertSafeDependencyPath($this->repoRoot, $relative);
        }
        ConfigPathRules::assertNoSymlinkDescendants($this->repoRoot, $relative);
    }
}
