<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

/** Shared ownership checks for branch cleanup and read-only residue monitoring. */
final class ManagedPullRequestBranchIdentity
{
    /** @var array<string, string> */
    private const LABEL_BRANCH_PREFIXES = [
        'automation:dependency-update' => 'codex/wporg-',
        'automation:framework-update' => 'codex/framework-',
    ];

    private const CORE_BRANCH_PREFIX = 'codex/wordpress-core-';

    /**
     * Validate ownership only; this neither reads a remote ref nor authorizes its deletion.
     * Callers must independently check current PR state and the expected head revision.
     *
     * @param array<string, mixed> $pullRequest
     */
    public static function validate(array $pullRequest, string $defaultBranch): string
    {
        $number = (int) ($pullRequest['number'] ?? 0);
        $metadata = PrBodyRenderer::extractMetadata((string) ($pullRequest['body'] ?? ''));

        if ($metadata === null) {
            throw new RuntimeException(sprintf('Pull request #%d has no valid wp-core-base metadata.', $number));
        }

        if (! AutomationPullRequestGuard::isSameRepositoryAutomationPullRequest($pullRequest)) {
            throw new RuntimeException(sprintf('Pull request #%d does not use a same-repository branch.', $number));
        }

        $head = is_array($pullRequest['head'] ?? null) ? $pullRequest['head'] : [];
        $base = is_array($pullRequest['base'] ?? null) ? $pullRequest['base'] : [];
        $headRef = (string) ($head['ref'] ?? '');
        $metadataBranch = (string) ($metadata['branch'] ?? '');

        if ($headRef === '' || $metadataBranch === '' || ! hash_equals($headRef, $metadataBranch)) {
            throw new RuntimeException(sprintf('Pull request #%d metadata does not match its head branch.', $number));
        }

        $baseRef = (string) ($base['ref'] ?? '');

        if ($headRef === $defaultBranch || ($baseRef !== '' && $headRef === $baseRef)) {
            throw new RuntimeException(sprintf('Pull request #%d resolves to protected branch %s.', $number, $headRef));
        }

        $expectedPrefix = self::expectedBranchPrefix($pullRequest, $metadata);

        if (! str_starts_with($headRef, $expectedPrefix) || strlen($headRef) <= strlen($expectedPrefix)) {
            throw new RuntimeException(sprintf(
                'Pull request #%d branch %s is outside the managed namespace %s.',
                $number,
                $headRef,
                $expectedPrefix
            ));
        }

        return $headRef;
    }

    /**
     * @param array<string, mixed> $pullRequest
     * @param array<string, mixed> $metadata
     */
    private static function expectedBranchPrefix(array $pullRequest, array $metadata): string
    {
        $labels = [];

        foreach ((array) ($pullRequest['labels'] ?? []) as $label) {
            $name = is_array($label) ? (string) ($label['name'] ?? '') : (string) $label;

            if ($name !== '') {
                $labels[$name] = true;
            }
        }

        $recognizedLabels = array_values(array_intersect(array_keys(self::LABEL_BRANCH_PREFIXES), array_keys($labels)));

        if (count($recognizedLabels) !== 1) {
            throw new RuntimeException('Pull request must have exactly one recognized wp-core-base automation label.');
        }

        if ($recognizedLabels[0] === 'automation:framework-update') {
            if (($metadata['component_key'] ?? null) !== 'framework:wp-core-base') {
                throw new RuntimeException('Framework automation metadata has an unexpected component key.');
            }

            return self::LABEL_BRANCH_PREFIXES['automation:framework-update'];
        }

        if (($metadata['kind'] ?? null) === 'core' && ($metadata['slug'] ?? null) === 'wordpress-core') {
            return self::CORE_BRANCH_PREFIX;
        }

        $componentKey = (string) ($metadata['component_key'] ?? '');

        if ($componentKey === '' || ! str_contains($componentKey, ':')) {
            throw new RuntimeException('Dependency automation metadata has no valid component key.');
        }

        return self::LABEL_BRANCH_PREFIXES['automation:dependency-update'];
    }
}
