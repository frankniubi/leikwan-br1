<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');
foreach ([
    'assignedForwardConfigsForClient(int $clientId, ?array $deviceSession = null): array',
    'candidate_kind',
    'self_leg',
    'rented_group_leg',
    'runtime_preference',
    'helper_group_cap',
] as $snippet) {
    if (!str_contains($source, $snippet)) {
        fail('missing provisioning snippet: ' . $snippet);
    }
}

echo "ok\n";
