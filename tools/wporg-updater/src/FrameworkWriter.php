<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class FrameworkWriter
{
    public function write(FrameworkConfig $framework): void
    {
        $directory = dirname($framework->path);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create framework metadata directory: %s', $directory));
        }

        $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($framework->toArray(), true) . ";\n";

        if (file_put_contents($framework->path, $contents) === false) {
            throw new RuntimeException(sprintf('Unable to write framework metadata file: %s', $framework->path));
        }
    }
}
