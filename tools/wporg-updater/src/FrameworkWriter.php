<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

final class FrameworkWriter
{
    public function write(FrameworkConfig $framework): void
    {
        $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($framework->toArray(), true) . ";\n";
        (new AtomicFileWriter())->write($framework->path, $contents);
    }
}
