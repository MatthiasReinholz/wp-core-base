<?php

declare(strict_types=1);

use WpOrgPluginUpdater\Cli\CommandOptions;

require dirname(__DIR__, 2) . '/tools/wporg-updater/src/Autoload.php';

$repoRoot = dirname(__DIR__, 2);
$documents = [$repoRoot . '/README.md', $repoRoot . '/AGENTS.md', $repoRoot . '/SECURITY.md'];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repoRoot . '/docs', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->isFile() && $file->getExtension() === 'md') { $documents[] = $file->getPathname(); }
}
sort($documents);
$linkCount = 0; $commandCount = 0;
foreach ($documents as $document) {
    $contents = (string) file_get_contents($document);
    preg_match_all('/\[[^\]\n]*\]\((?:<([^>]+)>|([^\s)]+))(?:\s+"[^"]*")?\)/', $contents, $links, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
    foreach ($links as $link) {
        $target = $link[1] ?? $link[2];
        if (preg_match('~^(?:[a-z][a-z0-9+.-]*:|#)~i', $target) === 1) { continue; }
        $path = rawurldecode(explode('#', explode('?', $target, 2)[0], 2)[0]);
        if ($path === '' || str_contains($path, '{')) { continue; }
        $resolved = realpath(dirname($document) . '/' . $path);
        if (str_starts_with($path, '/') || $resolved === false || ! str_starts_with($resolved, $repoRoot . '/')) {
            throw new RuntimeException(sprintf('%s has an invalid repository-relative link: %s', substr($document, strlen($repoRoot) + 1), $target));
        }
        $linkCount++;
    }
    // Release notes describe historical CLI behavior and are intentionally not rewritten.
    if (str_contains($document, '/docs/releases/')) { continue; }
    $joined = preg_replace('/\\\\\r?\n\s*/', ' ', $contents) ?? $contents;
    preg_match_all('~(?:php\s+(?:[a-zA-Z0-9_.\-/]+/)?tools/wporg-updater/bin/wporg-updater\.php|(?:[a-zA-Z0-9_.\-/]+/)?bin/wp-core-base)\s+([a-z][a-z-]*)([^\r\n`]*)~', $joined, $commands, PREG_SET_ORDER);
    foreach ($commands as $command) {
        $mode = $command[1];
        $definition = CommandOptions::forCommand($mode);
        preg_match_all('/(?<![\w-])--([a-z][a-z0-9-]*)(=?)/', $command[2], $options, PREG_SET_ORDER);
        foreach ($options as $option) {
            $name = $option[1];
            if (! isset($definition[$name])) {
                throw new RuntimeException(sprintf('%s documents unsupported option %s --%s.', substr($document, strlen($repoRoot) + 1), $mode, $name));
            }
            if ($definition[$name] === 'value' && $option[2] !== '=') {
                throw new RuntimeException(sprintf('%s must use %s --%s=value.', substr($document, strlen($repoRoot) + 1), $mode, $name));
            }
            if ($definition[$name] === 'flag' && $option[2] === '=') {
                throw new RuntimeException(sprintf('%s assigns a value to boolean flag %s --%s.', substr($document, strlen($repoRoot) + 1), $mode, $name));
            }
        }
        $commandCount++;
    }
    preg_match_all('/(?<!`)`([^`\r\n]+)`(?!`)/', $contents, $inlineCommands, PREG_SET_ORDER);
    foreach ($inlineCommands as $inline) {
        if (preg_match('/^([a-z][a-z-]*)\s+(.+)$/', $inline[1], $parts) !== 1 || ! isset(CommandOptions::definitions()[$parts[1]])) { continue; }
        $definition = CommandOptions::forCommand($parts[1]);
        preg_match_all('/(?<![\w-])--([a-z][a-z0-9-]*)/', $parts[2], $flags);
        foreach ($flags[1] as $flag) {
            if (! isset($definition[$flag])) {
                throw new RuntimeException(sprintf('%s documents unsupported shorthand %s --%s.', substr($document, strlen($repoRoot) + 1), $parts[1], $flag));
            }
        }
        $commandCount++;
    }
}

// Execute only this curated, read-only subset. Documentation is never treated as shell code.
$cli = $repoRoot . '/tools/wporg-updater/bin/wporg-updater.php';
foreach (['doctor', 'list-dependencies', 'release-verify'] as $mode) {
    $output = documentationCommand($repoRoot, [PHP_BINARY, $cli, $mode, '--repo-root=' . $repoRoot, '--json']);
    $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($payload) || ($payload['status'] ?? null) !== 'success') {
        throw new RuntimeException(sprintf('Curated %s --json documentation example did not succeed.', $mode));
    }
}
foreach (array_keys(CommandOptions::definitions()) as $mode) {
    $output = documentationCommand($repoRoot, [PHP_BINARY, $cli, 'help', $mode]);
    if (trim($output) === '') { throw new RuntimeException('Empty help output for ' . $mode); }
}
fwrite(STDOUT, sprintf("Documentation verified: %d files, %d local links, %d CLI examples, 3 executable JSON examples and all command help topics.\n", count($documents), $linkCount, $commandCount));

/** @param list<string> $command */
function documentationCommand(string $cwd, array $command): string
{
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (! is_resource($process)) { throw new RuntimeException('Unable to start curated documentation example.'); }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) { throw new RuntimeException(sprintf('Curated documentation command failed (%d): %s %s', $status, $output, $error)); }
    return (string) $output;
}
