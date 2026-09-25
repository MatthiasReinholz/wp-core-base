<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class PremiumProviderScaffolder
{
    public function __construct(
        private readonly string $frameworkRoot,
        private readonly string $repoRoot,
    ) {
    }

    /**
     * @return array{provider:string,class:string,path:string,registry_path:string}
     */
    public function scaffold(string $provider, ?string $class = null, ?string $path = null, bool $force = false): array
    {
        $normalizedProvider = $this->normalizeProvider($provider);
        $registry = PremiumProviderRegistry::load($this->repoRoot);
        $className = $class !== null && trim($class) !== '' ? trim($class) : $this->defaultClassName($normalizedProvider);
        if (preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*$/D', $className) !== 1) {
            throw new RuntimeException('Premium provider class must be a valid PHP class name with an optional namespace.');
        }
        $relativePath = $path !== null && trim($path) !== '' ? $this->normalizePath($path) : '.wp-core-base/premium-providers/' . $normalizedProvider . '.php';
        ConfigPathRules::assertSafePremiumProviderPath($this->repoRoot, $relativePath);
        $absolutePath = $this->repoRoot . '/' . $relativePath;
        $definitions = $registry->definitions();
        $existing = $definitions[$normalizedProvider] ?? null;
        $registryPath = $registry->path();

        if ($existing !== null && ! $force) {
            throw new RuntimeException(sprintf(
                'Premium provider `%s` is already registered in %s. Re-run with --force to rewrite the registry entry and scaffold file.',
                $normalizedProvider,
                $registryPath
            ));
        }

        $definitions[$normalizedProvider] = [
            'class' => $className,
            'path' => $relativePath,
        ];

        $previousRegistryContents = is_file($registryPath) ? file_get_contents($registryPath) : null;
        $previousClassContents = is_file($absolutePath) ? file_get_contents($absolutePath) : null;

        try {
            ConfigPathRules::assertNoSymlinkDescendants($this->repoRoot, $relativePath);
            ConfigPathRules::assertNoSymlinkDescendants($this->repoRoot, '.wp-core-base/premium-providers.php');
            $this->writeProviderClass($normalizedProvider, $className, $absolutePath, $force);
            ConfigPathRules::assertNoSymlinkDescendants($this->repoRoot, '.wp-core-base/premium-providers.php');
            (new PremiumProviderRegistryWriter())->write($registryPath, $definitions);
        } catch (RuntimeException $exception) {
            $this->restoreFile($registryPath, $previousRegistryContents);
            $this->restoreFile($absolutePath, $previousClassContents);
            throw $exception;
        }

        return [
            'provider' => $normalizedProvider,
            'class' => $className,
            'path' => $relativePath,
            'registry_path' => $registryPath,
        ];
    }

    private function normalizeProvider(string $provider): string
    {
        $normalized = trim($provider);

        PremiumSourceResolver::assertCustomProviderKey($normalized);

        return $normalized;
    }

    private function normalizePath(string $path): string
    {
        return ConfigPathRules::normalizedRelativePath($path, 'premium provider class path');
    }

    private function defaultClassName(string $provider): string
    {
        $segments = array_map(
            static fn (string $segment): string => ucfirst($segment),
            explode('-', $provider)
        );

        return 'Project\\WpCoreBase\\Premium\\' . implode('', $segments) . 'ManagedSource';
    }

    private function writeProviderClass(string $provider, string $className, string $absolutePath, bool $force): void
    {
        $directory = dirname($absolutePath);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create premium provider class directory: %s', $directory));
        }

        if (is_file($absolutePath) && ! $force) {
            throw new RuntimeException(sprintf(
                'Premium provider class file already exists: %s. Re-run with --force to overwrite the scaffold.',
                $absolutePath
            ));
        }

        $templatePath = $this->frameworkRoot . '/tools/wporg-updater/templates/custom-premium-provider.php.tpl';
        $template = file_get_contents($templatePath);

        if ($template === false) {
            throw new RuntimeException(sprintf('Unable to read premium provider template: %s', $templatePath));
        }

        $namespace = '';
        $shortClassName = $className;

        if (str_contains($className, '\\')) {
            $lastSeparator = strrpos($className, '\\');
            $namespace = substr($className, 0, $lastSeparator);
            $shortClassName = substr($className, $lastSeparator + 1);
        }

        $rendered = str_replace(
            ['__NAMESPACE_DECLARATION__', '__CLASS_NAME__', '__PROVIDER_KEY__'],
            [
                $namespace !== '' ? "namespace {$namespace};\n" : '',
                $shortClassName,
                $provider,
            ],
            $template
        );

        ConfigPathRules::assertNoSymlinkDescendants($this->repoRoot, substr($absolutePath, strlen(rtrim($this->repoRoot, '/')) + 1));
        try {
            $tokens = token_get_all($rendered, TOKEN_PARSE);
            if ($tokens === [] || ! is_array($tokens[0]) || $tokens[0][0] !== T_OPEN_TAG) {
                throw new RuntimeException('Premium provider template must begin with a PHP opening tag.');
            }
        } catch (\ParseError $exception) {
            throw new RuntimeException('Premium provider class name does not produce valid PHP.', 0, $exception);
        }
        (new AtomicFileWriter())->write($absolutePath, $rendered);
    }

    private function restoreFile(string $path, string|false|null $contents): void
    {
        ConfigPathRules::assertNoSymlinkDescendants($this->repoRoot, substr($path, strlen(rtrim($this->repoRoot, '/')) + 1));
        if ($contents === false) {
            return;
        }

        if ($contents === null) {
            if (is_file($path)) {
                unlink($path);
            }

            return;
        }

        file_put_contents($path, $contents);
    }
}
