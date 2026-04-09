<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class FrameworkReleaseSignature
{
    private const CONTEXT = 'wp-core-base-release-signature-v1';
    private const ALGORITHM = 'sha256';

    public static function signChecksumFile(
        string $checksumPath,
        string $signaturePath,
        string $privateKeyPem,
        ?string $passphrase = null,
    ): array {
        self::assertOpenSslAvailable('release signing');

        if (! is_file($checksumPath)) {
            throw new RuntimeException(sprintf('Release checksum file not found: %s', $checksumPath));
        }

        $privateKey = openssl_pkey_get_private($privateKeyPem, $passphrase ?? '');

        if ($privateKey === false) {
            throw new RuntimeException('Unable to load release signing private key.');
        }

        $publicKeyDetails = openssl_pkey_get_details($privateKey);

        if (! is_array($publicKeyDetails) || ! is_string($publicKeyDetails['key'] ?? null) || trim($publicKeyDetails['key']) === '') {
            throw new RuntimeException('Unable to derive release signing public key details.');
        }

        $checksumSha256 = self::hashFile($checksumPath);
        $signedFile = basename($checksumPath);
        $payload = self::payload($signedFile, $checksumSha256);
        $rawSignature = '';

        if (! openssl_sign($payload, $rawSignature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Failed to create detached release signature.');
        }

        $document = [
            'context' => self::CONTEXT,
            'algorithm' => self::ALGORITHM,
            'signed_file' => $signedFile,
            'checksum_sha256' => $checksumSha256,
            'key_id' => self::keyId($publicKeyDetails['key']),
            'signature' => base64_encode($rawSignature),
        ];

        $encoded = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (! is_string($encoded) || file_put_contents($signaturePath, $encoded . "\n") === false) {
            throw new RuntimeException(sprintf('Unable to write release signature file: %s', $signaturePath));
        }

        return $document;
    }

    public static function verifyChecksumFile(string $checksumPath, string $signaturePath, string $publicKeyPath): array
    {
        self::assertOpenSslAvailable('release signature verification');

        if (! is_file($checksumPath)) {
            throw new RuntimeException(sprintf('Release checksum file not found: %s', $checksumPath));
        }

        if (! is_file($signaturePath)) {
            throw new RuntimeException(sprintf('Release signature file not found: %s', $signaturePath));
        }

        if (! is_file($publicKeyPath)) {
            throw new RuntimeException(sprintf('Release public key file not found: %s', $publicKeyPath));
        }

        $publicKeyPem = file_get_contents($publicKeyPath);

        if (! is_string($publicKeyPem) || trim($publicKeyPem) === '') {
            throw new RuntimeException(sprintf('Unable to read release public key file: %s', $publicKeyPath));
        }

        $document = self::readSignatureDocument($signaturePath);
        $checksumSha256 = self::hashFile($checksumPath);

        if ($document['context'] !== self::CONTEXT) {
            throw new RuntimeException(sprintf('Unsupported release signature context: %s', $document['context']));
        }

        if ($document['algorithm'] !== self::ALGORITHM) {
            throw new RuntimeException(sprintf('Unsupported release signature algorithm: %s', $document['algorithm']));
        }

        if ($document['signed_file'] !== basename($checksumPath)) {
            throw new RuntimeException(sprintf(
                'Release signature bound checksum to %s, expected %s.',
                $document['signed_file'],
                basename($checksumPath)
            ));
        }

        if (! hash_equals($document['checksum_sha256'], $checksumSha256)) {
            throw new RuntimeException(sprintf(
                'Release signature checksum digest mismatch. Expected %s but found %s.',
                $document['checksum_sha256'],
                $checksumSha256
            ));
        }

        $expectedKeyId = self::keyId($publicKeyPem);

        if (! hash_equals($document['key_id'], $expectedKeyId)) {
            throw new RuntimeException(sprintf(
                'Release signature key mismatch. Expected %s but found %s.',
                $expectedKeyId,
                $document['key_id']
            ));
        }

        $publicKey = openssl_pkey_get_public($publicKeyPem);

        if ($publicKey === false) {
            throw new RuntimeException('Unable to load release signing public key.');
        }

        $rawSignature = base64_decode($document['signature'], true);

        if (! is_string($rawSignature) || $rawSignature === '') {
            throw new RuntimeException('Release signature payload was not valid base64.');
        }

        $verified = openssl_verify(
            self::payload($document['signed_file'], $document['checksum_sha256']),
            $rawSignature,
            $publicKey,
            OPENSSL_ALGO_SHA256
        );

        if ($verified !== 1) {
            throw new RuntimeException('Release signature verification failed.');
        }

        return $document;
    }

    private static function assertOpenSslAvailable(string $operation): void
    {
        if (! function_exists('openssl_pkey_get_private') || ! function_exists('openssl_sign') || ! function_exists('openssl_verify')) {
            throw new RuntimeException(sprintf('The openssl extension is required for %s.', $operation));
        }
    }

    private static function hashFile(string $path): string
    {
        $digest = hash_file('sha256', $path);

        if (! is_string($digest) || $digest === '') {
            throw new RuntimeException(sprintf('Unable to hash file: %s', $path));
        }

        return strtolower($digest);
    }

    private static function payload(string $signedFile, string $checksumSha256): string
    {
        return implode("\n", [self::CONTEXT, $signedFile, $checksumSha256]) . "\n";
    }

    private static function keyId(string $publicKeyPem): string
    {
        return 'sha256:' . hash('sha256', trim(str_replace("\r", '', $publicKeyPem)));
    }

    /**
     * @return array{context:string,algorithm:string,signed_file:string,checksum_sha256:string,key_id:string,signature:string}
     */
    private static function readSignatureDocument(string $signaturePath): array
    {
        $contents = file_get_contents($signaturePath);

        if (! is_string($contents) || trim($contents) === '') {
            throw new RuntimeException(sprintf('Unable to read release signature file: %s', $signaturePath));
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            throw new RuntimeException(sprintf('Release signature file must contain a JSON object: %s', $signaturePath));
        }

        $context = (string) ($decoded['context'] ?? '');
        $algorithm = (string) ($decoded['algorithm'] ?? '');
        $signedFile = (string) ($decoded['signed_file'] ?? '');
        $checksumSha256 = strtolower((string) ($decoded['checksum_sha256'] ?? ''));
        $keyId = (string) ($decoded['key_id'] ?? '');
        $signature = (string) ($decoded['signature'] ?? '');

        if ($context === '' || $algorithm === '' || $signedFile === '' || $checksumSha256 === '' || $keyId === '' || $signature === '') {
            throw new RuntimeException(sprintf('Release signature file is missing required fields: %s', $signaturePath));
        }

        if (preg_match('/^[a-f0-9]{64}$/', $checksumSha256) !== 1) {
            throw new RuntimeException(sprintf('Release signature checksum digest is invalid: %s', $signaturePath));
        }

        return [
            'context' => $context,
            'algorithm' => $algorithm,
            'signed_file' => $signedFile,
            'checksum_sha256' => $checksumSha256,
            'key_id' => $keyId,
            'signature' => $signature,
        ];
    }
}
