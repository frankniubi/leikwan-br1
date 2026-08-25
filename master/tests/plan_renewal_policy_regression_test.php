<?php
declare(strict_types=1);

function failPlanRenewal(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');
if (!is_string($source)) {
    failPlanRenewal('failed to read ProvisioningService');
}

foreach ([
    "SELECT is_active, feature_flags_json FROM service_plans WHERE id = :id LIMIT 1",
    "array_key_exists('allow_renewal', \$flags)",
    "(int) (\$plan['is_active'] ?? 0) !== 1",
    "service_renewal_disabled",
    "'allow_renewal'",
    'private function featureFlagValue(mixed $value): bool',
    "bandwidth_upgrade_disabled",
    "This package has a fixed bandwidth limit of ",
    "default_traffic_limit_gb'] ?? \$trafficLimit",
] as $needle) {
    if (!str_contains($source, $needle)) {
        failPlanRenewal('missing non-renewal guard: ' . $needle);
    }
}

echo "ok\n";
