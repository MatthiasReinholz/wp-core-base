<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

class PremiumCredentialsStore
{
    public function __construct(
        private readonly ?string $json = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function credentialsFor(array $dependency): array
    {
        $all = $this->all();
        $lookupKey = $this->lookupKeyFor($dependency);
        $credentials = $all[$lookupKey] ?? null;

        if (! is_array($credentials)) {
            throw new RuntimeException(sprintf(
                'Premium credentials are missing for %s. Provide %s with an entry for %s.',
                (string) ($dependency['component_key'] ?? 'unknown'),
                self::envName(),
                $lookupKey
            ));
        }

        return $credentials;
    }

    public function lookupKeyFor(array $dependency): string
    {
        $override = $dependency['source_config']['credential_key'] ?? null;

        if (is_string($override) && trim($override) !== '') {
            return trim($override);
        }

        $all = $this->all();

        foreach ($this->lookupKeysFor($dependency) as $lookupKey) {
            if (isset($all[$lookupKey])) {
                return $lookupKey;
            }
        }

        $componentKey = $dependency['component_key'] ?? null;

        if (! is_string($componentKey) || $componentKey === '') {
            throw new RuntimeException('Premium dependency is missing component_key.');
        }

        return $componentKey;
    }

    /**
     * @param array<string, mixed> $dependency
     * @return list<string>
     */
    public function lookupKeysFor(array $dependency): array
    {
        $override = $dependency['source_config']['credential_key'] ?? null;

        if (is_string($override) && trim($override) !== '') {
            return [trim($override)];
        }

        $componentKey = $dependency['component_key'] ?? null;

        if (! is_string($componentKey) || $componentKey === '') {
            throw new RuntimeException('Premium dependency is missing component_key.');
        }

        return array_values(array_unique(array_merge(
            [$componentKey],
            PremiumSourceResolver::legacyComponentKeysForDependency($dependency)
        )));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $raw = $this->json;

        if ($raw === null) {
            $env = getenv(self::envName());
            $raw = is_string($env) ? $env : null;
        }

        if ($raw === null || trim($raw) === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException(sprintf(
                '%s must contain valid JSON.',
                self::envName()
            ), previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException(sprintf('%s must decode to an object.', self::envName()));
        }

        $normalized = [];

        foreach ($decoded as $lookupKey => $credentials) {
            if (! is_string($lookupKey) || trim($lookupKey) === '') {
                throw new RuntimeException(sprintf('%s keys must be non-empty strings.', self::envName()));
            }

            if (! is_array($credentials)) {
                throw new RuntimeException(sprintf(
                    '%s[%s] must be an object.',
                    self::envName(),
                    $lookupKey
                ));
            }

            $normalized[trim($lookupKey)] = $credentials;
        }

        return $normalized;
    }

    /**
     * @param list<string> $requiredFields
     */
    public function assertRequiredFields(array $dependency, array $requiredFields): void
    {
        $credentials = $this->credentialsFor($dependency);
        $missing = [];

        foreach ($requiredFields as $field) {
            $value = $credentials[$field] ?? null;

            if (! is_string($value) || trim($value) === '') {
                $missing[] = $field;
            }
        }

        if ($missing !== []) {
            throw new RuntimeException(sprintf(
                'Premium credentials for %s are missing required fields: %s.',
                $this->lookupKeyFor($dependency),
                implode(', ', $missing)
            ));
        }
    }

    public static function envName(): string
    {
        return 'WP_CORE_BASE_PREMIUM_CREDENTIALS_JSON';
    }

    public static function redact(string $message, array $credentials): string
    {
        foreach ($credentials as $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $message = str_replace($value, '[REDACTED]', $message);
        }

        return preg_replace_callback(
            '#https://[^\s]+#i',
            static function (array $matches): string {
                $url = $matches[0];
                $parts = parse_url($url);

                if (! is_array($parts) || (! isset($parts['user']) && ! isset($parts['pass']))) {
                    return $url;
                }

                return preg_replace('#https://([^/\s:@]+):([^@\s/]+)@#i', 'https://[REDACTED]:[REDACTED]@', $url) ?? $url;
            },
            $message
        ) ?? $message;
    }
}
