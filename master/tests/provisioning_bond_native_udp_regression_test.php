<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$path = __DIR__ . '/../src/Support/ProvisioningService.php';
$source = file_get_contents($path);
if (!is_string($source) || $source === '') {
    fail('failed to read ProvisioningService source');
}

$requiredSnippets = [
    "'session_token' =>",
    "'udp_session_token' =>",
    "'ingress_mode' => \$ingressMode",
    "'route_key' =>",
    "'sidecar_host' =>",
    "'sidecar_port' =>",
    "'remote_target' =>",
];

foreach ($requiredSnippets as $snippet) {
    if (!str_contains($source, $snippet)) {
        fail('expected ProvisioningService to contain snippet: ' . $snippet);
    }
}

echo "ok\n";
