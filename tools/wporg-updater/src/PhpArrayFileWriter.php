<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

final class PhpArrayFileWriter
{
    /**
     * @param array<string, mixed> $data
     */
    public function write(string $path, array $data): void
    {
        $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($data, true) . ";\n";
        (new AtomicFileWriter())->write($path, $contents);
    }
}
