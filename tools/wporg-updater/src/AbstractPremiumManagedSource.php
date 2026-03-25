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
        $response = $this->httpClient->request($method, $url, $headers, $json, $body);

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
     * @param array<string, string> $headers
     */
    protected function downloadBinary(string $url, string $destination, array $headers = []): void
    {
        if ($headers === []) {
            $this->httpClient->downloadToFile($url, $destination);
            return;
        }

        $response = $this->httpClient->request('GET', $url, $headers);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException(sprintf('Premium archive download failed for %s with status %d.', $url, $response['status']));
        }

        if (file_put_contents($destination, $response['body']) === false) {
            throw new RuntimeException(sprintf('Unable to write premium archive to %s.', $destination));
        }
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
