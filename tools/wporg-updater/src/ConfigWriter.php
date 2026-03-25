<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

interface ConfigWriter
{
    public function write(Config $config): void;
}
