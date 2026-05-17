<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Controllers/SidecarAgentController.php');
if (!is_string($source) || $source === '') {
    fail('failed to read SidecarAgentController source');
}

foreach ([
    '$directCfBackendForwardIds = []',
    '$rawDirectCfBackendForwardIds = $runtimeState[\'cf_direct_backend_forward_ids\'] ?? []',
    'use ($directCfBackendForwardIds)',
    "'backend_mode' => isset(\$directCfBackendForwardIds[\$forwardId]) ? 'direct' : 'wstunnel'",
] as $snippet) {
    if (!str_contains($source, $snippet)) {
        fail('expected SidecarAgentController direct CF backend snippet missing: ' . $snippet);
    }
}

echo "ok\n";