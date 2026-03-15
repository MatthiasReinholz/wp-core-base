<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class HttpClient
{
    public function __construct(
        private readonly string $userAgent = 'wp-core-base/1.0',
        private readonly int $timeoutSeconds = 30,
    ) {
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $json
     * @return array{status:int, body:string, headers:array<string, string>}
     */
    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?array $json = null,
        ?string $body = null,
    ): array {
        $curl = curl_init($url);

        if ($curl === false) {
            throw new RuntimeException('Failed to initialize cURL.');
        }

        $headerLines = [];

        foreach ($headers as $name => $value) {
            $headerLines[] = sprintf('%s: %s', $name, $value);
        }

        if ($json !== null) {
            $body = json_encode($json, JSON_THROW_ON_ERROR);
            $headerLines[] = 'Content-Type: application/json';
        }

        $responseHeaders = [];

        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_HEADERFUNCTION => static function ($curlHandle, string $headerLine) use (&$responseHeaders): int {
                $length = strlen($headerLine);
                $parts = explode(':', $headerLine, 2);

                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return $length;
            },
        ]);

        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($curl);

        if (! is_string($responseBody)) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new RuntimeException(sprintf('HTTP request failed for %s: %s', $url, $error));
        }

        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        return [
            'status' => $status,
            'body' => $responseBody,
            'headers' => $responseHeaders,
        ];
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    public function getJson(string $url, array $headers = []): array
    {
        $response = $this->request('GET', $url, $headers);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException(sprintf('JSON request failed for %s with status %d.', $url, $response['status']));
        }

        $decoded = json_decode($response['body'], true);

        if (! is_array($decoded)) {
            throw new RuntimeException(sprintf('Failed to decode JSON from %s.', $url));
        }

        return $decoded;
    }

    /**
     * @param array<string, string> $headers
     */
    public function get(string $url, array $headers = []): string
    {
        $response = $this->request('GET', $url, $headers);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException(sprintf('Request failed for %s with status %d.', $url, $response['status']));
        }

        return $response['body'];
    }

    /**
     * @param array<string, string> $headers
     */
    public function downloadToFile(string $url, string $destination, array $headers = []): void
    {
        $body = $this->get($url, $headers);

        if (file_put_contents($destination, $body) === false) {
            throw new RuntimeException(sprintf('Failed to write download to %s.', $destination));
        }
    }
}
