<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

/** URL and credential policy shared by in-memory requests and file downloads. */
final class HttpRequestPolicy
{
    private const PUBLIC_HEADERS = ['accept', 'accept-encoding', 'accept-language', 'user-agent'];

    /** @param array<string,mixed> $options */
    public static function assertAllowedUrl(string $url, array $options = [], string $role = 'Request'): void
    {
        $parts = self::validatedParts($url);
        $hosts = $options['allowed_redirect_hosts'] ?? null;
        if (is_array($hosts) && $hosts !== []) {
            $normalized = array_map(static fn (mixed $host): string => strtolower((string) $host), $hosts);
            if (! in_array(strtolower($parts['host']), $normalized, true)) {
                throw new HttpPolicyViolation(sprintf('%s target host %s is not allowed.', $role, $parts['host']));
            }
        }
    }

    public static function origin(string $url): string
    {
        $parts = self::validatedParts($url);
        return sprintf('https://%s:%d', strtolower($parts['host']), $parts['port'] ?? 443);
    }

    /**
     * @param array<string,string> $headers
     * @return array<string,string>
     */
    public static function publicHeaders(array $headers): array
    {
        return array_filter($headers, static fn (string $name): bool => in_array(strtolower($name), self::PUBLIC_HEADERS, true), ARRAY_FILTER_USE_KEY);
    }

    /**
     * @param array<string,string> $headers
     * @param array<string,mixed> $options
     * @return array<string,string>
     */
    public static function initialHeaders(array $headers, string $url, array $options): array
    {
        self::assertAllowedUrl($url, $options);
        foreach ($headers as $name => $value) {
            if (! preg_match('/^[!#$%&\x27*+.^_`|~0-9A-Za-z-]+$/', $name) || preg_match('/[\r\n\x00]/', $value)) {
                throw new HttpPolicyViolation('Invalid HTTP header name or value.');
            }
        }
        $origins = $options['credential_origins'] ?? (isset($options['credential_origin']) ? [$options['credential_origin']] : null);
        if (is_array($origins)) {
            $allowed = array_map(static fn (mixed $origin): string => self::origin((string) $origin), $origins);
            if (! in_array(self::origin($url), $allowed, true)) {
                return self::publicHeaders($headers);
            }
        }
        return $headers;
    }

    /**
     * @param array<string,string> $headers
     * @param array<string,mixed> $options
     * @return array<string,string>
     */
    public static function redirectHeaders(array $headers, string $from, string $to, array $options): array
    {
        self::assertAllowedUrl($to, $options, 'Redirect');
        return self::origin($from) === self::origin($to) ? $headers : self::publicHeaders($headers);
    }

    public static function resolveRedirect(string $current, string $location): string
    {
        self::assertAllowedUrl($current);
        if ($location === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $location)) {
            throw new HttpPolicyViolation('Redirect Location is empty or contains invalid characters.');
        }
        if (preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $location)) {
            $resolved = $location;
        } elseif (str_starts_with($location, '//')) {
            $resolved = 'https:' . $location;
        } else {
            $base = self::validatedParts($current);
            $relative = parse_url($location);
            if (! is_array($relative)) {
                throw new HttpPolicyViolation('Redirect Location is malformed.');
            }
            $authority = 'https://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
            $path = (string) ($relative['path'] ?? '');
            if ($path === '') {
                $path = (string) ($base['path'] ?? '/');
                $query = $relative['query'] ?? $base['query'] ?? null;
            } else {
                if (! str_starts_with($path, '/')) {
                    $basePath = (string) ($base['path'] ?? '/');
                    $path = substr($basePath, 0, (int) strrpos($basePath, '/') + 1) . $path;
                }
                $query = $relative['query'] ?? null;
            }
            $resolved = $authority . self::removeDotSegments($path) . ($query !== null ? '?' . $query : '');
        }
        $resolved = explode('#', $resolved, 2)[0];
        self::assertAllowedUrl($resolved);
        return $resolved;
    }

    /** @return array{scheme?:string,host:string,port?:int,path?:string,query?:string,fragment?:string} */
    private static function validatedParts(string $url): array
    {
        $parts = parse_url($url);
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url) || ! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! isset($parts['host']) || $parts['host'] === ''
            || isset($parts['user']) || isset($parts['pass'])
            || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new HttpPolicyViolation('HTTP URL must be a valid HTTPS URL without user information or control characters.');
        }
        return $parts;
    }

    private static function removeDotSegments(string $path): string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '.') {
                $segments[] = $segment;
            }
        }
        $normalized = implode('/', $segments);
        if (preg_match('#/(?:\.|\.\.)$#', $path)) {
            $normalized .= '/';
        }
        return '/' . ltrim($normalized, '/');
    }
}
