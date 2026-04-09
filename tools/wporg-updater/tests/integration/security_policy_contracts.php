<?php

declare(strict_types=1);

use WpOrgPluginUpdater\Config;
use WpOrgPluginUpdater\OutputRedactor;
use WpOrgPluginUpdater\PremiumProviderRegistry;
use WpOrgPluginUpdater\PremiumCredentialsStore;

/**
 * @param callable(bool,string):void $assert
 */
function run_security_policy_contract_tests(callable $assert, string $repoRoot): void
{
    $premiumSingleConfig = Config::fromArray($repoRoot, [
        'profile' => 'content-only',
        'paths' => [
            'content_root' => 'cms',
            'plugins_root' => 'cms/plugins',
            'themes_root' => 'cms/themes',
            'mu_plugins_root' => 'cms/mu-plugins',
        ],
        'core' => ['enabled' => false, 'mode' => 'external'],
        'dependencies' => [
            [
                'name' => 'Premium Plugin',
                'slug' => 'premium-plugin',
                'kind' => 'plugin',
                'management' => 'managed',
                'source' => 'premium',
                'path' => 'cms/plugins/premium-plugin',
                'main_file' => 'premium-plugin.php',
                'version' => '1.0.0',
                'checksum' => 'sha256:test',
                'source_config' => ['provider' => 'example-vendor'],
                'policy' => ['class' => 'managed-premium', 'allow_runtime_paths' => [], 'sanitize_paths' => [], 'sanitize_files' => []],
            ],
        ],
    ]);
    $assert($premiumSingleConfig->dependencyByKey('plugin:premium:example-vendor:premium-plugin')['source_config']['provider'] === 'example-vendor', 'Expected provider-aware premium component keys to resolve directly.');
    $assert($premiumSingleConfig->dependencyByKey('plugin:premium:premium-plugin')['source_config']['provider'] === 'example-vendor', 'Expected legacy premium component keys to remain readable during migration.');
    $premiumAmbiguousConfig = Config::fromArray($repoRoot, [
        'profile' => 'content-only',
        'paths' => [
            'content_root' => 'cms',
            'plugins_root' => 'cms/plugins',
            'themes_root' => 'cms/themes',
            'mu_plugins_root' => 'cms/mu-plugins',
        ],
        'core' => ['enabled' => false, 'mode' => 'external'],
        'dependencies' => [
            [
                'name' => 'Premium Plugin A',
                'slug' => 'shared-plugin',
                'kind' => 'plugin',
                'management' => 'managed',
                'source' => 'premium',
                'path' => 'cms/plugins/shared-plugin-a',
                'main_file' => 'shared-plugin.php',
                'version' => '1.0.0',
                'checksum' => 'sha256:test-a',
                'source_config' => ['provider' => 'vendor-a'],
                'policy' => ['class' => 'managed-premium', 'allow_runtime_paths' => [], 'sanitize_paths' => [], 'sanitize_files' => []],
            ],
            [
                'name' => 'Premium Plugin B',
                'slug' => 'shared-plugin',
                'kind' => 'plugin',
                'management' => 'managed',
                'source' => 'premium',
                'path' => 'cms/plugins/shared-plugin-b',
                'main_file' => 'shared-plugin.php',
                'version' => '1.0.0',
                'checksum' => 'sha256:test-b',
                'source_config' => ['provider' => 'vendor-b'],
                'policy' => ['class' => 'managed-premium', 'allow_runtime_paths' => [], 'sanitize_paths' => [], 'sanitize_files' => []],
            ],
        ],
    ]);
    $ambiguousLegacyPremiumKey = false;

    try {
        $premiumAmbiguousConfig->dependencyByKey('plugin:premium:shared-plugin');
    } catch (RuntimeException $exception) {
        $ambiguousLegacyPremiumKey = str_contains($exception->getMessage(), 'ambiguous');
    }

    $assert($ambiguousLegacyPremiumKey, 'Expected legacy premium component keys to become ambiguous once multiple providers share the same slug.');
    $credentialsStore = new PremiumCredentialsStore(json_encode([
        'plugin:premium:premium-plugin' => ['license_key' => 'legacy-secret'],
    ], JSON_THROW_ON_ERROR));
    $resolvedCredentials = $credentialsStore->credentialsFor([
        'component_key' => 'plugin:premium:example-vendor:premium-plugin',
        'kind' => 'plugin',
        'source' => 'premium',
        'slug' => 'premium-plugin',
        'source_config' => ['provider' => 'example-vendor'],
    ]);
    $assert(($resolvedCredentials['license_key'] ?? null) === 'legacy-secret', 'Expected premium credentials lookup to fall back to legacy premium keys during migration.');
    $redacted = OutputRedactor::redact('Authorization: Bearer very-secret-token https://user:pass@example.com/path');
    $assert(! str_contains($redacted, 'very-secret-token'), 'Expected output redaction to scrub bearer tokens.');
    $assert(! str_contains($redacted, 'user:pass'), 'Expected output redaction to scrub basic-auth URL credentials.');
    $benignUrlRedaction = OutputRedactor::redact('See https://wordpress.org/plugins/example-plugin/ for details.');
    $assert(str_contains($benignUrlRedaction, 'https://wordpress.org/plugins/example-plugin/'), 'Expected benign HTTPS URLs to remain visible in diagnostics.');
    $securityConfig = Config::fromArray($repoRoot, [
        'profile' => 'content-only',
        'paths' => [
            'content_root' => 'cms',
            'plugins_root' => 'cms/plugins',
            'themes_root' => 'cms/themes',
            'mu_plugins_root' => 'cms/mu-plugins',
        ],
        'core' => ['enabled' => false, 'mode' => 'external'],
        'security' => [
            'managed_release_min_age_hours' => 12,
            'github_release_verification' => 'checksum-sidecar-required',
        ],
        'dependencies' => [
            [
                'name' => 'Security Plugin',
                'slug' => 'security-plugin',
                'kind' => 'plugin',
                'management' => 'managed',
                'source' => 'github-release',
                'path' => 'cms/plugins/security-plugin',
                'main_file' => 'security-plugin.php',
                'version' => '1.0.0',
                'checksum' => 'sha256:test',
                'source_config' => [
                    'github_repository' => 'owner/security-plugin',
                    'github_release_asset_pattern' => 'security-plugin-*.zip',
                    'checksum_asset_pattern' => 'security-plugin-*.zip.sha256',
                    'verification_mode' => 'inherit',
                    'min_release_age_hours' => 6,
                ],
                'policy' => ['class' => 'managed-private', 'allow_runtime_paths' => [], 'sanitize_paths' => [], 'sanitize_files' => []],
            ],
            [
                'name' => 'WordPress Org Plugin',
                'slug' => 'wordpress-org-plugin',
                'kind' => 'plugin',
                'management' => 'managed',
                'source' => 'wordpress.org',
                'path' => 'cms/plugins/wordpress-org-plugin',
                'main_file' => 'wordpress-org-plugin.php',
                'version' => '1.0.0',
                'checksum' => 'sha256:test',
                'policy' => ['class' => 'managed-upstream', 'allow_runtime_paths' => [], 'sanitize_paths' => [], 'sanitize_files' => []],
            ],
        ],
    ]);
    $securityDependency = $securityConfig->dependencyByKey('plugin:github-release:security-plugin');
    $assert($securityConfig->managedReleaseMinAgeHours() === 12, 'Expected security.managed_release_min_age_hours to round-trip through config normalization.');
    $assert($securityConfig->githubReleaseVerificationMode() === 'checksum-sidecar-required', 'Expected security.github_release_verification to round-trip through config normalization.');
    $assert($securityConfig->dependencyMinReleaseAgeHours($securityDependency) === 6, 'Expected dependency source_config.min_release_age_hours to override the repo default.');
    $assert($securityConfig->dependencyVerificationMode($securityDependency) === 'checksum-sidecar-required', 'Expected inherit verification mode to resolve to the repo-level GitHub verification default.');
    $assert(($securityDependency['source_config']['checksum_asset_pattern'] ?? null) === 'security-plugin-*.zip.sha256', 'Expected checksum sidecar asset patterns to survive config normalization.');
    $assert(
        $securityConfig->dependencyVerificationMode($securityConfig->dependencyByKey('plugin:wordpress.org:wordpress-org-plugin')) === 'none',
        'Expected non-GitHub managed dependencies to default to no release checksum verification unless they opt in explicitly.'
    );

    $providerScopeRoot = sys_get_temp_dir() . '/wporg-premium-scope-' . bin2hex(random_bytes(4));
    mkdir($providerScopeRoot . '/.wp-core-base', 0777, true);
    file_put_contents($providerScopeRoot . '/.wp-core-base/premium-providers.php', <<<'PHP'
<?php
return [
    'vendor-a' => ['class' => 'SecurityPolicyVendorAProvider'],
    'vendor-b' => ['class' => 'SecurityPolicyVendorBProvider'],
];
PHP);

    $providerScopedStore = new PremiumCredentialsStore(json_encode([
        'vendor-a-key' => ['license_key' => 'a-secret'],
        'vendor-b-key' => ['license_key' => 'b-secret'],
    ], JSON_THROW_ON_ERROR));
    $providerScopedDependencies = [
        [
            'component_key' => 'plugin:premium:vendor-a:premium-a',
            'source' => 'premium',
            'kind' => 'plugin',
            'slug' => 'premium-a',
            'source_config' => [
                'provider' => 'vendor-a',
                'credential_key' => 'vendor-a-key',
            ],
        ],
        [
            'component_key' => 'plugin:premium:vendor-b:premium-b',
            'source' => 'premium',
            'kind' => 'plugin',
            'slug' => 'premium-b',
            'source_config' => [
                'provider' => 'vendor-b',
                'credential_key' => 'vendor-b-key',
            ],
        ],
    ];
    $providerSources = PremiumProviderRegistry::load($providerScopeRoot)->instantiate(
        new \WpOrgPluginUpdater\HttpClient(),
        $providerScopedStore,
        $providerScopedDependencies
    );
    $assert($providerSources['vendor-a'] instanceof SecurityPolicyVendorAProvider, 'Expected vendor-a provider class to instantiate in scoped credential contract test.');
    $assert($providerSources['vendor-b'] instanceof SecurityPolicyVendorBProvider, 'Expected vendor-b provider class to instantiate in scoped credential contract test.');
    $assert(
        $providerSources['vendor-a']->visibleCredentialKeys() === ['vendor-a-key'],
        'Expected vendor-a provider to see only its own credential keys.'
    );
    $assert(
        $providerSources['vendor-b']->visibleCredentialKeys() === ['vendor-b-key'],
        'Expected vendor-b provider to see only its own credential keys.'
    );

    $unscopedAttempt = PremiumProviderRegistry::load($providerScopeRoot)->instantiate(
        new \WpOrgPluginUpdater\HttpClient(),
        $providerScopedStore,
        []
    );
    $assert(
        $unscopedAttempt['vendor-a']->visibleCredentialKeys() === [],
        'Expected provider-scoped credentials to default to empty when no managed premium dependencies are present.'
    );
    $assert(
        $unscopedAttempt['vendor-b']->visibleCredentialKeys() === [],
        'Expected provider-scoped credentials to default to empty for every provider when dependencies are absent.'
    );
    $assert(
        $providerSources['vendor-a']->rejectsDependencyForProvider('vendor-b'),
        'Expected provider-scoped credentials to reject cross-provider dependency lookups.'
    );
}

final class SecurityPolicyVendorAProvider extends \WpOrgPluginUpdater\AbstractPremiumManagedSource
{
    public function key(): string
    {
        return 'vendor-a';
    }

    public function fetchCatalog(array $dependency): array
    {
        return ['latest_version' => '1.0.0', 'latest_release_at' => gmdate(DATE_ATOM)];
    }

    public function releaseDataForVersion(array $dependency, array $catalog, string $targetVersion, string $fallbackReleaseAt): array
    {
        return ['version' => $targetVersion, 'release_at' => $fallbackReleaseAt];
    }

    public function downloadReleaseToFile(array $dependency, array $releaseData, string $destination): void
    {
    }

    /**
     * @return list<string>
     */
    public function visibleCredentialKeys(): array
    {
        $keys = array_keys($this->credentialsStore->all());
        sort($keys);
        return $keys;
    }

    public function rejectsDependencyForProvider(string $provider): bool
    {
        try {
            $this->credentialsStore->credentialsFor([
                'component_key' => 'plugin:premium:' . $provider . ':cross-provider-probe',
                'kind' => 'plugin',
                'source' => 'premium',
                'slug' => 'cross-provider-probe',
                'source_config' => ['provider' => $provider],
            ]);
        } catch (RuntimeException) {
            return true;
        }

        return false;
    }
}

final class SecurityPolicyVendorBProvider extends \WpOrgPluginUpdater\AbstractPremiumManagedSource
{
    public function key(): string
    {
        return 'vendor-b';
    }

    public function fetchCatalog(array $dependency): array
    {
        return ['latest_version' => '1.0.0', 'latest_release_at' => gmdate(DATE_ATOM)];
    }

    public function releaseDataForVersion(array $dependency, array $catalog, string $targetVersion, string $fallbackReleaseAt): array
    {
        return ['version' => $targetVersion, 'release_at' => $fallbackReleaseAt];
    }

    public function downloadReleaseToFile(array $dependency, array $releaseData, string $destination): void
    {
    }

    /**
     * @return list<string>
     */
    public function visibleCredentialKeys(): array
    {
        $keys = array_keys($this->credentialsStore->all());
        sort($keys);
        return $keys;
    }
}
