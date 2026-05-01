<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$path = __DIR__ . '/../src/Controllers/SidecarAgentController.php';
$source = file_get_contents($path);
if (!is_string($source) || $source === '') {
    fail('failed to read SidecarAgentController source');
}

foreach ([
    "'service_name' => 'cf-forward-' . \$forwardId",
    "'service_name' => 'cf-forward-' . \$forwardId . '-uot'",
    "'target' => '127.0.0.1:8999'",
    "'127.0.0.1:' . \$targetPort",
] as $snippet) {
    if (!str_contains($source, $snippet)) {
        fail('expected SidecarAgentController to contain snippet: ' . $snippet);
    }
}

echo "ok\n";
