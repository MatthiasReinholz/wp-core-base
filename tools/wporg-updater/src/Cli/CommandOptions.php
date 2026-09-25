<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater\Cli;

use RuntimeException;

/** Single CLI option contract, reusable by the entrypoint, help, and documentation checks. */
final class CommandOptions
{
    /** @return array<string,array<string,'flag'|'value'>> */
    public static function definitions(): array
    {
        $common = ['repo-root' => 'value', 'tool-path' => 'value', 'help' => 'flag'];
        $hosted = self::values(['github-repository', 'github-release-asset-pattern', 'github-token-env',
            'gitlab-project', 'gitlab-release-asset-pattern', 'gitlab-token-env', 'gitlab-api-base']);
        $dependency = [
            ...$hosted,
            ...self::values(['source', 'kind', 'slug', 'path', 'main-file', 'name', 'version', 'archive-subdir',
                'generic-json-url', 'credential-key', 'provider', 'provider-product-id']),
            ...self::flags(['private', 'plan', 'preview', 'dry-run', 'json']),
        ];
        $commands = [
            'help' => [],
            'doctor' => self::flags(['automation', 'github', 'json']),
            'sync' => ['report-json' => 'value', 'fail-on-source-errors' => 'flag'],
            'render-sync-report' => self::values(['report-json', 'summary-path']),
            'sync-report-issue' => self::values(['report-json']),
            'stage-runtime' => ['output' => 'value', 'json' => 'flag'],
            'refresh-admin-governance' => [],
            'suggest-manifest' => [],
            'format-manifest' => [],
            'scaffold-downstream' => [
                ...self::values(['profile', 'content-root', 'automation-provider']),
                ...self::flags(['force', 'adopt-existing-managed-files']),
            ],
            'scaffold-premium-provider' => [...self::values(['provider', 'class', 'path']), 'force' => 'flag'],
            'framework-apply' => self::values(['payload-root', 'distribution-path', 'result-path']),
            'framework-sync' => self::flags(['check-only', 'fail-on-skipped-managed-files', 'json']),
            'prepare-framework-release' => [...self::values(['release-type', 'version']), 'allow-current-version' => 'flag'],
            'build-release-artifact' => [...self::values(['output', 'checksum-file', 'source-revision']), ...self::flags(['fixture', 'json'])],
            'release-sign' => self::values(['artifact', 'checksum-file', 'signature-file', 'private-key-env', 'passphrase-env']),
            'release-verify' => [...self::values(['tag', 'artifact', 'checksum-file', 'signature-file', 'public-key-file']), 'json' => 'flag'],
            'add-dependency' => [...$dependency, 'management' => 'value', ...self::flags(['replace', 'force', 'interactive'])],
            'adopt-dependency' => [...$dependency, ...self::values(['component-key', 'from-source']), 'preserve-version' => 'flag'],
            'remove-dependency' => [...self::values(['component-key', 'slug', 'kind', 'source']), 'delete-path' => 'flag'],
            'list-dependencies' => self::flags(['freshness', 'fail-on-source-errors', 'json']),
            'inspect-release-assets' => [...$hosted, ...self::values(['source', 'checksum-asset-pattern', 'tag', 'version']), 'json' => 'flag'],
            'pr-blocker' => ['pr-number' => 'value', 'json' => 'flag'],
            'pr-blocker-reconcile' => ['json' => 'flag'],
            'managed-pr-cleanup' => ['pr-number' => 'value', 'json' => 'flag'],
        ];
        foreach ($commands as $mode => $options) {
            $commands[$mode] = [...$common, ...$options];
            ksort($commands[$mode]);
        }
        return $commands;
    }

    /** @return array<string,'flag'|'value'> */
    public static function forCommand(string $mode): array
    {
        $mode = in_array($mode, ['--help', '-h'], true) ? 'help' : $mode;
        $definition = self::definitions()[$mode] ?? null;
        if ($definition === null) {
            throw new RuntimeException(sprintf('Unknown mode: %s.', $mode));
        }
        return $definition;
    }

    /**
     * Values use --name=value. A bare flag is true; assigning values to flags is rejected.
     * The sole positional exception is `help COMMAND`, which the help renderer consumes.
     * @param list<string> $arguments
     * @return array<string,string|true>
     */
    public static function parse(string $mode, array $arguments): array
    {
        $definition = self::forCommand($mode);
        $helpMode = in_array($mode, ['help', '--help', '-h'], true);
        $topicSeen = false;
        $options = [];
        foreach ($arguments as $argument) {
            if (! str_starts_with($argument, '--')) {
                if ($helpMode && ! $topicSeen && ($argument === 'general' || isset(self::definitions()[$argument]))) {
                    $topicSeen = true;
                    continue;
                }
                throw new RuntimeException(sprintf('Unexpected positional argument: %s. Use --name=value for option values.', $argument));
            }
            $parts = explode('=', substr($argument, 2), 2);
            $name = $parts[0];
            $type = $definition[$name] ?? null;
            if ($type === null) {
                throw new RuntimeException(sprintf('Unknown option for %s: --%s.', $mode, $name));
            }
            if (array_key_exists($name, $options)) {
                throw new RuntimeException(sprintf('Option --%s may only be supplied once.', $name));
            }
            if ($type === 'flag') {
                if (count($parts) !== 1) {
                    throw new RuntimeException(sprintf('Flag --%s does not accept a value; omit it for false.', $name));
                }
                $options[$name] = true;
                continue;
            }
            $value = $parts[1] ?? '';
            if (trim($value) === '' || str_contains($value, "\0")) {
                throw new RuntimeException(sprintf('Option --%s requires a non-empty value using --%s=value.', $name, $name));
            }
            if (in_array($name, ['pr-number', 'provider-product-id'], true)
                && filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw new RuntimeException(sprintf('Option --%s requires a positive integer.', $name));
            }
            $options[$name] = $value;
        }
        return $options;
    }

    /** @param list<string> $names @return array<string,'value'> */
    private static function values(array $names): array
    {
        return array_fill_keys($names, 'value');
    }

    /** @param list<string> $names @return array<string,'flag'> */
    private static function flags(array $names): array
    {
        return array_fill_keys($names, 'flag');
    }
}
