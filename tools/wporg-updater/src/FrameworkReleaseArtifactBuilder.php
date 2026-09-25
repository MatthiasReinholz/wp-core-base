<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;
use ZipArchive;

final class FrameworkReleaseArtifactBuilder
{
    private const SNAPSHOT_ROOT = 'wp-core-base';
    // Fixed DOS-compatible time, independent of checkout, timezone, and build time.
    private const ZIP_TIMESTAMP = 946684800;

    /** @return list<string> Compatibility list for older downstream fixture callers. */
    public static function excludedPaths(): array
    {
        return ['.git', '.github', '.wp-core-base/build', 'dist', 'scripts/ci', 'tools/wporg-updater/.tmp', 'tools/wporg-updater/tests'];
    }

    public function __construct(private readonly string $repoRoot) {}

    /** @return array<string, mixed> */
    public function build(string $artifactPath, ?string $checksumPath = null, ?string $sourceRevision = null, bool $fixture = false): array
    {
        $revision = $fixture ? 'fixture' : trim($this->git(['rev-parse', '--verify', '--end-of-options', ($sourceRevision ?? 'HEAD') . '^{commit}']));
        if ($fixture && $this->isGitWorktree()) {
            throw new RuntimeException('Fixture release builds require an explicit non-Git fixture directory.');
        }
        if (! $fixture && preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D', $revision) !== 1) {
            throw new RuntimeException('Official release builds require an immutable Git commit.');
        }
        $directory = dirname($artifactPath);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create artifact directory: %s', $directory));
        }
        $workspace = TempWorkspace::create($this->repoRoot, 'artifact');
        $snapshot = $workspace->path() . '/' . self::SNAPSHOT_ROOT;
        $temporaryArtifact = $directory . '/.wp-core-base-artifact-' . bin2hex(random_bytes(8)) . '.zip';
        try {
            if ($fixture) {
                $this->copySnapshotTo($snapshot);
            } else {
                $this->copyCommitTo($snapshot, $revision);
            }
            FrameworkReleasePayload::writeInventory($snapshot, $revision);
            FrameworkReleasePayload::verify($snapshot);
            $this->writeArchive($snapshot, $temporaryArtifact);
            $resolvedChecksum = $checksumPath ?? ($artifactPath . '.sha256');
            $this->publishArtifactPair($temporaryArtifact, $artifactPath, $resolvedChecksum);
            return ['artifact' => $artifactPath, 'checksum_file' => $resolvedChecksum, 'snapshot_root' => self::SNAPSHOT_ROOT, 'source_revision' => $revision, 'format' => FrameworkReleasePayload::FORMAT, 'excluded_paths' => self::excludedPaths()];
        } finally {
            if (is_file($temporaryArtifact)) {
                unlink($temporaryArtifact);
            }
            try {
                $workspace->close();
            } catch (\Throwable $cleanupFailure) {
                fwrite(STDERR, sprintf("[warn] Release scratch cleanup failed: %s\n", $cleanupFailure->getMessage()));
            }
        }
    }

    private function writeArchive(string $snapshot, string $temporaryArtifact): void
    {
        // libzip encodes DOS timestamps in the process timezone, independently
        // of PHP's date timezone. Keep this synchronous CLI operation in UTC.
        $previousTimezone = getenv('TZ');
        if (! putenv('TZ=UTC')) {
            throw new RuntimeException('Unable to establish reproducible ZIP timezone.');
        }
        try {
            $zip = new ZipArchive();
            if ($zip->open($temporaryArtifact, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                throw new RuntimeException('Unable to create temporary release archive.');
            }
            try {
                $paths = array_keys(FrameworkReleasePayload::inventory($snapshot));
                $paths[] = FrameworkReleasePayload::INVENTORY;
                sort($paths, SORT_STRING);
                foreach ($paths as $path) {
                    $name = self::SNAPSHOT_ROOT . '/' . $path;
                    if (! $zip->addFile($snapshot . '/' . $path, $name)
                        || ! $zip->setMtimeName($name, self::ZIP_TIMESTAMP)
                        || ! $zip->setCompressionName($name, ZipArchive::CM_STORE)
                        || ! $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, (($path === 'bin/wp-core-base' ? 0100755 : 0100644) << 16))) {
                        throw new RuntimeException(sprintf('Unable to add deterministic release entry: %s', $path));
                    }
                }
            } finally {
                if (! $zip->close()) {
                    throw new RuntimeException('Unable to finalize release archive.');
                }
            }
        } finally {
            if (! putenv($previousTimezone === false ? 'TZ' : 'TZ=' . $previousTimezone)) {
                throw new RuntimeException('Unable to restore process timezone after ZIP creation.');
            }
        }
    }

    /** Explicit development-fixture copy; official builds read committed Git objects instead. */
    public function copySnapshotTo(string $destination): void
    {
        if (file_exists($destination) || is_link($destination)) {
            throw new RuntimeException('Snapshot fixture destination must not already exist.');
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->repoRoot, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = str_replace('\\', '/', $iterator->getSubPathName());
            if (! FrameworkReleasePayload::allows($path)) {
                continue;
            }
            if ($file->isLink() || ! $file->isFile()) {
                throw new RuntimeException(sprintf('Release fixture contains a non-regular allowed input: %s', $path));
            }
            $contents = file_get_contents($file->getPathname());
            if (! is_string($contents)) {
                throw new RuntimeException(sprintf('Unable to read release fixture: %s', $path));
            }
            $this->writePayloadFile($destination, $path, $contents);
        }
        FrameworkReleasePayload::writeInventory($destination, 'fixture');
    }

    private function copyCommitTo(string $destination, string $revision): void
    {
        foreach (explode("\0", $this->git(['ls-tree', '-rz', '--full-tree', $revision])) as $entry) {
            if ($entry === '') {
                continue;
            }
            if (preg_match('/^([0-7]{6}) (blob|tree|commit) ([a-f0-9]+)\t(.+)$/sD', $entry, $match) !== 1) {
                throw new RuntimeException('Unable to parse committed release tree.');
            }
            $path = $match[4];
            if (! FrameworkReleasePayload::allows($path)) {
                continue;
            }
            if (! in_array($match[1], ['100644', '100755'], true) || $match[2] !== 'blob') {
                throw new RuntimeException(sprintf('Release input is not a regular committed file: %s', $path));
            }
            $this->writePayloadFile($destination, $path, $this->git(['cat-file', 'blob', $match[3]]));
        }
    }

    private function writePayloadFile(string $root, string $path, string $contents): void
    {
        $directory = dirname($root . '/' . $path);
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create payload directory: %s', $directory));
        }
        if (file_put_contents($root . '/' . $path, $contents) === false) {
            throw new RuntimeException(sprintf('Unable to write payload file: %s', $path));
        }
        chmod($root . '/' . $path, $path === 'bin/wp-core-base' ? 0755 : 0644);
    }

    private function publishArtifactPair(string $temporary, string $artifact, string $checksum): void
    {
        if ($artifact === $checksum || is_link($artifact) || is_link($checksum)) {
            throw new RuntimeException('Artifact and checksum must be distinct regular file destinations.');
        }
        $previousChecksum = is_file($checksum) ? file_get_contents($checksum) : null;
        if ($previousChecksum === false) {
            throw new RuntimeException('Unable to preserve previous checksum.');
        }
        (new AtomicFileWriter())->write($checksum, sprintf("%s  %s\n", FileChecksum::sha256($temporary), basename($artifact)));
        if (! rename($temporary, $artifact)) {
            if (is_string($previousChecksum)) {
                (new AtomicFileWriter())->write($checksum, $previousChecksum);
            } else {
                unlink($checksum);
            }
            throw new RuntimeException('Unable to publish completed release artifact; previous artifact preserved.');
        }
    }

    private function isGitWorktree(): bool
    {
        try { return trim($this->git(['rev-parse', '--is-inside-work-tree'])) === 'true'; }
        catch (RuntimeException) { return false; }
    }

    /** @param list<string> $arguments */
    private function git(array $arguments): string
    {
        $process = proc_open(['git', '-C', $this->repoRoot, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Unable to read immutable release source.');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0 || ! is_string($stdout)) {
            throw new RuntimeException('Official release source Git command failed: ' . trim((string) $stderr));
        }
        return $stdout;
    }
}
