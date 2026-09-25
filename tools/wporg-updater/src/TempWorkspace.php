<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;
use Throwable;

/** Private, owned scratch storage whose lifetime is protected by an advisory lock. */
final class TempWorkspace
{
    public const MARKER = '.wp-core-base-workspace.json';
    public const LOCK = '.workspace.lock';

    /** @var resource|null */
    private $lock;
    private bool $closed = false;
    private bool $preserved = false;
    private readonly int $inode;
    private readonly int $device;

    /** @param resource $lock */
    private function __construct(private readonly string $root, $lock)
    {
        $this->lock = $lock;
        $identity = lstat($root);
        if (! is_array($identity)) {
            throw new RuntimeException('Unable to identify private temporary workspace.');
        }
        $this->inode = $identity['ino'];
        $this->device = $identity['dev'];
    }

    public static function create(string $repoRoot, string $operation, ?string $tempRoot = null): self
    {
        if (preg_match('/^[a-z][a-z0-9-]*$/D', $operation) !== 1) {
            throw new RuntimeException('Temporary workspace operation must be a lowercase identifier.');
        }
        $namespace = self::namespacePath($repoRoot, $tempRoot);
        self::createPrivateDirectory(dirname($namespace));
        self::createPrivateDirectory($namespace);
        $root = $namespace . '/workspace-' . $operation . '-' . bin2hex(random_bytes(16));
        self::createPrivateDirectory($root);
        $lock = @fopen($root . '/' . self::LOCK, 'x+b');
        if ($lock === false || ! chmod($root . '/' . self::LOCK, 0600) || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException(sprintf('Unable to lock temporary workspace %s.', $root));
        }
        $workspace = new self($root, $lock);
        try {
            $marker = json_encode([
                'schema' => 1,
                'repository' => self::repositoryIdentity($repoRoot),
                'operation' => $operation,
                'created_at' => time(),
                'preserved' => false,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
            if (file_put_contents($root . '/' . self::MARKER, $marker) === false || ! chmod($root . '/' . self::MARKER, 0600)) {
                throw new RuntimeException('Unable to mark temporary workspace.');
            }
            self::createPrivateDirectory($workspace->path());
        } catch (Throwable $exception) {
            $workspace->close();
            throw $exception;
        }
        return $workspace;
    }

    public function path(): string
    {
        return $this->root . '/payload';
    }

    /** Release scratch storage; harmless to call more than once. */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        try {
            $this->assertWorkspaceIdentity();
            if (! $this->preserved && ! self::removeOwnedTree($this->root)) {
                throw new RuntimeException(sprintf('Unable to remove temporary workspace %s.', $this->root));
            }
        } finally {
            $this->releaseLock();
        }
    }

    /** Keep recovery data indefinitely, outside automatic stale-workspace cleanup. */
    public function preserve(): void
    {
        if ($this->closed) {
            throw new RuntimeException('Cannot preserve a closed temporary workspace.');
        }
        $this->preserved = true;
        $this->assertWorkspaceIdentity();
        $markerPath = $this->root . '/' . self::MARKER;
        $marker = self::readMarker($this->root);
        if ($marker === null) {
            throw new RuntimeException(sprintf('Cannot preserve unrecognized workspace %s.', $this->root));
        }
        $marker['preserved'] = true;
        if (file_put_contents($markerPath, json_encode($marker, JSON_THROW_ON_ERROR) . "\n") === false) {
            throw new RuntimeException(sprintf('Unable to mark recovery workspace %s.', $this->root));
        }
        $this->releaseLock();
    }

    public function __destruct()
    {
        try {
            $this->close();
        } catch (Throwable) {
            // Explicit close reports failures; destructors must not replace the operation error.
        }
    }

    public static function namespacePath(string $repoRoot, ?string $tempRoot = null): string
    {
        $base = self::temporaryRoot($tempRoot);
        return $base . '/wp-core-base-private-' . self::ownerId() . '/repo-' . self::repositoryIdentity($repoRoot);
    }

    public static function repositoryIdentity(string $repoRoot): string
    {
        $canonical = realpath($repoRoot);
        if ($canonical === false || ! is_dir($canonical)) {
            throw new RuntimeException(sprintf('Workspace repository root does not exist: %s', $repoRoot));
        }
        return hash('sha256', $canonical);
    }

    public static function ownerId(): int
    {
        return function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
    }

    /** @return array{schema:int,repository:string,operation:string,created_at:int,preserved:bool}|null */
    public static function readMarker(string $root): ?array
    {
        $path = $root . '/' . self::MARKER;
        if (! self::isOwnedPrivateDirectory($root) || ! self::isOwnedPrivateFile($path)) {
            return null;
        }
        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            return null;
        }
        $data = json_decode($contents, true);
        if (! is_array($data) || ($data['schema'] ?? null) !== 1
            || ! is_string($data['repository'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $data['repository']) !== 1
            || ! is_string($data['operation'] ?? null) || preg_match('/^[a-z][a-z0-9-]*$/D', $data['operation']) !== 1
            || ! is_int($data['created_at'] ?? null) || ! is_bool($data['preserved'] ?? null)) {
            return null;
        }
        return ['schema' => 1, 'repository' => $data['repository'], 'operation' => $data['operation'], 'created_at' => $data['created_at'], 'preserved' => $data['preserved']];
    }

    /** @phpstan-impure */
    public static function isOwnedPrivateDirectory(string $path): bool
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        return is_array($stat) && ($stat['mode'] & 0170000) === 0040000
            && $stat['uid'] === self::ownerId() && ($stat['mode'] & 0077) === 0;
    }

    /** @phpstan-impure */
    public static function isOwnedPrivateFile(string $path): bool
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        return is_array($stat) && ($stat['mode'] & 0170000) === 0100000
            && $stat['uid'] === self::ownerId() && ($stat['mode'] & 0077) === 0;
    }

    /** Never follows links, including the tree root. Call only while holding the workspace lock. */
    public static function removeOwnedTree(string $path): bool
    {
        clearstatcache(true, $path);
        if (is_link($path)) {
            return @unlink($path);
        }
        if (! file_exists($path)) {
            return true;
        }
        if (! is_dir($path)) {
            return @unlink($path);
        }
        $entries = @scandir($path);
        if (! is_array($entries)) {
            return false;
        }
        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..' && ! self::removeOwnedTree($path . '/' . $entry)) {
                return false;
            }
        }
        return @rmdir($path);
    }

    /** Canonicalize only the system temp alias, and reject links in caller-supplied descendants. */
    private static function temporaryRoot(?string $tempRoot): string
    {
        $system = rtrim(sys_get_temp_dir(), '/');
        $canonicalSystem = realpath($system);
        if ($canonicalSystem === false) {
            throw new RuntimeException('System temporary directory does not exist.');
        }
        $candidate = rtrim($tempRoot ?? $canonicalSystem, '/');
        if ($candidate === $system || str_starts_with($candidate, $system . '/')) {
            $candidate = $canonicalSystem . substr($candidate, strlen($system));
        }
        if (! str_starts_with($candidate, '/') || str_contains($candidate, "\0") || in_array('..', explode('/', $candidate), true)) {
            throw new RuntimeException('Temporary root must be an absolute path without traversal.');
        }
        $path = '';
        foreach (explode('/', $candidate) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            $path .= '/' . $segment;
            if (is_link($path)) {
                throw new RuntimeException(sprintf('Temporary root contains a symlink: %s', $path));
            }
        }
        $root = realpath($candidate);
        if ($root === false || ! is_dir($root)) {
            throw new RuntimeException(sprintf('Temporary root does not exist: %s', $candidate));
        }
        return $root;
    }

    private static function createPrivateDirectory(string $path): void
    {
        if (! file_exists($path) && ! is_link($path) && ! @mkdir($path, 0700) && ! is_dir($path)) {
            throw new RuntimeException(sprintf('Unable to create private temporary directory %s.', $path));
        }
        if (! self::isOwnedPrivateDirectory($path)) {
            throw new RuntimeException(sprintf('Temporary directory must be owned, private, and not a symlink: %s', $path));
        }
    }

    private function assertWorkspaceIdentity(): void
    {
        // Revalidate all ancestors before cleanup; a replaced root or namespace is not ours.
        self::temporaryRoot(dirname($this->root, 3));
        if (! self::isOwnedPrivateDirectory(dirname($this->root, 2))
            || ! self::isOwnedPrivateDirectory(dirname($this->root))
            || ! self::isOwnedPrivateDirectory($this->root)) {
            throw new RuntimeException(sprintf('Temporary workspace ownership changed; preserving %s.', $this->root));
        }
        $identity = lstat($this->root);
        if (! is_array($identity) || $identity['ino'] !== $this->inode || $identity['dev'] !== $this->device) {
            throw new RuntimeException(sprintf('Temporary workspace was replaced; preserving %s.', $this->root));
        }
    }

    private function releaseLock(): void
    {
        if (is_resource($this->lock)) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
        }
        $this->lock = null;
        $this->closed = true;
    }
}
