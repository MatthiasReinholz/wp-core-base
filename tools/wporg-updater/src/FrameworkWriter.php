<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

final class FrameworkWriter
{
    public function write(FrameworkConfig $framework): void
    {
        (new PhpArrayFileWriter())->write($framework->path, $framework->toArray());
    }
}
