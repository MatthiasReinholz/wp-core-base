<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class HttpClient implements ArchiveDownloader
{
    private const RETRYABLE_STATUSES = [429, 500, 502, 503, 504];

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
        bool $followRedirects = false,
    ): array {
        if ($followRedirects && $this->hasAuthorizationHeader($headers)) {
            throw new RuntimeException('Authenticated HTTP requests may not follow redirects.');
        }

        return $this->requestOnce($method, $url, $headers, $json, $body, $followRedirects);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $json
     * @return array{status:int, body:string, headers:array<string, string>}
     */
    private function requestOnce(
        string $method,
        string $url,
        array $headers = [],
        ?array $json = null,
        ?string $body = null,
        bool $followRedirects = false,
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
            CURLOPT_FOLLOWLOCATION => $followRedirects,
            CURLOPT_MAXREDIRS => $followRedirects ? 5 : 0,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeoutSeconds),
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
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

        $this->applyProtocolRestrictions($curl, $followRedirects);

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
        $response = $this->requestWithRetry('GET', $url, $headers);

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
        $response = $this->requestWithRetry('GET', $url, $headers);

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
        if ($this->hasAuthorizationHeader($headers)) {
            throw new RuntimeException('Authenticated downloads are not supported.');
        }

        $temporaryDestination = $destination . '.part';
        $attempts = 3;
        $delayMicroseconds = 250000;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $this->downloadOnce($url, $temporaryDestination, $headers);

                if ($response['status'] >= 200 && $response['status'] < 300) {
                    if (! rename($temporaryDestination, $destination)) {
                        throw new RuntimeException(sprintf('Failed to move download into place at %s.', $destination));
                    }

                    return;
                }

                @unlink($temporaryDestination);

                if (! $this->shouldRetryStatus($response['status']) || $attempt === $attempts) {
                    throw new RuntimeException(sprintf('Download request failed for %s with status %d.', $url, $response['status']));
                }
            } catch (RuntimeException $exception) {
                @unlink($temporaryDestination);

                if ($attempt === $attempts) {
                    throw $exception;
                }
            }

            usleep($delayMicroseconds);
            $delayMicroseconds *= 2;
        }
    }

    /**
     * @param array<string, string> $headers
     * @return array{status:int, body:string, headers:array<string, string>}
     */
    private function requestWithRetry(string $method, string $url, array $headers = []): array
    {
        $attempts = 3;
        $delayMicroseconds = 250000;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $this->requestOnce($method, $url, $headers);

                if (! $this->shouldRetryStatus($response['status']) || $attempt === $attempts) {
                    return $response;
                }
            } catch (RuntimeException $exception) {
                if ($attempt === $attempts) {
                    throw $exception;
                }
            }

            usleep($delayMicroseconds);
            $delayMicroseconds *= 2;
        }

        throw new RuntimeException(sprintf('Exceeded retry budget for %s %s.', $method, $url));
    }

    /**
     * @param array<string, string> $headers
     * @return array{status:int, body:string, headers:array<string, string>}
     */
    private function downloadOnce(string $url, string $destination, array $headers = []): array
    {
        $fileHandle = fopen($destination, 'wb');

        if ($fileHandle === false) {
            throw new RuntimeException(sprintf('Failed to open download destination %s.', $destination));
        }

        $curl = curl_init($url);

        if ($curl === false) {
            fclose($fileHandle);
            throw new RuntimeException('Failed to initialize cURL.');
        }

        $headerLines = [];
        $responseHeaders = [];

        foreach ($headers as $name => $value) {
            $headerLines[] = sprintf('%s: %s', $name, $value);
        }

        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FILE => $fileHandle,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeoutSeconds),
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
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

        $this->applyProtocolRestrictions($curl, true);

        $result = curl_exec($curl);

        if ($result === false) {
            $error = curl_error($curl);
            curl_close($curl);
            fclose($fileHandle);
            throw new RuntimeException(sprintf('Download request failed for %s: %s', $url, $error));
        }

        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        fclose($fileHandle);

        return [
            'status' => $status,
            'body' => '',
            'headers' => $responseHeaders,
        ];
    }

    private function shouldRetryStatus(int $status): bool
    {
        return in_array($status, self::RETRYABLE_STATUSES, true);
    }

    /**
     * @param array<string, string> $headers
     */
    private function hasAuthorizationHeader(array $headers): bool
    {
        foreach ($headers as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0 && $value !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param resource $curl
     */
    private function applyProtocolRestrictions($curl, bool $followRedirects): void
    {
        if (defined('CURLOPT_PROTOCOLS_STR') && defined('CURLOPT_REDIR_PROTOCOLS_STR')) {
            curl_setopt($curl, CURLOPT_PROTOCOLS_STR, 'https');
            curl_setopt($curl, CURLOPT_REDIR_PROTOCOLS_STR, $followRedirects ? 'https' : '');
            return;
        }

        curl_setopt($curl, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        curl_setopt($curl, CURLOPT_REDIR_PROTOCOLS, $followRedirects ? CURLPROTO_HTTPS : 0);
    }
}
