<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

/** Delegates cryptography, certificate trust and transparency proof checks to Cosign. */
final class SigstoreBundleVerifier
{
    public function __construct(
        private readonly string $binary = 'cosign',
        private readonly int $timeoutSeconds = 60,
    ) {
        if ($binary === '' || str_contains($binary, "\0") || $timeoutSeconds < 1) {
            throw new RuntimeException('Invalid Sigstore verifier executable or timeout.');
        }
    }

    /** @param array{bundle_asset_pattern:string,certificate_identity:string,oidc_issuer:string} $policy */
    public function verify(string $archivePath, string $bundle, array $policy): void
    {
        if (! is_file($archivePath) || is_link($archivePath) || $bundle === '' || strlen($bundle) > 1024 * 1024) {
            throw new RuntimeException('Sigstore verification requires a regular archive and a nonempty bundle of at most one MiB.');
        }
        $resolvedArchive = realpath($archivePath);
        if ($resolvedArchive === false) {
            throw new RuntimeException('Unable to resolve the archive for signature verification.');
        }
        $archivePath = $resolvedArchive;
        $bundlePath = tempnam(dirname($archivePath), 'sigstore-');
        if ($bundlePath === false) {
            throw new RuntimeException('Unable to allocate the private Sigstore bundle file.');
        }
        try {
            if (file_put_contents($bundlePath, $bundle) !== strlen($bundle)) {
                throw new RuntimeException('Unable to write the private Sigstore bundle file.');
            }
            $this->run([
                $this->binary, 'verify-blob', '--offline',
                '--bundle=' . $bundlePath,
                '--certificate-identity=' . $policy['certificate_identity'],
                '--certificate-oidc-issuer=' . $policy['oidc_issuer'],
                $archivePath,
            ]);
        } finally {
            unlink($bundlePath);
        }
    }

    /** @param list<string> $command */
    private function run(array $command): void
    {
        // The verifier needs neither release-reader tokens nor publisher credentials.
        $environment = [];
        foreach (['PATH', 'HOME', 'TMPDIR', 'SYSTEMROOT', 'SSL_CERT_FILE', 'SSL_CERT_DIR', 'HTTPS_PROXY', 'HTTP_PROXY', 'NO_PROXY'] as $name) {
            $value = getenv($name);
            if (is_string($value)) {
                $environment[$name] = $value;
            }
        }
        $pipes = [];
        $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
        if (! is_resource($process)) {
            throw new RuntimeException('Cannot start Cosign. Install the verification tools before downloading signed dependencies.');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $deadline = hrtime(true) + $this->timeoutSeconds * 1_000_000_000;
        $bytes = 0;
        $exitCode = -1;
        try {
            while (true) {
                foreach ([$pipes[1], $pipes[2]] as $pipe) {
                    $output = stream_get_contents($pipe, 65537);
                    if (is_string($output)) {
                        $bytes += strlen($output);
                    }
                }
                if ($bytes > 65536) {
                    throw new RuntimeException('Cosign verification exceeded its diagnostic output limit.');
                }
                $status = proc_get_status($process);
                if (! $status['running']) {
                    $exitCode = $status['exitcode'];
                    break;
                }
                if (hrtime(true) >= $deadline) {
                    throw new RuntimeException('Cosign verification timed out.');
                }
                usleep(10000);
            }
        } finally {
            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process, 9);
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
        if ($exitCode !== 0) {
            throw new RuntimeException('Sigstore verification failed: archive, certificate identity, issuer or transparency proof was not accepted. Ensure Cosign is installed; the release was rejected.');
        }
    }
}
