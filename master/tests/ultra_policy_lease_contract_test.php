<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', (string) $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use Leikwan\Master\Config;
use Leikwan\Master\Support\UltraLeaseService;
use Leikwan\Master\Support\UltraMode;

function failUltraPolicyLease(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertUltraPolicyLease(bool $condition, string $message): void
{
    if (!$condition) {
        failUltraPolicyLease($message);
    }
}

$envPath = tempnam(sys_get_temp_dir(), 'lw-ultra-policy-');
if ($envPath === false) {
    failUltraPolicyLease('failed to create env file');
}
file_put_contents($envPath, "ULTRA_TOKEN_KEY=test-key\n");
$config = new Config($envPath);
$generation = str_repeat('a', 64);
$now = 1_900_000_000;
$candidateCalls = 0;
$candidateOffsetSeen = null;
$service = new UltraLeaseService(
    $config,
    null,
    null,
    static function (array $request, int $clientId, string $deviceId) use (&$candidateCalls, &$candidateOffsetSeen): array {
        $candidateCalls++;
        $candidateOffsetSeen = $request['candidate_offset'] ?? null;
        return [
            'selection_ref' => str_repeat('r', 43),
            'ip' => '203.0.113.20',
            'port' => 5438,
            'user' => 'line-user',
            'credential' => 'line-password',
        ];
    },
    static fn(): int => $now,
    static function (int $clientId, string $deviceId, int $forwardId) use ($generation): array {
        return [
            'forward_id' => 42,
            'device_hash' => hash('sha256', 'device-a'),
            'service_state' => 'active',
            'policy_generation' => $generation,
            'target' => '198.51.100.10:443',
            'transport' => 'tcp',
            'rate_limit_mbps' => 20,
            'quota_remaining_bytes' => 1024 * 1024,
            'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', 1_900_000_300),
        ];
    },
);

$request = [
    'forward_id' => 42,
    'session_ticket' => 'ticket-a',
    'policy_generation' => $generation,
    'nonce' => 'abcdef0123456789abcdef0123456789',
    'target_digest' => hash('sha256', '198.51.100.10:443'),
    'transport' => 'tcp',
];
$lease = $service->issueLease($request, 7, 'device-a');
assertUltraPolicyLease(($lease['ok'] ?? false) === true, 'valid lease must issue');
assertUltraPolicyLease(($lease['lease']['endpoint']['ip'] ?? '') === '203.0.113.20', 'lease endpoint missing');
assertUltraPolicyLease(($lease['lease']['credential']['user'] ?? '') === 'line-user', 'lease user missing');
assertUltraPolicyLease(($lease['lease']['credential']['password'] ?? '') === 'line-password', 'lease password missing');
assertUltraPolicyLease(
    ($lease['lease']['direct_ip_cidrs'] ?? []) === ['203.0.113.20/32'],
    'lease did not protect the provider endpoint as a direct CIDR'
);
assertUltraPolicyLease(!array_key_exists('line_id', $lease['lease']), 'lease exposed line id');
assertUltraPolicyLease(!array_key_exists('line_ips', $lease['lease']), 'lease exposed line ips');
assertUltraPolicyLease(!array_key_exists('endpoints', $lease['lease']), 'lease exposed endpoint inventory');
assertUltraPolicyLease(!array_key_exists('candidates', $lease['lease']), 'lease exposed candidate inventory');
assertUltraPolicyLease(
    array_keys((array) ($lease['lease']['endpoint'] ?? [])) === ['ip', 'port'],
    'lease must contain exactly one endpoint'
);
assertUltraPolicyLease(($lease['lease']['policy_generation'] ?? '') === $generation, 'generation missing');
assertUltraPolicyLease(
    strtotime((string) ($lease['lease']['expires_at'] ?? '')) <= $now + UltraMode::MAX_POLICY_TTL_SECONDS,
    'lease TTL exceeded five minutes'
);
assertUltraPolicyLease($candidateCalls === 1, 'candidate resolver call count mismatch');

$offsetLease = $service->issueLease(
    array_merge($request, [
        'nonce' => 'abcdef0123456789abcdef01234535',
        'candidate_offset' => 2,
    ]),
    7,
    'device-a'
);
assertUltraPolicyLease(($offsetLease['ok'] ?? false) === true, 'candidate offset lease must issue');
assertUltraPolicyLease($candidateOffsetSeen === 2, 'candidate offset was not passed to resolver');

$replay = $service->issueLease($request, 7, 'device-a');
assertUltraPolicyLease(($replay['error'] ?? '') === 'lease_replayed', 'nonce replay was not rejected');
assertUltraPolicyLease(
    ($service->issueLease(array_merge($request, ['nonce' => 'abcdef0123456789abcdef01234530']), 7, 'wrong-device')['error'] ?? '') === 'lease_scope_invalid',
    'device scope was not rejected'
);
assertUltraPolicyLease(
    ($service->issueLease(array_merge($request, ['forward_id' => 43, 'nonce' => 'abcdef0123456789abcdef01234534']), 7, 'device-a')['error'] ?? '') === 'lease_scope_invalid',
    'forward scope was not rejected'
);
assertUltraPolicyLease(
    ($service->issueLease(array_merge($request, ['nonce' => 'abcdef0123456789abcdef01234531', 'policy_generation' => str_repeat('b', 64)]), 7, 'device-a')['error'] ?? '') === 'policy_generation_invalid',
    'stale generation was not rejected'
);

$inactive = new UltraLeaseService(
    $config,
    null,
    null,
    static fn(array $request, int $clientId, string $deviceId): array => [],
    static fn(): int => $now,
    static fn(int $clientId, string $deviceId, int $forwardId): array => [
        'forward_id' => $forwardId,
        'device_hash' => hash('sha256', $deviceId),
        'service_state' => 'suspended',
        'policy_generation' => $generation,
        'target' => '198.51.100.10:443',
        'transport' => 'tcp',
        'rate_limit_mbps' => 20,
        'quota_remaining_bytes' => 1024,
        'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', 1_900_000_300),
    ],
);
assertUltraPolicyLease(($inactive->issueLease($request, 7, 'device-a')['error'] ?? '') === 'forward_inactive', 'inactive forward was not rejected');

$expired = new UltraLeaseService(
    $config,
    null,
    null,
    static fn(array $request, int $clientId, string $deviceId): array => [],
    static fn(): int => $now,
    static fn(int $clientId, string $deviceId, int $forwardId): array => [
        'forward_id' => $forwardId,
        'device_hash' => hash('sha256', $deviceId),
        'service_state' => 'active',
        'policy_generation' => $generation,
        'target' => '198.51.100.10:443',
        'transport' => 'tcp',
        'rate_limit_mbps' => 20,
        'quota_remaining_bytes' => 1024,
        'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', 1_899_999_999),
    ],
);
assertUltraPolicyLease(($expired->issueLease(array_merge($request, ['nonce' => 'abcdef0123456789abcdef01234532']), 7, 'device-a')['error'] ?? '') === 'policy_expired', 'expired policy was not rejected');

$revoked = new UltraLeaseService(
    $config,
    null,
    null,
    static fn(array $request, int $clientId, string $deviceId): array => [
        'selection_ref' => str_repeat('s', 43),
        'ip' => '203.0.113.21',
        'port' => 5439,
        'user' => 'line-user',
        'credential' => 'line-password',
    ],
    static fn(): int => $now,
    static function (int $clientId, string $deviceId, int $forwardId) use ($generation): array {
        return [
            'forward_id' => $forwardId,
            'device_hash' => hash('sha256', $deviceId),
            'service_state' => 'active',
            'policy_generation' => $generation,
            'target' => '198.51.100.10:443',
            'transport' => 'tcp',
            'rate_limit_mbps' => 20,
            'quota_remaining_bytes' => 1024,
            'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', 1_900_000_300),
        ];
    },
);
assertUltraPolicyLease(($revoked->revokeGeneration(42, $generation)['ok'] ?? false) === true, 'generation revoke failed');
assertUltraPolicyLease(
    ($revoked->issueLease(array_merge($request, ['nonce' => 'abcdef0123456789abcdef01234533']), 7, 'device-a')['error'] ?? '') === 'policy_generation_invalid',
    'revoked generation was not rejected'
);

$broadCidr = new UltraLeaseService(
    $config,
    null,
    null,
    static fn(array $request, int $clientId, string $deviceId): array => [
        'selection_ref' => str_repeat('d', 43),
        'ip' => '203.0.113.22',
        'port' => 5440,
        'user' => 'line-user',
        'credential' => 'line-password',
        'direct_ip_cidrs' => ['0.0.0.0/0'],
    ],
    static fn(): int => $now,
    static function (int $clientId, string $deviceId, int $forwardId) use ($generation): array {
        return [
            'forward_id' => $forwardId,
            'device_hash' => hash('sha256', $deviceId),
            'service_state' => 'active',
            'policy_generation' => $generation,
            'target' => '198.51.100.10:443',
            'transport' => 'tcp',
            'rate_limit_mbps' => 20,
            'quota_remaining_bytes' => 1024,
            'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', 1_900_000_300),
        ];
    },
);
assertUltraPolicyLease(
    ($broadCidr->issueLease(array_merge($request, ['nonce' => 'abcdef0123456789abcdef01234534']), 7, 'device-a')['error'] ?? '') === 'no_approved_candidate',
    'broad direct CIDR was accepted'
);

$source = (string) file_get_contents(__DIR__ . '/../src/Support/UltraLeaseService.php');
foreach (['lease_replayed', 'policy_generation_invalid', 'forward_inactive', 'policy_expired', 'quota_exhausted'] as $needle) {
    assertUltraPolicyLease(str_contains($source, $needle), 'lease error missing: ' . $needle);
}
assertUltraPolicyLease(str_contains($source, 'ultra_client_leases'), 'lease audit table integration missing');
assertUltraPolicyLease(!str_contains($source, 'gateway'), 'lease service introduced gateway semantics');
assertUltraPolicyLease(UltraMode::LEASE_ENDPOINT === '/api/client/ultra/lease', 'lease endpoint constant mismatch');
$controllerSource = (string) file_get_contents(__DIR__ . '/../src/Controllers/ClientAgentController.php');
assertUltraPolicyLease(
    substr_count($controllerSource, "'Cache-Control' => 'no-store, no-cache, must-revalidate'") >= 2,
    'Ultra policy and lease responses must be no-store'
);
assertUltraPolicyLease(str_contains($controllerSource, 'selection_mode'), 'auto selection mode missing');
assertUltraPolicyLease(str_contains($controllerSource, 'max_candidates'), 'auto selection candidate bound missing');
assertUltraPolicyLease(str_contains($controllerSource, 'candidate_offset'), 'auto selection candidate offset missing');

@unlink($envPath);
echo "ok\n";
