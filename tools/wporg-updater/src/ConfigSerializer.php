<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

final class ConfigSerializer
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(Config $config): array
    {
        return [
            'profile' => $config->profile,
            'paths' => $config->paths,
            'core' => $config->core,
            'runtime' => $config->runtime,
            'github' => $config->github,
            'automation' => $config->automation,
            'security' => $config->security,
            'dependencies' => array_map(static function (array $dependency): array {
                return [
                    'name' => $dependency['name'],
                    'slug' => $dependency['slug'],
                    'kind' => $dependency['kind'],
                    'management' => $dependency['management'],
                    'source' => $dependency['source'],
                    'path' => $dependency['path'],
                    'main_file' => $dependency['main_file'],
                    'version' => $dependency['version'],
                    'checksum' => $dependency['checksum'],
                    'archive_subdir' => $dependency['archive_subdir'],
                    'extra_labels' => $dependency['extra_labels'],
                    'source_config' => $dependency['source_config'],
                    'policy' => $dependency['policy'],
                ];
            }, $config->dependencies),
        ];
    }
}
