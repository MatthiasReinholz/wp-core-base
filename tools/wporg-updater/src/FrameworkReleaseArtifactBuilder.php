<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;
use ZipArchive;

final class FrameworkReleaseArtifactBuilder
{
    private const SNAPSHOT_ROOT = 'wp-core-base';

    /**
     * @return list<string>
     */
    public static function excludedPaths(): array
    {
        return [
            '.git',
            '.github',
            '.wp-core-base/build',
            'dist',
            'scripts/ci',
            'tools/wporg-updater/.tmp',
            'tools/wporg-updater/tests',
        ];
    }

    public function __construct(
        private readonly string $repoRoot,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(string $artifactPath, ?string $checksumPath = null): array
    {
        $artifactDirectory = dirname($artifactPath);

        if (! is_dir($artifactDirectory) && ! mkdir($artifactDirectory, 0775, true) && ! is_dir($artifactDirectory)) {
            throw new RuntimeException(sprintf('Unable to create artifact directory: %s', $artifactDirectory));
        }

        $temporaryRoot = sys_get_temp_dir() . '/wp-core-base-artifact-' . bin2hex(random_bytes(6));
        $snapshotRoot = $temporaryRoot . '/' . self::SNAPSHOT_ROOT;
        $runtimeInspector = new RuntimeInspector(Config::load($this->repoRoot)->runtime);

        try {
            $this->copySnapshotTo($snapshotRoot);

            $zip = new ZipArchive();

            if ($zip->open($artifactPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException(sprintf('Unable to create release artifact archive: %s', $artifactPath));
            }

            $this->addDirectoryToZip($zip, $snapshotRoot, self::SNAPSHOT_ROOT);
            $zip->close();

            $resolvedChecksumPath = $checksumPath ?? ($artifactPath . '.sha256');
            (new AtomicFileWriter())->write(
                $resolvedChecksumPath,
                sprintf("%s  %s\n", FileChecksum::sha256($artifactPath), basename($artifactPath))
            );

            return [
                'artifact' => $artifactPath,
                'checksum_file' => $resolvedChecksumPath,
                'snapshot_root' => self::SNAPSHOT_ROOT,
                'excluded_paths' => self::excludedPaths(),
            ];
        } finally {
            $runtimeInspector->clearPath($temporaryRoot);
        }
    }

    public function copySnapshotTo(string $destination): void
    {
        $inspector = new RuntimeInspector(Config::load($this->repoRoot)->runtime);
        $inspector->clearPath($destination);
        $this->copyPath($this->repoRoot, $destination, '');
    }

    private function copyPath(string $source, string $destination, string $relativePath): void
    {
        if ($relativePath !== '' && $this->isExcluded($relativePath)) {
            return;
        }

        if (is_link($source)) {
            throw new RuntimeException(sprintf('Symlink detected while building release snapshot: %s', $relativePath === '' ? '.' : $relativePath));
        }

        if (is_file($source)) {
            $directory = dirname($destination);

            if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
                throw new RuntimeException(sprintf('Unable to create directory while building release snapshot: %s', $directory));
            }

            if (! copy($source, $destination)) {
                throw new RuntimeException(sprintf('Unable to copy %s into release snapshot.', $relativePath));
            }

            return;
        }

        if (! is_dir($source)) {
            return;
        }

        if (! is_dir($destination) && ! mkdir($destination, 0775, true) && ! is_dir($destination)) {
            throw new RuntimeException(sprintf('Unable to create snapshot directory: %s', $destination));
        }

        foreach (scandir($source) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $childRelative = $relativePath === '' ? $entry : $relativePath . '/' . $entry;
            $this->copyPath($source . '/' . $entry, $destination . '/' . $entry, $childRelative);
        }
    }

    private function addDirectoryToZip(ZipArchive $zip, string $sourceRoot, string $archiveRoot): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $zip->addEmptyDir($archiveRoot);

        foreach ($iterator as $item) {
            $relativePath = str_replace('\\', '/', $iterator->getSubPathName());
            $archivePath = $archiveRoot . '/' . $relativePath;

            if ($item->isDir()) {
                $zip->addEmptyDir($archivePath);
                continue;
            }

            if (! $zip->addFile($item->getPathname(), $archivePath)) {
                throw new RuntimeException(sprintf('Unable to add %s to release artifact.', $relativePath));
            }
        }
    }

    private function isExcluded(string $relativePath): bool
    {
        foreach (self::excludedPaths() as $excludedPath) {
            if ($relativePath === $excludedPath || str_starts_with($relativePath, $excludedPath . '/')) {
                return true;
            }
        }

        return false;
    }
}
