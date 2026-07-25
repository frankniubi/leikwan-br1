<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$sources = '';
foreach ([$root . '/src/Controllers', $root . '/public'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    foreach ($iterator as $file) {
        if ($file->isFile() && in_array($file->getExtension(), ['php', 'css', 'js', 'json'], true)) {
            $sources .= (string) file_get_contents($file->getPathname());
        }
    }
}
foreach (['Speed max', 'Latency Best', 'client_strategy', 'strategy_mode', 'latency_ms_by_node'] as $forbidden) {
    if (str_contains($sources, $forbidden)) {
        throw new RuntimeException('client-local state leaked into Web source: ' . $forbidden);
    }
}
echo "ok\n";
