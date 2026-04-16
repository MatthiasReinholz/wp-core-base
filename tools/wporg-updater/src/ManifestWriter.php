<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

final class ManifestWriter implements ConfigWriter
{
    public function write(Config $config): void
    {
        (new PhpArrayFileWriter())->write($config->manifestPath, $config->toArray());
    }
}
