<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class OutputRedactor
{
    public static function redact(string $message): string
    {
        $redacted = $message;

        try {
            $credentials = (new PremiumCredentialsStore())->all();

            foreach ($credentials as $entry) {
                $redacted = PremiumCredentialsStore::redact($redacted, $entry);
            }
        } catch (RuntimeException) {
            $redacted = self::redactUrls($redacted);
        }

        $redacted = self::redactBearerTokens($redacted);
        $redacted = self::redactAuthorizationHeaders($redacted);
        $redacted = self::redactKnownTokenFormats($redacted);
        $redacted = self::redactKnownSecretEnvValues($redacted);
        $redacted = self::redactUrls($redacted);

        return $redacted;
    }

    public static function redactHttpBody(string $body, int $maxLength = 512): string
    {
        $redacted = trim(preg_replace('/\s+/', ' ', self::redact($body)) ?? self::redact($body));

        if (strlen($redacted) <= $maxLength) {
            return $redacted;
        }

        return substr($redacted, 0, $maxLength) . '...[truncated]';
    }

    /**
     * @param list<string> $messages
     * @return list<string>
     */
    public static function redactAll(array $messages): array
    {
        return array_values(array_map([self::class, 'redact'], $messages));
    }

    private static function redactBearerTokens(string $message): string
    {
        $message = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/=-]+\b/i', 'Bearer [REDACTED]', $message) ?? $message;
        return preg_replace('/\btoken\s+[A-Za-z0-9._~+\/=-]+\b/i', 'token [REDACTED]', $message) ?? $message;
    }

    private static function redactAuthorizationHeaders(string $message): string
    {
        $message = preg_replace('/(Authorization:\s*Bearer\s+)[^\s]+/i', '$1[REDACTED]', $message) ?? $message;
        return preg_replace('/(Authorization:\s*Basic\s+)[^\s]+/i', '$1[REDACTED]', $message) ?? $message;
    }

    private static function redactKnownTokenFormats(string $message): string
    {
        $patterns = [
            '/\bgh[pousr]_[A-Za-z0-9_]{20,}\b/' => '[REDACTED]',
            '/\bgithub_pat_[A-Za-z0-9_]{20,}\b/' => '[REDACTED]',
            '/\bgl(?:pat|oas|ptt|rt|dt)-[A-Za-z0-9._-]{10,}\b/' => '[REDACTED]',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $message = preg_replace($pattern, $replacement, $message) ?? $message;
        }

        return $message;
    }

    private static function redactKnownSecretEnvValues(string $message): string
    {
        $environment = getenv();

        if (! is_array($environment)) {
            return $message;
        }

        foreach ($environment as $name => $value) {
            if (! is_string($name) || ! is_string($value) || trim($value) === '') {
                continue;
            }

            if (! self::isKnownSecretEnvName($name)) {
                continue;
            }

            $message = str_replace($value, '[REDACTED]', $message);
        }

        return $message;
    }

    private static function isKnownSecretEnvName(string $name): bool
    {
        if (
            in_array($name, ['GITHUB_TOKEN', 'GITLAB_TOKEN', 'CI_JOB_TOKEN', PremiumCredentialsStore::envName()], true)
        ) {
            return true;
        }

        return preg_match('/^WP_CORE_BASE_.*(?:TOKEN|SECRET|PASSWORD|LICENSE|KEY)/', $name) === 1;
    }

    private static function redactUrls(string $message): string
    {
        $message = preg_replace_callback(
            '#https://[^\s]+#i',
            static function (array $matches): string {
                $url = $matches[0];
                $parts = parse_url($url);

                if (! is_array($parts)) {
                    return $url;
                }

                $redacted = $url;

                if (isset($parts['user']) || isset($parts['pass'])) {
                    $redacted = preg_replace('#https://([^/\s:@]+):([^@\s/]+)@#i', 'https://[REDACTED]:[REDACTED]@', $redacted) ?? $redacted;
                }

                if (isset($parts['query']) && is_string($parts['query'])) {
                    parse_str($parts['query'], $query);

                    foreach ($query as $key => $value) {
                        if (! is_string($key) || preg_match('/(?:token|secret|password|license|key)/i', $key) !== 1) {
                            continue;
                        }

                        $redacted = preg_replace(
                            '/([?&]' . preg_quote($key, '/') . '=)[^&#\s]*/i',
                            '$1[REDACTED]',
                            $redacted
                        ) ?? $redacted;
                    }
                }

                return $redacted;
            },
            $message
        ) ?? $message;

        return $message;
    }
}
