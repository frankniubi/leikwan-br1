<?php
declare(strict_types=1);

function failUltraLeaseSchema(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertUltraLeaseSchema(bool $condition, string $message): void
{
    if (!$condition) {
        failUltraLeaseSchema($message);
    }
}

$source = (string) file_get_contents(__DIR__ . '/../migrations/20260825_add_ultra_policy_leases.sql');
$provisioning = (string) file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');
foreach (['ultra_client_leases', 'policy_generation', 'nonce_hash', 'quota_bytes', 'consumed_at', 'revoked_at', 'ultra_usage_events', 'sequence_no'] as $needle) {
    assertUltraLeaseSchema(str_contains($source, $needle), 'missing lease schema field: ' . $needle);
}
foreach (['credential', 'password', 'line_id', 'material', 'provider_ip', 'provider_port'] as $forbidden) {
    assertUltraLeaseSchema(
        preg_match('/\b' . preg_quote($forbidden, '/') . '\b/i', $source) !== 1,
        'secret field leaked: ' . $forbidden
    );
}
assertUltraLeaseSchema(str_contains($source, 'uniq_ultra_lease_nonce'), 'nonce uniqueness missing');
assertUltraLeaseSchema(str_contains($source, 'idx_ultra_lease_generation'), 'generation index missing');
foreach (['billing_cycle_traffic_used_bytes', 'traffic_suspended', 'traffic_limit', 'queueLifecycleTasks'] as $needle) {
    assertUltraLeaseSchema(str_contains($provisioning, $needle), 'usage accounting contract missing: ' . $needle);
}
echo "ok\n";
