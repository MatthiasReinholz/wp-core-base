<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class PremiumProviderRegistryWriter
{
    /**
     * @param array<string, array{class:string,path:?string}> $definitions
     */
    public function write(string $path, array $definitions): void
    {
        ksort($definitions);
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create premium provider registry directory: %s', $directory));
        }

        $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($definitions, true) . ";\n";

        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException(sprintf('Unable to write premium provider registry: %s', $path));
        }
    }
}
