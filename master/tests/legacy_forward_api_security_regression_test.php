<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$service = file_get_contents(__DIR__ . '/../src/Controllers/ServiceController.php');
$tenant = file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');
if (!is_string($service) || !is_string($tenant)) {
    fail('failed to read compatibility controllers');
}

foreach ([
    'ForwardLineApiContract',
    'normalizeLegacyCreate',
    'normalizeLegacyUpdate',
    'allow_manage_forwards',
    'allow_delete_forwards',
    'service_inactive',
    'forward_quota_exceeded',
    "'Deprecation' => 'true'",
] as $required) {
    if (!str_contains($service . $tenant, $required)) {
        fail('missing compatibility security gate: ' . $required);
    }
}

if (str_contains($service, "'client_config' => \$this->provisioning->createForward")) {
    fail('legacy create still returns runtime client config');
}
if (str_contains($service, "'client_config' => \$this->provisioning->updateForwardNonStructural")) {
    fail('legacy update still returns runtime client config');
}

echo "ok\n";
