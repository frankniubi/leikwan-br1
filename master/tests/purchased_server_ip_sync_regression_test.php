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
    [$whmcs, 'GetClientsProducts'],
    [$whmcs, 'dedicatedip'],
    [$whmcs, 'assignedips'],
    [$provisioning, 'server_public_ip'],
    [$provisioning, 'server_ip_state'],
    [$provisioning, 'server_ip_last_synced_at'],
    [$provisioning, 'target_service_id'],
    [$provisioning, 'target_source'],
] as [$haystack, $needle]) {
    if (!is_string($haystack) || !str_contains($haystack, $needle)) {
        fail('missing purchased-server-ip sync snippet: ' . $needle);
    }
}

echo "ok\n";