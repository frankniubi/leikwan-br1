<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$schema = file_get_contents(__DIR__ . '/../migrations/schema.sql');
$migration = file_get_contents(__DIR__ . '/../migrations/20260504_add_plan_visibility_and_device_limit.sql');
$provisioning = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');

foreach ([
    [$schema, 'is_tenant_visible TINYINT(1) NOT NULL DEFAULT 1'],
    [$schema, 'default_client_online_device_limit INT NOT NULL DEFAULT 1'],
    [$migration, 'ALTER TABLE service_plans ADD COLUMN is_tenant_visible TINYINT(1) NOT NULL DEFAULT 1'],
    [$migration, 'ALTER TABLE service_plans ADD COLUMN default_client_online_device_limit INT NOT NULL DEFAULT 1'],
    [$provisioning, "(int) (\$plan['is_tenant_visible'] ?? 1) === 1"],
] as [$haystack, $needle]) {
    if (!is_string($haystack) || !str_contains($haystack, $needle)) {
        fail('missing storefront visibility schema snippet: ' . $needle);
    }
}

foreach ([
    "'is_tenant_visible' => (int) ((\$payload['is_tenant_visible'] ?? '1') ? 1 : 0)",
    "'default_client_online_device_limit' => max(1, (int) (\$payload['default_client_online_device_limit'] ?? 1))",
    "is_tenant_visible = :is_tenant_visible",
    "default_client_online_device_limit = :default_client_online_device_limit",
    "static fn(array \$plan): bool => (int) (\$plan['is_active'] ?? 0) === 1 && (int) (\$plan['is_tenant_visible'] ?? 1) === 1",
    "if ((int) (\$plan['is_tenant_visible'] ?? 1) !== 1) {",
] as $needle) {
    if (!str_contains($provisioning, $needle)) {
        fail('missing plan storefront persistence snippet: ' . $needle);
    }
}

echo "ok\n";