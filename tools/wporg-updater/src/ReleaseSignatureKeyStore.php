<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class ReleaseSignatureKeyStore
{
    public const PUBLIC_KEY_RELATIVE_PATH = 'tools/wporg-updater/keys/framework-release-public.pem';

    public static function defaultPublicKeyPath(FrameworkConfig $framework): string
    {
        $distributionPath = trim($framework->distributionPath(), '/');
        $frameworkRoot = $distributionPath === '' || $distributionPath === '.'
            ? $framework->repoRoot
            : $framework->repoRoot . '/' . $distributionPath;

        return $frameworkRoot . '/' . self::PUBLIC_KEY_RELATIVE_PATH;
    }

    public static function privateKeyFromEnvironment(string $environmentVariable): string
    {
        $value = getenv($environmentVariable);

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException(sprintf(
                'Release signing private key environment variable is not set: %s',
                $environmentVariable
            ));
        }

        return $value;
    }

    public static function optionalEnvironmentValue(?string $environmentVariable): ?string
    {
        if ($environmentVariable === null || trim($environmentVariable) === '') {
            return null;
        }

        $value = getenv($environmentVariable);

        if ($value === false) {
            return null;
        }

        return (string) $value;
    }
}
