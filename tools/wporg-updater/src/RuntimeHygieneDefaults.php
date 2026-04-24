<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

final class RuntimeHygieneDefaults
{
    public const FORBIDDEN_PATHS = [
        '.git',
        '.github',
        '.gitlab',
        '.gitea',
        '.forgejo',
        '.circleci',
        '.wordpress-org',
        'node_modules',
        'docs',
        'doc',
        'tests',
        'test',
        '__tests__',
        'examples',
        'example',
        'demo',
        'screenshots',
    ];

    public const FORBIDDEN_FILES = [
        'README*',
        'CHANGELOG*',
        '.gitignore',
        '.gitattributes',
        '.gitlab-ci.yml',
        'bitbucket-pipelines.yml',
        'phpunit.xml*',
        'composer.json',
        'composer.lock',
        'package.json',
        'package-lock.json',
        'pnpm-lock.yaml',
        'yarn.lock',
    ];

    public const MANAGED_SANITIZE_PATH_SUFFIXES = [
        '.github',
        '.gitlab',
        '.gitea',
        '.forgejo',
        '.circleci',
        '.wordpress-org',
        'node_modules',
        'docs',
        'doc',
        'tests',
        'test',
        '__tests__',
        'examples',
        'example',
        'demo',
        'screenshots',
    ];

    public const MANAGED_SANITIZE_FILES = self::FORBIDDEN_FILES;

    /**
     * @param array{content_root:string, plugins_root:string, themes_root:string, mu_plugins_root:string} $paths
     * @return list<string>
     */
    public static function managedSanitizePaths(array $paths): array
    {
        $entries = [];

        foreach ([$paths['plugins_root'], $paths['themes_root'], $paths['mu_plugins_root']] as $root) {
            foreach (self::MANAGED_SANITIZE_PATH_SUFFIXES as $suffix) {
                $entries[] = $root . '/' . $suffix;
            }
        }

        return $entries;
    }
}
