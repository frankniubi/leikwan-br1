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
    'public function republishForwardsForServiceWithResult(int $serviceId): array',
    'public function republishForwardsForNodeWithResult(int $nodeId): array',
    'public function republishForwardsForClientWithResult(int $clientId): array',
    'public function republishAllForwardsWithResult(): array',
    'public function republishActiveForwardsForNode(int $nodeId): void',
    '$this->republishActiveForwardsForService($serviceId);',
    '$this->republishActiveForwardsForNode($nodeId);',
    '$this->republishActiveForwardsForClient($clientId);',
    'return [\'republished\' => $count, \'errors\' => $errors];',
];

foreach ($requiredSnippets as $snippet) {
    if (!str_contains($source, $snippet)) {
        fail('expected ProvisioningService to contain snippet: ' . $snippet);
    }
}

echo "ok\n";
