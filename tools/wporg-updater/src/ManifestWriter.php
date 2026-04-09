<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

final class ManifestWriter implements ConfigWriter
{
    public function write(Config $config): void
    {
        $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config->toArray(), true) . ";\n";
        (new AtomicFileWriter())->write($config->manifestPath, $contents);
    }
}
