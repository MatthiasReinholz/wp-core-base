<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use JsonException;
use RuntimeException;

final class HttpClient implements ArchiveDownloader
{
    public const DEFAULT_MAX_JSON_BODY_BYTES = 5 * 1024 * 1024;
    public const DEFAULT_MAX_REQUEST_BODY_BYTES = 2 * 1024 * 1024;

    private const RETRYABLE_STATUSES = [429, 500, 502, 503, 504];
    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];
    private const MAX_RETRY_DELAY_SECONDS = 900;

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
        return $this->requestWithOptions($method, $url, $headers, $json, $body, $followRedirects);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $json
     * @param array<string, mixed> $options
     * @return array{status:int, body:string, headers:array<string, string>}
     */
    public function requestWithOptions(
        string $method,
        string $url,
        array $headers = [],
        ?array $json = null,
        ?string $body = null,
        bool $followRedirects = false,
        array $options = [],
    ): array {
        if ($followRedirects && $this->hasAuthorizationHeader($headers) && ! ($options['strip_auth_on_cross_origin_redirect'] ?? false)) {
            throw new RuntimeException('Authenticated HTTP requests may not follow redirects unless cross-origin auth stripping is enabled.');
        }

        $this->assertAllowedUrl($url, $options);

        return $this->requestOnce($method, $url, $headers, $json, $body, $followRedirects, $options);
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    public function getJson(string $url, array $headers = []): array
    {
        return $this->getJsonWithOptions($url, $headers);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function getJsonWithOptions(string $url, array $headers = [], array $options = []): array
    {
        $options['max_body_bytes'] ??= self::DEFAULT_MAX_JSON_BODY_BYTES;
        $response = $this->requestWithRetry('GET', $url, $headers, $options);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException(sprintf('JSON request failed for %s with status %d.', $url, $response['status']));
        }

        return self::decodeJsonObject($response['body'], sprintf('Failed to decode JSON from %s.', $url));
    }

    /**
     * @param array<string, string> $headers
     */
    public function get(string $url, array $headers = []): string
    {
        return $this->getWithOptions($url, $headers);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $options
     */
    public function getWithOptions(string $url, array $headers = [], array $options = []): string
    {
        $options['max_body_bytes'] ??= self::DEFAULT_MAX_JSON_BODY_BYTES;
        $response = $this->requestWithRetry('GET', $url, $headers, $options);

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
        $this->downloadToFileWithOptions($url, $destination, $headers);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $options
     */
    public function downloadToFileWithOptions(string $url, string $destination, array $headers = [], array $options = []): void
    {
        $this->assertAllowedUrl($url, $options);
        $temporaryDestination = $destination . '.part';
        $attempts = 3;
        $delayMicroseconds = 250000;
        $options['max_download_bytes'] ??= 512 * 1024 * 1024;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $this->downloadOnce($url, $temporaryDestination, $headers, $options);

                if ($response['status'] >= 200 && $response['status'] < 300) {
                    if (! rename($temporaryDestination, $destination)) {
                        throw new RuntimeException(sprintf('Failed to move download into place at %s.', $destination));
                    }

                    return;
                }

                $this->bestEffortRemoveFile($temporaryDestination);

                if (! $this->shouldRetryStatus($response['status']) || $attempt === $attempts) {
                    throw new RuntimeException(sprintf('Download request failed for %s with status %d.', $url, $response['status']));
                }

                $delayMicroseconds = $this->nextRetryDelayMicroseconds($delayMicroseconds, $response);
            } catch (RuntimeException $exception) {
                $this->bestEffortRemoveFile($temporaryDestination);

                if ($attempt === $attempts || ! $this->shouldRetryException($exception)) {
                    throw $exception;
                }
            }

            usleep($delayMicroseconds);
            $delayMicroseconds *= 2;
        }
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $options
     * @return array{status:int, body:string, headers:array<string, string>}
     */
    private function requestWithRetry(string $method, string $url, array $headers = [], array $options = []): array
    {
        $attempts = 3;
        $delayMicroseconds = 250000;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $this->requestOnce($method, $url, $headers, null, null, false, $options);

                if (! $this->shouldRetryStatus($response['status']) || $attempt === $attempts) {
                    return $response;
                }

                $delayMicroseconds = $this->nextRetryDelayMicroseconds($delayMicroseconds, $response);
            } catch (RuntimeException $exception) {
                if ($attempt === $attempts || ! $this->shouldRetryException($exception)) {
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
     * @param array<string, mixed>|null $json
     * @param array<string, mixed> $options
     * @return array{status:int, body:string, headers:array<string, string>}
     */
    private function requestOnce(
        string $method,
        string $url,
        array $headers = [],
        ?array $json = null,
        ?string $body = null,
        bool $followRedirects = false,
        array $options = [],
        int $redirectDepth = 0,
    ): array {
        $redirectLimit = (int) ($options['max_redirects'] ?? 5);

        if ($redirectDepth > $redirectLimit) {
            throw new RuntimeException(sprintf('HTTP redirect limit exceeded for %s.', $url));
        }

        $curl = curl_init($url);

        if ($curl === false) {
            throw new RuntimeException('Failed to initialize cURL.');
        }

        $headerLines = [];
        $responseHeaders = [];
        $responseBody = '';
        $maxBodyBytes = isset($options['max_body_bytes']) ? (int) $options['max_body_bytes'] : null;
        $bodyLimitExceeded = false;

        foreach ($headers as $name => $value) {
            $headerLines[] = sprintf('%s: %s', $name, $value);
        }

        if ($json !== null) {
            $body = json_encode($json, JSON_THROW_ON_ERROR);
            $headerLines[] = 'Content-Type: application/json';
        }

        if ($body !== null) {
            $maxRequestBytes = isset($options['max_request_bytes']) ? (int) $options['max_request_bytes'] : self::DEFAULT_MAX_REQUEST_BODY_BYTES;

            if ($maxRequestBytes > 0 && strlen($body) > $maxRequestBytes) {
                throw new RuntimeException(sprintf('HTTP request body exceeded the configured byte limit for %s.', $url));
            }
        }

        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
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
            CURLOPT_WRITEFUNCTION => static function ($curlHandle, string $chunk) use (&$responseBody, $maxBodyBytes, &$bodyLimitExceeded): int {
                $responseBody .= $chunk;

                if ($maxBodyBytes !== null && strlen($responseBody) > $maxBodyBytes) {
                    $bodyLimitExceeded = true;
                    return 0;
                }

                return strlen($chunk);
            },
        ]);

        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $this->applyProtocolRestrictions($curl, false);
        $result = curl_exec($curl);

        if ($result === false) {
            $error = curl_error($curl);
            curl_close($curl);

            if ($bodyLimitExceeded) {
                throw new RuntimeException(sprintf('HTTP response body exceeded the configured byte limit for %s.', $url));
            }

            throw new RuntimeException(sprintf('HTTP request failed for %s: %s', $url, $error));
        }

        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($followRedirects && in_array($status, self::REDIRECT_STATUSES, true)) {
            $location = $responseHeaders['location'] ?? null;

            if (! is_string($location) || $location === '') {
                throw new RuntimeException(sprintf('Redirect response for %s did not include a Location header.', $url));
            }

            $redirectUrl = $this->resolveRedirectUrl($url, $location);
            $redirectHeaders = $this->headersForRedirect($headers, $url, $redirectUrl, $options);

            return $this->requestOnce(
                $method,
                $redirectUrl,
                $redirectHeaders,
                $json,
                $body,
                true,
                $options,
                $redirectDepth + 1
            );
        }

        return [
            'status' => $status,
            'body' => $responseBody,
            'headers' => $responseHeaders,
        ];
    }

    /**
     * @return array<string, mixed>|list<mixed>
     */
    public static function decodeJsonObject(string $jsonBody, string $errorPrefix, int $depth = 32): array
    {
        try {
            $decoded = json_decode($jsonBody, true, $depth, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException($errorPrefix . ' ' . $exception->getMessage(), previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException($errorPrefix . ' JSON payload must decode to an object or array.');
        }

        return $decoded;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $options
     * @return array{status:int, body:string, headers:array<string, string>}
     */
    private function downloadOnce(
        string $url,
        string $destination,
        array $headers = [],
        array $options = [],
        int $redirectDepth = 0,
    ): array {
        $redirectLimit = (int) ($options['max_redirects'] ?? 5);

        if ($redirectDepth > $redirectLimit) {
            throw new RuntimeException(sprintf('Download redirect limit exceeded for %s.', $url));
        }

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
        $downloadedBytes = 0;
        $downloadLimitExceeded = false;
        $maxDownloadBytes = isset($options['max_download_bytes']) ? (int) $options['max_download_bytes'] : null;

        foreach ($headers as $name => $value) {
            $headerLines[] = sprintf('%s: %s', $name, $value);
        }

        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
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
            CURLOPT_WRITEFUNCTION => static function ($curlHandle, string $chunk) use ($fileHandle, $maxDownloadBytes, &$downloadedBytes, &$downloadLimitExceeded): int {
                $downloadedBytes += strlen($chunk);

                if ($maxDownloadBytes !== null && $downloadedBytes > $maxDownloadBytes) {
                    $downloadLimitExceeded = true;
                    return 0;
                }

                return fwrite($fileHandle, $chunk);
            },
        ]);

        $this->applyProtocolRestrictions($curl, false);
        $result = curl_exec($curl);

        if ($result === false) {
            $error = curl_error($curl);
            curl_close($curl);
            fclose($fileHandle);

            if ($downloadLimitExceeded) {
                throw new RuntimeException(sprintf('Download exceeded the configured byte limit for %s.', $url));
            }

            throw new RuntimeException(sprintf('Download request failed for %s: %s', $url, $error));
        }

        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        fclose($fileHandle);

        if (in_array($status, self::REDIRECT_STATUSES, true)) {
            $location = $responseHeaders['location'] ?? null;

            if (! is_string($location) || $location === '') {
                throw new RuntimeException(sprintf('Download redirect for %s did not include a Location header.', $url));
            }

            $this->bestEffortRemoveFile($destination);
            $redirectUrl = $this->resolveRedirectUrl($url, $location);
            $redirectHeaders = $this->headersForRedirect($headers, $url, $redirectUrl, $options);

            return $this->downloadOnce($redirectUrl, $destination, $redirectHeaders, $options, $redirectDepth + 1);
        }

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

    private function shouldRetryException(RuntimeException $exception): bool
    {
        $message = $exception->getMessage();
        $nonRetryableFragments = [
            'configured byte limit',
            'Request target host',
            'Redirect target host',
            'redirect limit exceeded',
            'did not include a Location header',
            'may not follow redirects unless cross-origin auth stripping is enabled',
        ];

        foreach ($nonRetryableFragments as $fragment) {
            if (str_contains($message, $fragment)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{status:int, body:string, headers:array<string, string>} $response
     */
    private function nextRetryDelayMicroseconds(int $fallbackMicroseconds, array $response): int
    {
        $headerDelay = $this->retryDelayFromHeaders($response['headers']);

        if ($headerDelay !== null && $headerDelay > 0) {
            return min(self::MAX_RETRY_DELAY_SECONDS * 1_000_000, $headerDelay * 1_000_000);
        }

        return $fallbackMicroseconds;
    }

    /**
     * @param array<string, string> $headers
     */
    private function retryDelayFromHeaders(array $headers): ?int
    {
        $retryAfter = $headers['retry-after'] ?? null;

        if (is_string($retryAfter) && trim($retryAfter) !== '') {
            $parsed = $this->parseRetryAfterSeconds($retryAfter);

            if ($parsed !== null && $parsed > 0) {
                return $parsed;
            }
        }

        $remaining = $headers['x-ratelimit-remaining'] ?? null;
        $reset = $headers['x-ratelimit-reset'] ?? null;

        if (is_string($remaining) && trim($remaining) === '0' && is_string($reset) && ctype_digit(trim($reset))) {
            $seconds = ((int) trim($reset)) - time();
            return max(1, $seconds);
        }

        return null;
    }

    private function parseRetryAfterSeconds(string $retryAfter): ?int
    {
        $value = trim($retryAfter);

        if (ctype_digit($value)) {
            return (int) $value;
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return null;
        }

        return max(1, $timestamp - time());
    }

    private function bestEffortRemoveFile(string $path): void
    {
        if (! is_file($path)) {
            return;
        }

        if (! unlink($path)) {
            fwrite(STDERR, sprintf("[warn] Failed to remove temporary file %s\n", $path));
        }
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

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    private function headersForRedirect(array $headers, string $fromUrl, string $toUrl, array $options): array
    {
        $redirectHosts = $options['allowed_redirect_hosts'] ?? null;

        if (is_array($redirectHosts) && $redirectHosts !== []) {
            $targetHost = strtolower((string) parse_url($toUrl, PHP_URL_HOST));

            if ($targetHost === '' || ! in_array($targetHost, array_map('strtolower', $redirectHosts), true)) {
                throw new RuntimeException(sprintf('Redirect target host %s is not allowed for %s.', $targetHost === '' ? '(unknown)' : $targetHost, $fromUrl));
            }
        }

        $fromOrigin = $this->originForUrl($fromUrl);
        $toOrigin = $this->originForUrl($toUrl);

        if ($fromOrigin !== '' && $toOrigin !== '' && $fromOrigin !== $toOrigin) {
            foreach (array_keys($headers) as $headerName) {
                if (strcasecmp($headerName, 'Authorization') === 0) {
                    unset($headers[$headerName]);
                }
            }
        }

        return $headers;
    }

    private function resolveRedirectUrl(string $currentUrl, string $location): string
    {
        if (preg_match('#^https://#i', $location) === 1) {
            return $location;
        }

        $current = parse_url($currentUrl);

        if (! is_array($current) || ! isset($current['scheme'], $current['host'])) {
            throw new RuntimeException(sprintf('Unable to resolve redirect URL from %s.', $currentUrl));
        }

        $base = $current['scheme'] . '://' . $current['host'];

        if (isset($current['port'])) {
            $base .= ':' . $current['port'];
        }

        if (str_starts_with($location, '/')) {
            return $base . $location;
        }

        $path = (string) ($current['path'] ?? '/');
        $directory = rtrim(str_replace('\\', '/', dirname($path)), '/');

        return $base . ($directory === '' ? '' : $directory) . '/' . $location;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function assertAllowedUrl(string $url, array $options): void
    {
        $allowedHosts = $options['allowed_redirect_hosts'] ?? null;

        if (! is_array($allowedHosts) || $allowedHosts === []) {
            return;
        }

        $targetHost = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($targetHost === '' || ! in_array($targetHost, array_map('strtolower', $allowedHosts), true)) {
            throw new RuntimeException(sprintf(
                'Request target host %s is not allowed for %s.',
                $targetHost === '' ? '(unknown)' : $targetHost,
                $url
            ));
        }
    }

    private function originForUrl(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme === '' || $host === '') {
            return '';
        }

        $port = isset($parts['port'])
            ? (int) $parts['port']
            : ($scheme === 'https' ? 443 : ($scheme === 'http' ? 80 : 0));

        return sprintf('%s://%s:%d', $scheme, $host, $port);
    }
}
