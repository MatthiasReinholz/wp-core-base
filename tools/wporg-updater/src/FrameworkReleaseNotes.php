<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

final class FrameworkReleaseNotes
{
    private const REQUIRED_SECTIONS = [
        'Summary',
        'Downstream Impact',
        'Migration Notes',
        'Downstream Workflow Changes',
        'Required Downstream Actions',
        'Bundled Baseline',
    ];

    /**
     * @return array<string, string>
     */
    public static function parseSections(string $markdown): array
    {
        $sections = [];
        $currentHeading = null;
        $buffer = [];

        foreach (preg_split("/\r\n|\n|\r/", $markdown) ?: [] as $line) {
            if (preg_match('/^##\s+(.+?)\s*$/', $line, $matches) === 1) {
                if ($currentHeading !== null) {
                    $sections[$currentHeading] = trim(implode("\n", $buffer));
                }

                $currentHeading = trim($matches[1]);
                $buffer = [];
                continue;
            }

            if ($currentHeading !== null) {
                $buffer[] = $line;
            }
        }

        if ($currentHeading !== null) {
            $sections[$currentHeading] = trim(implode("\n", $buffer));
        }

        return $sections;
    }

    /**
     * @return list<string>
     */
    public static function missingRequiredSections(string $markdown): array
    {
        $sections = self::parseSections($markdown);
        $missing = [];

        foreach (self::REQUIRED_SECTIONS as $section) {
            if (! isset($sections[$section]) || trim($sections[$section]) === '') {
                $missing[] = $section;
            }
        }

        return $missing;
    }

    /**
     * @return list<string>
     */
    public static function requiredSections(): array
    {
        return self::REQUIRED_SECTIONS;
    }
}
