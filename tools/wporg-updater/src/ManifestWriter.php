<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class ManifestWriter implements ConfigWriter
{
    public function write(Config $config): void
    {
        $manifestDir = dirname($config->manifestPath);

        if (! is_dir($manifestDir) && ! mkdir($manifestDir, 0775, true) && ! is_dir($manifestDir)) {
            throw new RuntimeException(sprintf('Unable to create manifest directory: %s', $manifestDir));
        }

        $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config->toArray(), true) . ";\n";

        if (file_put_contents($config->manifestPath, $contents) === false) {
            throw new RuntimeException(sprintf('Unable to write manifest file: %s', $config->manifestPath));
        }
    }
}
