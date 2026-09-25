<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

/** Core archives cannot replace independently owned plugins, themes or content. */
final class CoreContentOwnership
{
    public function __construct(private readonly Config $config)
    {
    }

    public function mayReplace(string $relativePath, bool $isDirectory): bool
    {
        // Bundled packages have their own manifest/source lifecycle. A core
        // release is not authorization to install or replace those packages.
        if ($isDirectory) {
            return false;
        }

        $comparisonPath = ConfigPathRules::filesystemPath($this->config->repoRoot, $relativePath);
        foreach ($this->config->dependencies() as $dependency) {
            $ownedPath = ConfigPathRules::filesystemPath($this->config->repoRoot, (string) $dependency['path']);
            if ($comparisonPath === $ownedPath
                || str_starts_with($comparisonPath, $ownedPath . '/')
                || str_starts_with($ownedPath, $comparisonPath . '/')) {
                return false;
            }
        }

        // Only the standard directory-index stubs belong to core. A loose
        // bundled plugin (for example hello.php) still needs explicit ownership.
        return in_array($relativePath, [
            $this->config->paths['content_root'] . '/index.php',
            $this->config->paths['plugins_root'] . '/index.php',
            $this->config->paths['themes_root'] . '/index.php',
        ], true);
    }
}
