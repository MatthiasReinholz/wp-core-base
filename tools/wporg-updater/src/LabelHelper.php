<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

final class LabelHelper
{
    public const MAX_LENGTH = 50;

    public static function normalize(string $label): string
    {
        $label = trim($label);

        if ($label === '' || strlen($label) <= self::MAX_LENGTH) {
            return $label;
        }

        $hash = substr(sha1($label), 0, 8);
        $prefix = '';
        $body = $label;
        $delimiterPosition = strpos($label, ':');

        if ($delimiterPosition !== false && $delimiterPosition < 24) {
            $prefix = substr($label, 0, $delimiterPosition + 1);
            $body = substr($label, $delimiterPosition + 1);
        }

        $maxBodyLength = self::MAX_LENGTH - strlen($prefix) - strlen($hash) - 1;

        if ($maxBodyLength < 1) {
            $prefix = '';
            $body = $label;
            $maxBodyLength = self::MAX_LENGTH - strlen($hash) - 1;
        }

        $body = rtrim(substr($body, 0, $maxBodyLength), "-:_ \t\n\r\0\x0B");

        if ($body === '') {
            $body = substr($label, 0, $maxBodyLength);
        }

        return $prefix . $body . '-' . $hash;
    }

    /**
     * @param list<string> $labels
     * @return list<string>
     */
    public static function normalizeList(array $labels): array
    {
        $normalized = [];

        foreach ($labels as $label) {
            $candidate = self::normalize($label);

            if ($candidate === '') {
                continue;
            }

            $normalized[] = $candidate;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<string, array{color:string, description:string}> $definitions
     * @return array<string, array{color:string, description:string}>
     */
    public static function normalizeDefinitions(array $definitions): array
    {
        $normalized = [];

        foreach ($definitions as $label => $definition) {
            $normalized[self::normalize($label)] = $definition;
        }

        ksort($normalized);

        return $normalized;
    }
}
