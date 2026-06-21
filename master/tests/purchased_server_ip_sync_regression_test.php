<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$whmcs = file_get_contents(__DIR__ . '/../src/Support/WhmcsClient.php');
$provisioning = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');

foreach ([
    [$whmcs, 'normalizePurchasedServerIdentity('],
    [$whmcs, 'dedicatedip'],
    [$whmcs, 'assignedips'],
    [$provisioning, "payload['products'][0]"],
    [$provisioning, 'server_hostname'],
    [$provisioning, 'server_public_ip'],
    [$provisioning, 'server_ip_state'],
    [$provisioning, 'server_ip_last_synced_at'],
    [$provisioning, "'stale'"],
    [$provisioning, "\$targetSource = trim((string) (\$payload['target_source'] ?? 'manual'));"],
    [$provisioning, "\$targetServiceId = (int) (\$payload['target_service_id'] ?? 0);"],
    [$provisioning, "'target_source' => \$targetSource !== '' ? \$targetSource : 'manual'"],
    [$provisioning, "'target_service_id' => \$targetServiceId > 0 ? \$targetServiceId : null"],
    [$provisioning, 'target_source = :target_source'],
    [$provisioning, 'target_service_id = :target_service_id'],
    [$provisioning, 'target_service_id'],
    [$provisioning, 'target_source'],
] as [$haystack, $needle]) {
    if (!is_string($haystack) || !str_contains($haystack, $needle)) {
        fail('missing purchased-server-ip sync snippet: ' . $needle);
    }
}

echo "ok\n";
