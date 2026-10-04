<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$registration = file_get_contents(__DIR__ . '/../src/Support/TenantRegistrationService.php');
$admin = file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');
$provisioning = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');

if (!is_string($registration) || $registration === '') {
    fail('failed to read TenantRegistrationService source');
}
if (!is_string($admin) || $admin === '') {
    fail('failed to read AdminController source');
}
if (!is_string($provisioning) || $provisioning === '') {
    fail('failed to read ProvisioningService source');
}

foreach ([
    '$this->provisioning()->ensureDefaultClientForUser((int) ($user[\'id\'] ?? 0));',
    '$this->provisioning?->ensureDefaultClientForUser($tenantId);',
    '$this->ensureDefaultClientForUser((int) $user[\'id\']);',
] as $required) {
    if (!str_contains($registration . $admin . $provisioning, $required)) {
        fail('missing default-client creation hook: ' . $required);
    }
}

echo "ok\n";
