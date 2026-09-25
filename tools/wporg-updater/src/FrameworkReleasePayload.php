<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

/** The versioned, framework-only release boundary. Runtime baselines are separate inputs. */
final class FrameworkReleasePayload
{
    public const INVENTORY = '.wp-core-base/release-inventory.json';
    public const FORMAT = 'wp-core-base-tooling-v1';

    public static function allows(string $path): bool
    {
        if ($path === '' || str_contains($path, '\\') || preg_match('~(^|/)(?:\.|\.\.|\.[^/]+)(/|$)~', $path)) {
            return in_array($path, ['.wp-core-base/framework.php', '.wp-core-base/manifest.php'], true);
        }

        return in_array($path, ['README.md', 'AGENTS.md', 'SECURITY.md', 'license.txt', 'LICENSE', 'bin/wp-core-base'], true)
            || preg_match('~^docs/(?:[a-zA-Z0-9_-]+/)*[a-zA-Z0-9_.-]+\.(?:md|php|yml)$~D', $path) === 1
            || preg_match('~^tools/wporg-updater/(?:src/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\.php|bin/wporg-updater\.php|templates/[a-z0-9_.-]+\.tpl|keys/framework-release-public(?:-[a-zA-Z0-9_-]+)?\.pem)$~D', $path) === 1;
    }

    /** @return array<string, array{sha256:string,bytes:int,mode:string}> */
    public static function inventory(string $root): array
    {
        $files = [];
        $directories = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            $path = str_replace('\\', '/', $iterator->getSubPathName());
            if ($file->isDir() && ! $file->isLink()) {
                $directories[] = $path;
                continue;
            }
            if ($file->isLink() || ! $file->isFile()) {
                throw new RuntimeException(sprintf('Release payload contains a non-regular file: %s', $path));
            }
            if ($path === self::INVENTORY) {
                continue;
            }
            if (! self::allows($path)) {
                throw new RuntimeException(sprintf('Release payload contains an unapproved file: %s', $path));
            }
            if (str_ends_with($path, '.pem')) {
                $contents = file_get_contents($file->getPathname());
                if (! is_string($contents) || str_contains($contents, 'PRIVATE KEY') || openssl_pkey_get_public($contents) === false) {
                    throw new RuntimeException(sprintf('Release key input must contain only a public key: %s', $path));
                }
            }
            $hash = hash_file('sha256', $file->getPathname());
            if (! is_string($hash)) {
                throw new RuntimeException(sprintf('Unable to hash release payload: %s', $path));
            }
            $files[$path] = ['sha256' => $hash, 'bytes' => $file->getSize(), 'mode' => $path === 'bin/wp-core-base' ? '0755' : '0644'];
        }
        foreach ($directories as $directory) {
            $hasApprovedChild = false;
            foreach ([...array_keys($files), self::INVENTORY] as $path) {
                if (str_starts_with($path, $directory . '/')) {
                    $hasApprovedChild = true;
                    break;
                }
            }
            if (! $hasApprovedChild) {
                throw new RuntimeException(sprintf('Release payload contains an unapproved empty directory: %s', $directory));
            }
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    public static function writeInventory(string $root, string $revision): void
    {
        $document = [
            'format' => self::FORMAT,
            'source_revision' => $revision,
            'files' => self::inventory($root),
        ];
        (new AtomicFileWriter())->write($root . '/' . self::INVENTORY, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }

    /** Validate independently against extracted content; older full snapshots remain supported. */
    public static function verify(string $root): void
    {
        $path = $root . '/' . self::INVENTORY;
        if (! is_file($path)) {
            // Legacy artifacts predate the slim boundary. A slim artifact must identify itself.
            if (! is_file($root . '/wp-includes/version.php')) {
                throw new RuntimeException('Framework-only release payload is missing its inventory.');
            }
            return;
        }
        $document = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($document) || ($document['format'] ?? null) !== self::FORMAT
            || ! is_string($document['source_revision'] ?? null)
            || preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64}|fixture)$/D', $document['source_revision']) !== 1
            || ($document['files'] ?? null) !== self::inventory($root)) {
            throw new RuntimeException('Release payload inventory does not match its format, revision, or file contents.');
        }
        foreach (['.wp-core-base/framework.php', '.wp-core-base/manifest.php', 'README.md', 'bin/wp-core-base', 'tools/wporg-updater/src/Autoload.php', 'tools/wporg-updater/bin/wporg-updater.php', 'tools/wporg-updater/keys/framework-release-public.pem'] as $required) {
            if (! isset($document['files'][$required])) {
                throw new RuntimeException(sprintf('Release payload is missing required file: %s', $required));
            }
        }
    }
}
