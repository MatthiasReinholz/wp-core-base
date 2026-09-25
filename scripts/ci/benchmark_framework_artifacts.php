<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/tools/wporg-updater/src/Autoload.php';

use WpOrgPluginUpdater\TempWorkspace;
use WpOrgPluginUpdater\ZipExtractor;

$options = ['iterations' => '3'];
foreach (array_slice(array_values(array_map('strval', $GLOBALS['argv'] ?? [])), 1) as $argument) {
    if (preg_match('/^--(baseline|candidate|iterations)=(.+)$/D', $argument, $match) !== 1) {
        throw new RuntimeException('Use --baseline=old.zip --candidate=new.zip [--iterations=3].');
    }
    $options[$match[1]] = $match[2];
}
if (! ctype_digit($options['iterations']) || (int) $options['iterations'] < 1 || (int) $options['iterations'] > 10) {
    throw new RuntimeException('Iterations must be between 1 and 10.');
}
$measurements = [];
foreach (['baseline', 'candidate'] as $role) {
    $path = $options[$role] ?? '';
    if (! is_file($path)) {
        throw new RuntimeException('Missing local artifact: ' . $role);
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Unable to open artifact: ' . $role);
    }
    try {
        $uncompressed = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            if ($stat === false) {
                throw new RuntimeException('Unable to inspect archive entry.');
            }
            $uncompressed += $stat['size'];
        }
        $durations = [];
        for ($iteration = 0; $iteration < (int) $options['iterations']; $iteration++) {
            $workspace = TempWorkspace::create(dirname(__DIR__, 2), 'artifact-benchmark');
            try {
                $start = hrtime(true);
                ZipExtractor::extractValidated($zip, $workspace->path());
                $durations[] = (hrtime(true) - $start) / 1e6;
            } finally {
                $workspace->close();
            }
        }
        sort($durations, SORT_NUMERIC);
        $middle = intdiv(count($durations), 2);
        $median = count($durations) % 2 === 0 ? ($durations[$middle - 1] + $durations[$middle]) / 2 : $durations[$middle];
        $measurements[$role] = ['artifact' => basename($path), 'sha256' => hash_file('sha256', $path),
            'archive_bytes' => filesize($path), 'entries' => $zip->numFiles, 'uncompressed_bytes' => $uncompressed,
            'validated_extraction_ms' => array_map(static fn (float $value): float => round($value, 2), $durations),
            'median_validated_extraction_ms' => round($median, 2)];
    } finally {
        $zip->close();
    }
}
$report = ['environment' => ['php' => PHP_VERSION, 'os' => PHP_OS_FAMILY, 'architecture' => php_uname('m')],
    'method' => 'Sequential local validated extractions into fresh private directories; filesystem caches are uncontrolled. This measures packaging cost, not application throughput, network speed or CI cost.',
    ...$measurements];
fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
