# Adding a Premium Provider

`wp-core-base` does not ship vendor-specific premium source adapters.

If a downstream repository wants premium workflow updates, it registers its own provider adapter in the downstream repo.

This is the supported path for any premium vendor integration. There are no built-in premium providers.

## Files

Use these downstream-owned files:

- `.wp-core-base/premium-providers.php`
- `.wp-core-base/premium-providers/<provider>.php`

The registry file maps provider keys to provider classes. The class file implements the provider-specific HTTP contract.

The `path` field is optional when the class is already autoloadable. The normal downstream pattern is to keep the provider file in the repo and let `wp-core-base` load it directly from `path`.

## Fastest Path

Scaffold a provider:

```bash
vendor/wp-core-base/bin/wp-core-base scaffold-premium-provider \
  --repo-root=. \
  --provider=example-vendor
```

That writes:

- `.wp-core-base/premium-providers.php`
- `.wp-core-base/premium-providers/example-vendor.php`

## Agent Checklist

If you are a coding agent implementing a premium provider inside a downstream repo, use this sequence:

1. read `.wp-core-base/premium-providers.php`
2. reuse an existing provider if it already matches the upstream contract
3. scaffold a provider only when no matching provider exists yet
4. implement the generated class file
5. set `WP_CORE_BASE_PREMIUM_CREDENTIALS_JSON`
6. run `doctor --repo-root=. --automation`
7. if the plugin already exists as a local dependency, use `adopt-dependency`; otherwise use `add-dependency`
8. run `stage-runtime`

Do not:

- store secrets in `.wp-core-base/manifest.php`
- invent provider names that are not registered
- assume premium support is built in for any vendor
- model a non-deterministic browser-only flow as a managed premium source

For agents, prefer explicit non-interactive commands. Use interactive mode only when a human explicitly asks for prompts.

## Registry Format

The registry file returns an array like this:

```php
<?php

declare(strict_types=1);

return [
    'example-vendor' => [
        'class' => 'Project\\WpCoreBase\\Premium\\ExampleVendorManagedSource',
        'path' => '.wp-core-base/premium-providers/example-vendor.php',
    ],
];
```

Rules:

- provider keys must use lowercase letters, numbers, and hyphens
- provider keys may not collide with reserved built-in source names such as `wordpress.org`, `github-release`, `gitlab-release`, `generic-json`, `premium`, or `local`
- the class must be constructible with `(HttpClient, PremiumCredentialsStore)`
- the class must implement `PremiumManagedDependencySource`
- the class `key()` must match the registry key

## Provider Class Contract

The recommended base class is `AbstractPremiumManagedSource`.

You must implement:

- `key()`
- `fetchCatalog()`
- `releaseDataForVersion()`
- `downloadReleaseToFile()`

You may also override:

- `validateCredentialConfiguration()`
- `requiredCredentialFields()`

The default `validateCredentialConfiguration()` behavior only checks the fields returned by `requiredCredentialFields()`.

### Method Contracts

#### `fetchCatalog(array $dependency): array`

This method should inspect the upstream premium service and return enough metadata for version resolution.

Required keys:

- `latest_version` as a non-empty string
- `latest_release_at` as an ISO-8601 timestamp string

Optional keys:

- any provider-specific payload you want to carry forward into `releaseDataForVersion()`

Minimal example:

```php
return [
    'latest_version' => '6.3.0',
    'latest_release_at' => '2026-03-25T10:15:00+00:00',
    'payload' => [
        'download_url' => 'https://vendor.example.com/download/plugin.zip',
    ],
];
```

#### `releaseDataForVersion(array $dependency, array $catalog, string $targetVersion, string $fallbackReleaseAt): array`

This method should resolve one concrete release for the requested version.

Required keys:

- `version` as the exact version string that will be installed
- `release_at` as an ISO-8601 timestamp string
- `download_url` when your `downloadReleaseToFile()` implementation expects a direct URL

Recommended keys:

- `source_reference` as a human-readable upstream reference used in PR metadata
- `source_details` as a list of `['label' => string, 'value' => string]` items for PR detail rendering
- any provider-specific data that `downloadReleaseToFile()` needs

Minimal example:

```php
return [
    'version' => $targetVersion,
    'release_at' => (string) ($catalog['latest_release_at'] ?? $fallbackReleaseAt),
    'download_url' => (string) $catalog['payload']['download_url'],
    'source_reference' => 'https://vendor.example.com/releases/plugin',
    'source_details' => [
        ['label' => 'Source', 'value' => '`premium` provider `example-vendor`'],
    ],
];
```

#### `downloadReleaseToFile(array $dependency, array $releaseData, string $destination): void`

This method must write the release archive to `$destination`.

Common patterns:

- call `$this->downloadBinary((string) $releaseData['download_url'], $destination, $headers)`
- or make a provider-specific authenticated request and write the returned ZIP body

The updater will then:

- extract the archive
- apply managed sanitation
- validate runtime hygiene
- install the normalized runtime snapshot
- compute checksums from the sanitized runtime payload

## Worked End-to-End Flow

1. Scaffold the provider:

```bash
vendor/wp-core-base/bin/wp-core-base scaffold-premium-provider \
  --repo-root=. \
  --provider=example-vendor
```

2. Implement `.wp-core-base/premium-providers/example-vendor.php`.

3. Export credentials locally:

```bash
export WP_CORE_BASE_PREMIUM_CREDENTIALS_JSON='{"plugin:premium:example-vendor:premium-plugin":{"license_key":"provider-specific-secret"}}'
```

4. Verify the repo configuration:

```bash
php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php doctor --repo-root=. --automation
```

5. Add the plugin:

```bash
vendor/wp-core-base/bin/wp-core-base add-dependency \
  --repo-root=. \
  --source=premium \
  --provider=example-vendor \
  --kind=plugin \
  --slug=premium-plugin
```

6. Validate the staged runtime:

```bash
php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php stage-runtime --repo-root=. --output=.wp-core-base/build/runtime
```

If this flow works locally and `doctor --automation` passes in CI, the provider is integrated correctly.

### Credential Validation

If your provider needs credentials such as `license_key`, `site_url`, or account-specific IDs, expose that through `requiredCredentialFields()` or a custom `validateCredentialConfiguration()` implementation.

Minimal example:

```php
protected function requiredCredentialFields(): array
{
    return ['license_key'];
}
```

If your provider needs more complex validation, override `validateCredentialConfiguration()` directly.

## Credentials

Premium provider credentials always come from:

- `WP_CORE_BASE_PREMIUM_CREDENTIALS_JSON`

Lookup rules:

- by default, credentials are looked up by the dependency component key, for example `plugin:premium:example-vendor:premium-plugin`
- if the manifest sets `source_config.credential_key`, that override is used instead

The manifest stores only:

- `source: premium`
- `source_config.provider`
- optional `source_config.credential_key`
- optional `source_config.provider_product_id`

It never stores raw secrets.

Minimal manifest example:

```php
[
    'name' => 'Premium Plugin',
    'slug' => 'premium-plugin',
    'kind' => 'plugin',
    'management' => 'managed',
    'source' => 'premium',
    'path' => 'cms/plugins/premium-plugin',
    'main_file' => 'premium-plugin.php',
    'version' => '1.2.3',
    'checksum' => '...',
    'archive_subdir' => '',
    'source_config' => [
        'provider' => 'example-vendor',
        'credential_key' => null,
        'provider_product_id' => null,
    ],
]
```

Minimal credentials JSON example:

```json
{
  "plugin:premium:example-vendor:premium-plugin": {
    "license_key": "provider-specific-secret"
  }
}
```

Shared-account example with `credential_key` override:

```php
'source_config' => [
    'provider' => 'example-vendor',
    'credential_key' => 'example-vendor:team-license',
    'provider_product_id' => 42,
],
```

```json
{
  "example-vendor:team-license": {
    "license_key": "provider-specific-secret",
    "site_url": "https://example.com"
  }
}
```

## Add A Dependency

After the provider is registered:

```bash
vendor/wp-core-base/bin/wp-core-base add-dependency \
  --repo-root=. \
  --source=premium \
  --provider=example-vendor \
  --kind=plugin \
  --slug=premium-plugin
```

## Minimal Provider Skeleton

The scaffold gives you the class shape. A minimal provider usually looks like this:

```php
final class ExampleVendorManagedSource extends AbstractPremiumManagedSource
{
    public function key(): string
    {
        return 'example-vendor';
    }

    protected function requiredCredentialFields(): array
    {
        return ['license_key'];
    }

    public function fetchCatalog(array $dependency): array
    {
        $credentials = $this->credentialsFor($dependency, $this->requiredCredentialFields());

        return $this->requestJson(
            'GET',
            'https://vendor.example.com/api/latest',
            ['Authorization' => 'Bearer ' . $credentials['license_key']]
        );
    }

    public function releaseDataForVersion(array $dependency, array $catalog, string $targetVersion, string $fallbackReleaseAt): array
    {
        return [
            'version' => $targetVersion,
            'release_at' => (string) ($catalog['latest_release_at'] ?? $fallbackReleaseAt),
            'download_url' => (string) $catalog['download_url'],
            'source_reference' => 'https://vendor.example.com/account/downloads',
        ];
    }

    public function downloadReleaseToFile(array $dependency, array $releaseData, string $destination): void
    {
        $this->downloadBinary((string) $releaseData['download_url'], $destination);
    }
}
```

That is enough for a downstream repo to implement a provider for any premium source with a deterministic HTTP contract, including vendors that expose a latest-version endpoint plus an authenticated ZIP download.

## Validation

Use:

```bash
php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php doctor --repo-root=. --automation
```

`doctor` verifies:

- the registry file loads
- the provider key is registered
- the provider class can be loaded
- the provider class implements the required interface
- premium credentials are present enough for that provider's validation logic
