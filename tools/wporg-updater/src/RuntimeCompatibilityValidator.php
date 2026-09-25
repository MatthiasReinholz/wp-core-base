<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use DirectoryIterator;
use RuntimeException;

final class RuntimeCompatibilityValidator
{
    public function __construct(private readonly Config $config) {}

    public function assertPluginCoreCompatibility(string $runtimeRoot): void
    {
        // An externally supplied core must be checked by the deployment that owns it.
        if ($this->config->profile !== 'full-core' || ! $this->config->coreManaged()) {
            return;
        }

        // Validate the assembled payload, including relaxed and allowlisted inputs.
        // A true value means WordPress requires a Plugin Name header to discover it.
        $mainFiles = [];
        foreach ($this->config->dependencies() as $dependency) {
            $kind = (string) $dependency['kind'];
            if (! $this->config->shouldStageDependency($dependency)
                || ! in_array($kind, ['plugin', 'mu-plugin-package', 'mu-plugin-file'], true)) {
                continue;
            }

            $mainFile = (string) $dependency['path'];
            if ($kind !== 'mu-plugin-file') {
                $mainFile .= '/' . (string) $dependency['main_file'];
            }
            $mainFiles[$mainFile] = false;
        }
        foreach ($this->pluginFiles($runtimeRoot, $this->config->paths['plugins_root'], true, true) as $mainFile) {
            $mainFiles[$mainFile] ??= true;
        }
        foreach ($this->pluginFiles($runtimeRoot, $this->config->paths['mu_plugins_root'], false, false) as $mainFile) {
            $mainFiles[$mainFile] = false;
        }

        $coreVersion = null;
        foreach ($mainFiles as $mainFile => $requiresPluginName) {
            ConfigPathRules::assertNoSymlinkDescendants($runtimeRoot, $mainFile);
            $absoluteMainFile = $runtimeRoot . '/' . $mainFile;
            if (! is_file($absoluteMainFile)) {
                throw new RuntimeException(sprintf('Main dependency file not found: %s', $absoluteMainFile));
            }
            $header = file_get_contents($absoluteMainFile, false, null, 0, 8192);
            if (! is_string($header)) {
                throw new RuntimeException(sprintf('Failed to read dependency file: %s', $absoluteMainFile));
            }

            // Match WordPress get_file_data(): first 8 KiB, CR line endings, comment terminators.
            $header = str_replace("\r", "\n", $header);
            if ($requiresPluginName && $this->headerValue($header, 'Plugin Name') === '') {
                continue;
            }
            $required = $this->headerValue($header, 'Requires at least');
            if ($required === '') {
                continue;
            }

            if ($coreVersion === null) {
                ConfigPathRules::assertNoSymlinkDescendants($runtimeRoot, 'wp-includes/version.php');
                $coreVersion = (new CoreScanner())->inspect($runtimeRoot)['version'];
            }
            // Follow WordPress's minimum-version comparison, including 7.0 vs 7.0.0.
            $comparisonRequired = substr_count($required, '.') > 1 && str_ends_with($required, '.0')
                ? substr($required, 0, -2) : $required;
            $comparisonCore = explode('-', $coreVersion, 2)[0];
            if (version_compare($comparisonCore, $comparisonRequired, '<')) {
                throw new RuntimeException(sprintf(
                    'Plugin %s requires WordPress %s or newer; local managed core is %s. Update core through a reviewed migration or select a compatible plugin release before staging.',
                    $mainFile,
                    $required,
                    $coreVersion
                ));
            }
        }
    }

    private function headerValue(string $contents, string $name): string
    {
        $pattern = '/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote($name, '/') . ':(.*)$/mi';
        if (preg_match($pattern, $contents, $matches) !== 1) {
            return '';
        }
        return trim(preg_replace('/\s*(?:\*\/|\?>).*/', '', $matches[1]) ?? '');
    }

    /** @return list<string> */
    private function pluginFiles(string $runtimeRoot, string $directory, bool $includeSubdirectories, bool $skipHidden): array
    {
        ConfigPathRules::assertNoSymlinkDescendants($runtimeRoot, $directory);
        if (! is_dir($runtimeRoot . '/' . $directory)) {
            return [];
        }
        $files = [];
        foreach (new DirectoryIterator($runtimeRoot . '/' . $directory) as $entry) {
            $name = $entry->getFilename();
            if ($entry->isDot() || ($skipHidden && str_starts_with($name, '.'))) {
                continue;
            }
            $path = $directory . '/' . $name;
            ConfigPathRules::assertNoSymlinkDescendants($runtimeRoot, $path);
            if ($entry->isDir() && $includeSubdirectories) {
                $files = array_merge($files, $this->pluginFiles($runtimeRoot, $path, false, true));
            } elseif ($entry->isFile() && str_ends_with($name, '.php')) {
                $files[] = $path;
            }
        }
        sort($files);
        return $files;
    }
}
