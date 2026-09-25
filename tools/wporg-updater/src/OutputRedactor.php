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
        return array_map([self::class, 'redact'], $messages);
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

        foreach ($environment as $name => $value) {
            if (trim($value) === '') {
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

                if (isset($parts['query'])) {
                    // Classify decoded names without parse_str's key rewriting,
                    // array merging or input-count limit. Preserve every raw
                    // pair so encoded names and duplicate credentials are safe.
                    $query = preg_replace_callback(
                        '/(^|&)([^=&]+)=([^&]*)/',
                        static function (array $pair): string {
                            $name = urldecode($pair[2]);
                            $sensitive = preg_match(
                                '/(?:token|secret|password|license|key|credential|signature)|^(?:sig|policy|googleaccessid)(?:\[|$)/i',
                                $name
                            ) === 1;
                            return $sensitive ? $pair[1] . $pair[2] . '=[REDACTED]' : $pair[0];
                        },
                        $parts['query']
                    ) ?? $parts['query'];
                    $queryStart = strpos($redacted, '?');
                    if ($queryStart !== false) {
                        $redacted = substr_replace($redacted, $query, $queryStart + 1, strlen($parts['query']));
                    }
                }

                return $redacted;
            },
            $message
        ) ?? $message;

        return $message;
    }
}
