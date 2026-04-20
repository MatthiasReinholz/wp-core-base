<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

abstract class AbstractPremiumManagedSource implements PremiumManagedDependencySource
{
    public function __construct(
        protected readonly HttpClient $httpClient,
        protected readonly PremiumCredentialsStore $credentialsStore,
    ) {
    }

    public function supportsForumSync(array $dependency): bool
    {
        return false;
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    protected function requestJson(string $method, string $url, array $headers = [], ?array $json = null, ?string $body = null): array
    {
        $options = $this->premiumMetadataRequestOptions();
        $allowedHosts = $this->allowedApiHosts();

        if ($allowedHosts !== []) {
            $options['allowed_redirect_hosts'] = $allowedHosts;
        }

        // Route JSON GET requests through the retry-aware JSON transport path.
        if (strtoupper($method) === 'GET' && $json === null && $body === null) {
            return $this->httpClient->getJsonWithOptions($url, $headers, $options);
        }

        $response = $this->httpClient->requestWithOptions($method, $url, $headers, $json, $body, false, $options);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException(sprintf(
                'Premium source request failed for %s with status %d.',
                $url,
                $response['status']
            ));
        }

        $decoded = json_decode($response['body'], true);

        if (! is_array($decoded)) {
            throw new RuntimeException(sprintf('Premium source %s returned invalid JSON.', $url));
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    protected function premiumMetadataRequestOptions(): array
    {
        $options = ['max_body_bytes' => 5 * 1024 * 1024];
        $timeoutSeconds = $this->premiumMetadataTimeoutSeconds();

        if ($timeoutSeconds !== null && $timeoutSeconds > 0) {
            $options['timeout_seconds'] = $timeoutSeconds;
        }

        $retryAttempts = $this->premiumMetadataRetryAttempts();

        if ($retryAttempts !== null && $retryAttempts > 0) {
            $options['retry_attempts'] = $retryAttempts;
        }

        $retryDelayMilliseconds = $this->premiumMetadataInitialRetryDelayMilliseconds();

        if ($retryDelayMilliseconds !== null && $retryDelayMilliseconds >= 0) {
            $options['retry_initial_delay_milliseconds'] = $retryDelayMilliseconds;
        }

        return $options;
    }

    protected function premiumMetadataTimeoutSeconds(): ?int
    {
        return null;
    }

    protected function premiumMetadataRetryAttempts(): ?int
    {
        return null;
    }

    protected function premiumMetadataInitialRetryDelayMilliseconds(): ?int
    {
        return null;
    }

    /**
     * @param array<string, string> $headers
     */
    protected function downloadBinary(string $url, string $destination, array $headers = []): void
    {
        $options = [
            'max_download_bytes' => 512 * 1024 * 1024,
            'strip_auth_on_cross_origin_redirect' => true,
        ];
        $allowedHosts = $this->allowedDownloadHosts();

        if ($allowedHosts !== []) {
            $options['allowed_redirect_hosts'] = $allowedHosts;
        }

        $this->httpClient->downloadToFileWithOptions($url, $destination, $headers, $options);
    }

    /**
     * @param array<string, mixed> $dependency
     * @return array<string, mixed>
     */
    protected function credentialsFor(array $dependency, array $requiredFields = []): array
    {
        $credentials = $this->credentialsStore->credentialsFor($dependency);

        foreach ($requiredFields as $field) {
            $value = $credentials[$field] ?? null;

            if (! is_string($value) || trim($value) === '') {
                throw new RuntimeException(sprintf(
                    'Premium credentials for %s are missing required field `%s`.',
                    $this->credentialsStore->lookupKeyFor($dependency),
                    $field
                ));
            }
        }

        return $credentials;
    }

    public function validateCredentialConfiguration(array $dependency): void
    {
        $this->credentialsFor($dependency, $this->requiredCredentialFields());
    }

    /**
     * @return list<string>
     */
    protected function requiredCredentialFields(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    protected function allowedApiHosts(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    protected function allowedDownloadHosts(): array
    {
        return $this->allowedApiHosts();
    }

    /**
     * @return list<string>
     */
    public function hostPolicyWarnings(): array
    {
        $warnings = [];
        $apiHosts = array_values(array_filter(array_map('trim', $this->allowedApiHosts()), static fn (string $host): bool => $host !== ''));
        $downloadHosts = array_values(array_filter(array_map('trim', $this->allowedDownloadHosts()), static fn (string $host): bool => $host !== ''));

        if ($apiHosts === []) {
            $warnings[] = sprintf(
                'Premium provider `%s` does not declare allowed API hosts. Add an explicit allowlist before stricter provider-host enforcement is enabled.',
                $this->key()
            );
        }

        if ($downloadHosts === []) {
            $warnings[] = sprintf(
                'Premium provider `%s` does not declare allowed download hosts. Add an explicit allowlist before stricter provider-host enforcement is enabled.',
                $this->key()
            );
        }

        return $warnings;
    }

    /**
     * @param array<string, mixed> $dependency
     */
    protected function updateContractDescription(array $dependency): string
    {
        $source = (string) ($dependency['source'] ?? '');
        $provider = PremiumSourceResolver::providerForDependency($dependency);

        if ($source === 'premium' && $provider !== null) {
            return sprintf('`premium` provider `%s`', $provider);
        }

        if ($source !== '') {
            return sprintf('`%s` premium source', $source);
        }

        return '`premium` source';
    }
}
