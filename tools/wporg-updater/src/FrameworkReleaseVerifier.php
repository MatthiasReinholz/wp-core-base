<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class FrameworkReleaseVerifier
{
    public function __construct(
        private readonly string $repoRoot,
    ) {
    }

    public function verify(?string $expectedTag = null): string
    {
        $framework = FrameworkConfig::load($this->repoRoot);
        $releaseVersion = $framework->normalizedVersion();
        $releaseTag = 'v' . $releaseVersion;
        $releaseNotesPath = $this->repoRoot . '/docs/releases/' . $releaseVersion . '.md';

        if ($expectedTag !== null && trim($expectedTag) !== '' && trim($expectedTag) !== $releaseTag) {
            throw new RuntimeException(sprintf(
                'Release tag mismatch. Expected %s from .wp-core-base/framework.php but received %s.',
                $releaseTag,
                trim($expectedTag)
            ));
        }

        if (! is_file($releaseNotesPath)) {
            throw new RuntimeException(sprintf('Release notes not found: %s', $releaseNotesPath));
        }

        $releaseNotes = file_get_contents($releaseNotesPath);

        if ($releaseNotes === false) {
            throw new RuntimeException(sprintf('Unable to read release notes: %s', $releaseNotesPath));
        }

        $missingSections = FrameworkReleaseNotes::missingRequiredSections($releaseNotes);

        if ($missingSections !== []) {
            throw new RuntimeException(sprintf(
                'Release notes %s are missing required sections: %s.',
                basename($releaseNotesPath),
                implode(', ', $missingSections)
            ));
        }

        if (! str_contains($releaseNotes, $framework->baseline['wordpress_core'])) {
            throw new RuntimeException(sprintf(
                'Release notes %s must mention the bundled WordPress core baseline %s.',
                basename($releaseNotesPath),
                $framework->baseline['wordpress_core']
            ));
        }

        if (trim($framework->repository) === '') {
            throw new RuntimeException('Framework metadata must declare repository.');
        }

        return $releaseTag;
    }
}
