<?php

declare(strict_types=1);

/** Test-only subprocess runner: a clean exit must also prove every phase assertion ran. */
final class WordpressSmokeRunner
{
    /** @param array<string,string> $environment */
    public static function run(string $fixture, string $phase, string $runtimeRoot, array $environment): void
    {
        $receipt = tempnam(sys_get_temp_dir(), 'wp-core-base-smoke-phase-');
        if ($receipt === false) {
            throw new RuntimeException('Unable to allocate WordPress smoke phase receipt.');
        }
        $token = $phase . ':' . bin2hex(random_bytes(32));
        $environment['WP_CORE_BASE_SMOKE_RECEIPT'] = $receipt;
        $environment['WP_CORE_BASE_SMOKE_RECEIPT_TOKEN'] = $token;
        try {
            $process = proc_open([PHP_BINARY, $fixture, $phase], [STDIN, STDOUT, STDERR], $pipes, $runtimeRoot, $environment);
            if (! is_resource($process) || proc_close($process) !== 0) {
                throw new RuntimeException('WordPress runtime smoke failed during ' . $phase . '.');
            }
            if (is_link($receipt) || ! is_file($receipt) || file_get_contents($receipt) !== $token) {
                throw new RuntimeException('WordPress runtime smoke did not complete every assertion during ' . $phase . '.');
            }
        } finally {
            if (file_exists($receipt) || is_link($receipt)) {
                unlink($receipt);
            }
        }
    }

    public static function complete(string $phase): void
    {
        $receipt = getenv('WP_CORE_BASE_SMOKE_RECEIPT');
        $token = getenv('WP_CORE_BASE_SMOKE_RECEIPT_TOKEN');
        if (! is_string($receipt) || ! is_string($token) || ! str_starts_with($token, $phase . ':')
            || ! is_file($receipt) || is_link($receipt)
            || file_put_contents($receipt, $token) !== strlen($token)) {
            throw new RuntimeException('Unable to confirm WordPress smoke phase completion.');
        }
    }
}
