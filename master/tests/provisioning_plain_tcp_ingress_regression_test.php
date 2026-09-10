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
    'private function normalizeBondIngressMode(string $value): string',
    "'bond_ingress_mode' =>",
    "'ingress_mode' =>",
    "'plain_tcp_mux'",
    "'route_key' =>",
    "'backend_protocol' =>",
    "'ingress_protocol' =>",
    "'ingress_auth_token' =>",
];

foreach ($requiredSnippets as $snippet) {
    if (!str_contains($source, $snippet)) {
        fail('expected ProvisioningService to contain snippet: ' . $snippet);
    }
}

echo "ok\n";
