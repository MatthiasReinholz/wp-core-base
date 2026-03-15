<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class Config
{
    /**
     * @param list<array<string, mixed>> $plugins
     */
    public function __construct(
        public readonly string $repoRoot,
        public readonly string $configPath,
        public readonly ?string $baseBranch,
        public readonly int $supportMaxPages,
        public readonly string $githubApiBase,
        public readonly bool $dryRun,
        public readonly array $core,
        public readonly array $plugins,
    ) {
    }

    public static function load(string $repoRoot, ?string $configPath = null): self
    {
        $resolvedConfig = $configPath ?? $repoRoot . '/.github/wporg-updates.php';

        if (! is_file($resolvedConfig)) {
            throw new RuntimeException(sprintf('Config file not found: %s', $resolvedConfig));
        }

        $data = require $resolvedConfig;

        if (! is_array($data)) {
            throw new RuntimeException('Config file must return an array.');
        }

        $plugins = $data['plugins'] ?? null;

        if (! is_array($plugins)) {
            throw new RuntimeException('Config file must contain a plugins array.');
        }

        return new self(
            repoRoot: $repoRoot,
            configPath: $resolvedConfig,
            baseBranch: self::nullableString($data['base_branch'] ?? null),
            supportMaxPages: self::positiveInt($data['support_max_pages'] ?? 30, 'support_max_pages'),
            githubApiBase: self::string($data['github_api_base'] ?? 'https://api.github.com', 'github_api_base'),
            dryRun: (bool) ($data['dry_run'] ?? false),
            core: self::normalizeCoreConfig($data['core'] ?? ['enabled' => true]),
            plugins: array_values(array_filter($plugins, static function (mixed $plugin): bool {
                return is_array($plugin) && (bool) ($plugin['enabled'] ?? false);
            })),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function enabledPlugins(): array
    {
        return array_map(function (array $plugin): array {
            foreach (['slug', 'path', 'main_file'] as $required) {
                if (! isset($plugin[$required]) || ! is_string($plugin[$required]) || $plugin[$required] === '') {
                    throw new RuntimeException(sprintf('Plugin config is missing required string key "%s".', $required));
                }
            }

            $plugin['extra_labels'] = array_values(array_filter(
                is_array($plugin['extra_labels'] ?? null) ? $plugin['extra_labels'] : [],
                static fn (mixed $value): bool => is_string($value) && $value !== ''
            ));
            $plugin['support_max_pages'] = array_key_exists('support_max_pages', $plugin)
                ? self::positiveInt($plugin['support_max_pages'], sprintf('plugins[%s].support_max_pages', (string) $plugin['slug']))
                : null;

            return $plugin;
        }, $this->plugins);
    }

    /**
     * @return array{enabled:bool}
     */
    public function coreConfig(): array
    {
        return $this->core;
    }

    private static function string(mixed $value, string $key): string
    {
        if (! is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Config value "%s" must be a non-empty string.', $key));
        }

        return $value;
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new RuntimeException('Nullable string config value must be null or a string.');
        }

        return $value;
    }

    private static function positiveInt(mixed $value, string $key): int
    {
        if (! is_int($value) || $value < 1) {
            throw new RuntimeException(sprintf('Config value "%s" must be a positive integer.', $key));
        }

        return $value;
    }

    /**
     * @return array{enabled:bool}
     */
    private static function normalizeCoreConfig(mixed $value): array
    {
        if (! is_array($value)) {
            throw new RuntimeException('Core config must be an array.');
        }

        return [
            'enabled' => (bool) ($value['enabled'] ?? true),
        ];
    }
}
