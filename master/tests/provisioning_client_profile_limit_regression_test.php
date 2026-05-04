<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$schema = file_get_contents(__DIR__ . '/../migrations/schema.sql');
$migration = file_get_contents(__DIR__ . '/../migrations/20260504_add_plan_max_client_profiles.sql');
$provisioning = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');

foreach ([
    [$schema, 'max_client_profiles INT NOT NULL DEFAULT 1'],
    [$migration, 'ALTER TABLE service_plans ADD COLUMN max_client_profiles INT NOT NULL DEFAULT 1'],
    [$provisioning, "'max_client_profiles' => max(1, (int) (\$payload['max_client_profiles'] ?? 1))"],
] as [$haystack, $needle]) {
    if (!is_string($haystack) || !str_contains($haystack, $needle)) {
        fail('missing client-profile-limit schema snippet: ' . $needle);
    }
}

foreach ([
    "max_client_profiles = :max_client_profiles",
    "'max_client_profiles' => max(1, (int) (\$payload['max_client_profiles'] ?? 1))",
    "'plan_max_client_profiles' => (int) (\$plan['max_client_profiles'] ?? 1)",
    "'message' => 'Select at least one active forward combination before saving the plan.'",
] as $needle) {
    if (!str_contains($provisioning, $needle)) {
        fail('missing provisioning client-profile-limit snippet: ' . $needle);
    }
}

echo "ok\n";