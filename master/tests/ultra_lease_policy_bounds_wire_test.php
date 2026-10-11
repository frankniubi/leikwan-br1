<?php
declare(strict_types=1);
spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (str_starts_with($class, $prefix)) {
        require_once __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});
$type = new ReflectionClass(Leikwan\Master\Support\UltraLeaseService::class);
$service = $type->newInstanceWithoutConstructor();
$method = $type->getMethod('scrubLease');
$lease = [
    'forward_id' => 170, 'lease_id' => 'test', 'policy_generation' => str_repeat('a', 64),
    'expires_at' => '2026-09-23T00:05:00Z', 'policy_expires_at' => '2026-09-23T00:05:00Z',
    'policy_renew_after' => '2026-09-23T00:04:00Z', 'transport' => 'tcp+udp',
    'rate_limit_mbps' => 20, 'quota_remaining_bytes' => 1000000, 'selection_ref' => 'test-selection',
    'endpoint' => ['ip' => '127.0.0.1', 'port' => 8000],
    'credential' => ['user' => 'test', 'password' => 'test'],
];
$wire = $method->invoke($service, $lease);
foreach (['policy_expires_at', 'policy_renew_after'] as $key) {
    if (($wire[$key] ?? null) !== $lease[$key]) {
        throw new RuntimeException('wire scrub lost ' . $key);
    }
}
$lease['policy_renew_after'] = '';
$wire = $method->invoke($service, $lease);
if (isset($wire['policy_renew_after'])) {
    throw new RuntimeException('absent timestamps must not be serialized as invalid empty dates');
}
echo "PASS renewed policy bounds survive final wire scrub\n";
