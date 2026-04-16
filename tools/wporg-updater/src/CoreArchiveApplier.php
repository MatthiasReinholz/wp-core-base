<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;
use ZipArchive;

final class CoreArchiveApplier
{
    public function __construct(
        private readonly Config $config,
        private readonly WordPressCoreClient $coreClient,
        private readonly ArchiveDownloader $archiveDownloader,
        private readonly RuntimeInspector $runtimeInspector,
    ) {
    }

    /**
     * @return list<string>
     */
    public function checkoutAndApplyCoreVersion(
        GitRunnerInterface $gitRunner,
        string $defaultBranch,
        string $branch,
        string $downloadUrl,
        string $targetVersion,
        bool $resetToBase = false,
    ): array {
        $gitRunner->checkoutBranch($defaultBranch, $branch, $resetToBase);
        $tempDir = sys_get_temp_dir() . '/wp-core-update-' . bin2hex(random_bytes(6));

        if (! mkdir($tempDir, 0777, true) && ! is_dir($tempDir)) {
            throw new RuntimeException(sprintf('Failed to create temp directory: %s', $tempDir));
        }

        $archivePath = $tempDir . '/core.zip';
        $extractPath = $tempDir . '/extract';

        if (! mkdir($extractPath, 0777, true) && ! is_dir($extractPath)) {
            throw new RuntimeException(sprintf('Failed to create extraction directory: %s', $extractPath));
        }

        try {
            $this->archiveDownloader->downloadToFile($downloadUrl, $archivePath);

            $zip = new ZipArchive();

            if ($zip->open($archivePath) !== true) {
                throw new RuntimeException(sprintf('Failed to open core archive: %s', $archivePath));
            }

            ZipExtractor::extractValidated($zip, $extractPath);
            $zip->close();

            $sourceRoot = $extractPath . '/wordpress';

            if (! is_dir($sourceRoot)) {
                throw new RuntimeException('Expected extracted WordPress core archive to contain a wordpress/ root directory.');
            }

            $this->coreClient->assertOfficialChecksums($targetVersion, $sourceRoot);
            $this->sanitizeExtractedTree($sourceRoot);

            $paths = [];

            foreach (array_values(array_filter(scandir($sourceRoot) ?: [], static fn (string $entry): bool => ! in_array($entry, ['.', '..'], true))) as $entry) {
                $source = $sourceRoot . '/' . $entry;

                if ($entry === 'wp-content') {
                    $paths = array_merge($paths, $this->syncCoreWpContent($source));
                    continue;
                }

                $destination = $this->config->repoRoot . '/' . $entry;
                $this->runtimeInspector->clearPath($destination);
                $this->runtimeInspector->copyPath($source, $destination);
                $paths[] = $entry;
            }

            return array_values(array_unique($paths));
        } finally {
            $this->runtimeInspector->clearPath($tempDir);
        }
    }

    /**
     * @return list<string>
     */
    private function syncCoreWpContent(string $sourceWpContent): array
    {
        $destinationWpContent = $this->config->repoRoot . '/' . $this->config->paths['content_root'];

        if (! is_dir($destinationWpContent)) {
            if (! mkdir($destinationWpContent, 0777, true) && ! is_dir($destinationWpContent)) {
                throw new RuntimeException(sprintf('Failed to create WordPress content directory: %s', $destinationWpContent));
            }
        }

        $paths = [];

        foreach (array_values(array_filter(scandir($sourceWpContent) ?: [], static fn (string $entry): bool => ! in_array($entry, ['.', '..'], true))) as $entry) {
            $source = $sourceWpContent . '/' . $entry;
            $destination = $destinationWpContent . '/' . $entry;

            if (is_dir($source) && in_array($entry, ['plugins', 'themes'], true)) {
                $paths = array_merge($paths, $this->syncBundledDirectory($source, $destination, $this->config->paths['content_root'] . '/' . $entry));
                continue;
            }

            $this->runtimeInspector->clearPath($destination);
            $this->runtimeInspector->copyPath($source, $destination);
            $paths[] = $this->config->paths['content_root'] . '/' . $entry;
        }

        return $paths;
    }

    /**
     * @return list<string>
     */
    private function syncBundledDirectory(string $sourceDirectory, string $destinationDirectory, string $pathPrefix): array
    {
        if (! is_dir($destinationDirectory)) {
            if (! mkdir($destinationDirectory, 0777, true) && ! is_dir($destinationDirectory)) {
                throw new RuntimeException(sprintf('Failed to create bundled destination directory: %s', $destinationDirectory));
            }
        }

        $paths = [];

        foreach (array_values(array_filter(scandir($sourceDirectory) ?: [], static fn (string $entry): bool => ! in_array($entry, ['.', '..'], true))) as $entry) {
            $source = $sourceDirectory . '/' . $entry;
            $destination = $destinationDirectory . '/' . $entry;
            $this->runtimeInspector->clearPath($destination);
            $this->runtimeInspector->copyPath($source, $destination);
            $paths[] = $pathPrefix . '/' . $entry;
        }

        return $paths;
    }

    private function sanitizeExtractedTree(string $root): void
    {
        $forbiddenPaths = $this->config->runtime['forbidden_paths'];
        $forbiddenFiles = $this->config->runtime['forbidden_files'];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $basename = basename($item->getPathname());

            if ($item->isDir()) {
                foreach ($forbiddenPaths as $pattern) {
                    if (fnmatch($pattern, $basename)) {
                        $this->runtimeInspector->clearPath($item->getPathname());
                        break;
                    }
                }

                continue;
            }

            foreach ($forbiddenFiles as $pattern) {
                if (fnmatch($pattern, $basename)) {
                    if (! unlink($item->getPathname())) {
                        throw new RuntimeException(sprintf('Failed to remove forbidden file during sanitize: %s', $item->getPathname()));
                    }
                    break;
                }
            }
        }
    }
}
