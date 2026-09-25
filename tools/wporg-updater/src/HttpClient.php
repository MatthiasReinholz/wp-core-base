<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use JsonException;
use RuntimeException;

final class HttpClient implements ArchiveDownloader, JsonHttpTransport
{
    public const DEFAULT_MAX_JSON_BODY_BYTES = 5 * 1024 * 1024;
    public const DEFAULT_MAX_REQUEST_BODY_BYTES = 2 * 1024 * 1024;

    private const RETRYABLE_STATUSES = [429, 500, 502, 503, 504];
    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];
    private const MAX_RETRY_DELAY_SECONDS = 900;

    public function __construct(
        private readonly string $userAgent = 'wp-core-base/1.0',
        private readonly int $timeoutSeconds = 30,
        private readonly ?string $caFile = null,
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
        if ($followRedirects && ! in_array(strtoupper($method), ['GET', 'HEAD'], true)) {
            throw new HttpPolicyViolation('Redirect following is supported only for GET and HEAD requests.');
        }
        if ($followRedirects && ($json !== null || $body !== null)) {
            throw new HttpPolicyViolation('Redirect following is not supported for requests with a request body.');
        }
        $headers = HttpRequestPolicy::initialHeaders($headers, $url, $options);

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
        $headers = HttpRequestPolicy::initialHeaders($headers, $url, $options);
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

            $delayMicroseconds = $this->boundedRetryDelayMicroseconds($delayMicroseconds);
            usleep($delayMicroseconds);
            $delayMicroseconds = $this->boundedRetryDelayMicroseconds($delayMicroseconds * 2);
        }
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $options
     * @return array{status:int, body:string, headers:array<string, string>}
     */
    private function requestWithRetry(string $method, string $url, array $headers = [], array $options = []): array
    {
        $this->assertAllowedUrl($url, $options);

        $attempts = max(1, (int) ($options['retry_attempts'] ?? 3));
        $delayMicroseconds = $this->initialRetryDelayMicroseconds((int) ($options['retry_initial_delay_milliseconds'] ?? 250));

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $this->requestWithOptions($method, $url, $headers, null, null, (bool) ($options['follow_redirects'] ?? false), $options);

                if (! $this->shouldRetryStatus($response['status']) || $attempt === $attempts) {
                    return $response;
                }

                $delayMicroseconds = $this->nextRetryDelayMicroseconds($delayMicroseconds, $response);
            } catch (RuntimeException $exception) {
                if ($attempt === $attempts || ! $this->shouldRetryException($exception)) {
                    throw $exception;
                }
            }

            $delayMicroseconds = $this->boundedRetryDelayMicroseconds($delayMicroseconds);
            usleep($delayMicroseconds);
            $delayMicroseconds = $this->boundedRetryDelayMicroseconds($delayMicroseconds * 2);
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
        array $visitedUrls = [],
    ): array {
        $this->assertAllowedUrl($url, $options);
        $normalizedUrl = explode('#', $url, 2)[0];
        if (isset($visitedUrls[$normalizedUrl])) {
            throw new HttpPolicyViolation('HTTP redirect loop detected.');
        }
        $visitedUrls[$normalizedUrl] = true;
        $redirectLimit = max(0, (int) ($options['max_redirects'] ?? 5));
        $timeoutSeconds = max(1, (int) ($options['timeout_seconds'] ?? $this->timeoutSeconds));
        $connectTimeoutSeconds = max(1, (int) ($options['connect_timeout_seconds'] ?? min(10, $timeoutSeconds)));

        if ($redirectDepth > $redirectLimit) {
            throw new HttpPolicyViolation(sprintf('HTTP redirect limit exceeded for %s.', $url));
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
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $connectTimeoutSeconds,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_HEADERFUNCTION => static function ($curlHandle, string $headerLine) use (&$responseHeaders): int {
                $length = strlen($headerLine);
                if (preg_match('#^HTTP/\S+\s+\d{3}#i', $headerLine)) {
                    $responseHeaders = [];
                    return $length;
                }
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

        if ($this->caFile !== null) {
            curl_setopt($curl, CURLOPT_CAINFO, $this->caFile);
        }
        $this->applyProtocolRestrictions($curl, false);
        $result = curl_exec($curl);

        if ($result === false) {
            $error = curl_error($curl);
            $this->closeCurl($curl);

            if ($bodyLimitExceeded) {
                throw new RuntimeException(sprintf('HTTP response body exceeded the configured byte limit for %s.', $url));
            }

            throw new RuntimeException(sprintf('HTTP request failed for %s: %s', $url, $error));
        }

        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $this->closeCurl($curl);

        if ($followRedirects && in_array($status, self::REDIRECT_STATUSES, true)) {
            $location = $responseHeaders['location'] ?? null;

            if (! is_string($location) || $location === '') {
                throw new HttpPolicyViolation(sprintf('Redirect response for %s did not include a Location header.', $url));
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
                $redirectDepth + 1,
                $visitedUrls
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
        array $visitedUrls = [],
    ): array {
        $this->assertAllowedUrl($url, $options);
        $normalizedUrl = explode('#', $url, 2)[0];
        if (isset($visitedUrls[$normalizedUrl])) {
            throw new HttpPolicyViolation('HTTP redirect loop detected.');
        }
        $visitedUrls[$normalizedUrl] = true;
        $redirectLimit = max(0, (int) ($options['max_redirects'] ?? 5));
        $timeoutSeconds = max(1, (int) ($options['timeout_seconds'] ?? $this->timeoutSeconds));
        $connectTimeoutSeconds = max(1, (int) ($options['connect_timeout_seconds'] ?? min(10, $timeoutSeconds)));

        if ($redirectDepth > $redirectLimit) {
            throw new HttpPolicyViolation(sprintf('Download redirect limit exceeded for %s.', $url));
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
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $connectTimeoutSeconds,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_HEADERFUNCTION => static function ($curlHandle, string $headerLine) use (&$responseHeaders): int {
                $length = strlen($headerLine);
                if (preg_match('#^HTTP/\S+\s+\d{3}#i', $headerLine)) {
                    $responseHeaders = [];
                    return $length;
                }
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

        if ($this->caFile !== null) {
            curl_setopt($curl, CURLOPT_CAINFO, $this->caFile);
        }
        $this->applyProtocolRestrictions($curl, false);
        $result = curl_exec($curl);

        if ($result === false) {
            $error = curl_error($curl);
            $this->closeCurl($curl);
            fclose($fileHandle);

            if ($downloadLimitExceeded) {
                throw new RuntimeException(sprintf('Download exceeded the configured byte limit for %s.', $url));
            }

            throw new RuntimeException(sprintf('Download request failed for %s: %s', $url, $error));
        }

        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $this->closeCurl($curl);
        fclose($fileHandle);

        if (in_array($status, self::REDIRECT_STATUSES, true)) {
            $location = $responseHeaders['location'] ?? null;

            if (! is_string($location) || $location === '') {
                throw new HttpPolicyViolation(sprintf('Download redirect for %s did not include a Location header.', $url));
            }

            $this->bestEffortRemoveFile($destination);
            $redirectUrl = $this->resolveRedirectUrl($url, $location);
            $redirectHeaders = $this->headersForRedirect($headers, $url, $redirectUrl, $options);

            return $this->downloadOnce($redirectUrl, $destination, $redirectHeaders, $options, $redirectDepth + 1, $visitedUrls);
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
        if ($exception instanceof HttpPolicyViolation) {
            return false;
        }
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
            // Bound before conversion so even a saturated Retry-After integer
            // cannot overflow into a floating-point delay.
            return min(self::MAX_RETRY_DELAY_SECONDS, $headerDelay) * 1_000_000;
        }

        return $this->boundedRetryDelayMicroseconds($fallbackMicroseconds);
    }

    private function initialRetryDelayMicroseconds(int $milliseconds): int
    {
        return min(self::MAX_RETRY_DELAY_SECONDS * 1000, max(0, $milliseconds)) * 1000;
    }

    private function boundedRetryDelayMicroseconds(int $microseconds): int
    {
        return min(self::MAX_RETRY_DELAY_SECONDS * 1_000_000, max(0, $microseconds));
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

    private function applyProtocolRestrictions(\CurlHandle $curl, bool $followRedirects): void
    {
        if (defined('CURLOPT_PROTOCOLS_STR') && defined('CURLOPT_REDIR_PROTOCOLS_STR')) {
            curl_setopt($curl, CURLOPT_PROTOCOLS_STR, 'https');
            curl_setopt($curl, CURLOPT_REDIR_PROTOCOLS_STR, $followRedirects ? 'https' : '');
            return;
        }

        curl_setopt($curl, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        curl_setopt($curl, CURLOPT_REDIR_PROTOCOLS, $followRedirects ? CURLPROTO_HTTPS : 0);
    }

    private function closeCurl(\CurlHandle $curl): void
    {
        if (PHP_VERSION_ID < 80500) {
            curl_close($curl);
        }
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    private function headersForRedirect(array $headers, string $fromUrl, string $toUrl, array $options): array
    {
        return HttpRequestPolicy::redirectHeaders($headers, $fromUrl, $toUrl, $options);
    }

    private function resolveRedirectUrl(string $currentUrl, string $location): string
    {
        return HttpRequestPolicy::resolveRedirect($currentUrl, $location);
    }

    /** @param array<string,mixed> $options */
    private function assertAllowedUrl(string $url, array $options): void
    {
        HttpRequestPolicy::assertAllowedUrl($url, $options);
    }
}
