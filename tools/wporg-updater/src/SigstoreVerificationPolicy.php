<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

/** A committed, exact signer policy. Presence requires verification; there is no fallback. */
final class SigstoreVerificationPolicy
{
    /** @return array{bundle_asset_pattern:string,certificate_identity:string,oidc_issuer:string}|null */
    public static function normalize(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (! is_array($value) || array_is_list($value)) {
            throw new RuntimeException('source_config.sigstore must be an object containing an exact signer policy.');
        }
        $keys = ['bundle_asset_pattern', 'certificate_identity', 'oidc_issuer'];
        if (array_diff(array_keys($value), $keys) !== []) {
            throw new RuntimeException('source_config.sigstore contains an unknown policy key.');
        }
        $policy = [];
        foreach ($keys as $key) {
            $text = $value[$key] ?? null;
            if (! is_string($text) || trim($text) === '' || strlen($text) > 2048 || preg_match('/[\x00-\x1f\x7f]/', $text)) {
                throw new RuntimeException(sprintf('source_config.sigstore.%s must be a nonempty, bounded string without control characters.', $key));
            }
            $policy[$key] = trim($text);
        }
        $issuer = parse_url($policy['oidc_issuer']);
        if (! is_array($issuer) || ($issuer['scheme'] ?? '') !== 'https' || empty($issuer['host'])
            || isset($issuer['user']) || isset($issuer['pass']) || isset($issuer['query']) || isset($issuer['fragment'])) {
            throw new RuntimeException('source_config.sigstore.oidc_issuer must be an HTTPS issuer URL without credentials, query or fragment.');
        }
        return $policy;
    }

    /** @param array<string,mixed> $dependency
     * @return array{bundle_asset_pattern:string,certificate_identity:string,oidc_issuer:string}|null
     */
    public static function forDependency(array $dependency): ?array
    {
        $policy = self::normalize($dependency['source_config']['sigstore'] ?? null);
        if ($policy !== null && ! in_array($dependency['source'] ?? '', ['github-release', 'gitlab-release'], true)) {
            throw new RuntimeException('Sigstore verification is supported only for hosted GitHub and GitLab release assets.');
        }
        return $policy;
    }
}
