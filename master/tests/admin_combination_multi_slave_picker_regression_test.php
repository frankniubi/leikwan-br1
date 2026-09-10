<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$admin = file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php') ?: '';
$bootstrap = file_get_contents(__DIR__ . '/../src/Bootstrap.php') ?: '';
$service = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php') ?: '';

foreach ([
    '<select name="combination_slaves[]" multiple',
    'Live donations',
    'redacted endpoint',
    '/admin/forward-combinations/{id}/donations/{donationId}/revoke',
    'public function revokeDonation(',
] as $snippet) {
    if (!str_contains($admin . $bootstrap, $snippet)) {
        fail('missing admin/bootstrap snippet: ' . $snippet);
    }
}

foreach ([
    'saveCombinationAdminPinnedSlaves(int $combinationId, array $nodeIds): void',
    'forward_combination_slaves',
    "selection_kind' => 'admin_pin'",
    'is_active = :inactive',
    'revokeDonationManually(int $combinationId, int $donationId): void',
    'forward_combination_self_leg_donors',
] as $snippet) {
    if (!str_contains($service, $snippet)) {
        fail('missing provisioning snippet: ' . $snippet);
    }
}

echo "ok
";
