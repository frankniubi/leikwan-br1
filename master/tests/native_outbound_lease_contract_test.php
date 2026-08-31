<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use Leikwan\Master\Config;
use Leikwan\Master\Support\UltraLeaseService;
use Leikwan\Master\Support\UltraMode;

function failNativeLease(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertNativeLease(bool $condition, string $message): void
{
    if (!$condition) {
        failNativeLease($message);
    }
}

$envPath = tempnam(sys_get_temp_dir(), 'lw-native-lease-');
if ($envPath === false) {
    failNativeLease('failed to create env file');
}
file_put_contents($envPath, "ULTRA_TOKEN_KEY=test-key\n");
$config = new Config($envPath);
$now = 1_900_000_000;
$generation = str_repeat('a', 64);
$direction = 'Hong Kong to Mainland China';
$routeScope = 'global';
$transport = 'tcp+udp';
$forwardId = 158;
$nativeTarget = implode("\n", [
    'native-outbound:v1',
    $forwardId,
    strtolower($direction),
    $transport,
    $routeScope,
]);
$request = [
    'purpose' => UltraMode::NATIVE_OUTBOUND_PURPOSE,
    'forward_id' => $forwardId,
    'session_ticket' => 'ticket-native',
    'policy_generation' => $generation,
    'nonce' => 'abcdef0123456789abcdef0123456789',
    'target_digest' => UltraMode::nativeScopeDigest($forwardId, $direction, $transport, $routeScope),
    'transport' => $transport,
    'route_scope' => $routeScope,
    'selection_mode' => 'single',
    'max_candidates' => 1,
];
$candidateState = 'verified';
$service = new UltraLeaseService(
    $config,
    null,
    null,
    static function (array $candidateRequest, int $clientId, string $deviceId) use (&$candidateState): array {
        return [
            'selection_ref' => str_repeat('r', 43),
            'ip' => '203.0.113.20',
            'port' => 5438,
            'user' => 'lease-user',
            'credential' => 'lease-password',
            'native_socks5_state' => $candidateState,
        ];
    },
    static function () use ($now): int {
        return $now;
    },
    static function (int $clientId, string $deviceId, int $requestedForwardId) use ($forwardId, $generation, $nativeTarget, $direction, $routeScope, $transport, $now): array {
        return [
            'forward_id' => $forwardId,
            'device_hash' => hash('sha256', 'device-native'),
            'service_state' => 'active',
            'policy_generation' => $generation,
            'target' => $nativeTarget,
            'transport' => $transport,
            'direction' => $direction,
            'route_scope' => $routeScope,
            'rate_limit_mbps' => 20,
            'quota_remaining_bytes' => 1024 * 1024,
            'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', $now + 300),
        ];
    },
);

$issued = $service->issueLease($request, 7, 'device-native');
assertNativeLease(($issued['ok'] ?? false) === true, 'valid native lease must issue');
$lease = is_array($issued['lease'] ?? null) ? $issued['lease'] : [];
assertNativeLease(($lease['protocol'] ?? '') === UltraMode::NATIVE_SOCKS5_PROTOCOL, 'native protocol mismatch');
assertNativeLease(($lease['purpose'] ?? '') === UltraMode::NATIVE_OUTBOUND_PURPOSE, 'native purpose missing');
assertNativeLease(($lease['direction'] ?? '') === $direction, 'native direction missing');
assertNativeLease(($lease['route_scope'] ?? '') === $routeScope, 'native route scope missing');
assertNativeLease(($lease['scope_digest'] ?? '') === $request['target_digest'], 'native scope digest missing');
assertNativeLease(($lease['endpoint']['ip'] ?? '') === '203.0.113.20', 'native endpoint missing');
assertNativeLease(($lease['credential']['user'] ?? '') === 'lease-user', 'native credential missing');
assertNativeLease(!array_key_exists('source_id', $lease) && !array_key_exists('line_id', $lease), 'native lease exposed inventory identifiers');
assertNativeLease(!str_contains((string) json_encode($lease), 'leigod'), 'native lease exposed provider name');
assertNativeLease(strtotime((string) ($lease['expires_at'] ?? '')) <= $now + UltraMode::MAX_POLICY_TTL_SECONDS, 'native TTL exceeded Ultra bound');

$replay = $service->issueLease($request, 7, 'device-native');
assertNativeLease(($replay['error'] ?? '') === 'lease_replayed', 'native nonce replay was not rejected');

$candidateState = 'unknown';
$unknown = $service->issueLease(array_merge($request, [
    'nonce' => 'abcdef0123456789abcdef01234539',
]), 7, 'device-native');
assertNativeLease(($unknown['error'] ?? '') === 'no_approved_candidate', 'unknown SOCKS5 candidate was accepted');

$wrongDigest = $service->issueLease(array_merge($request, [
    'nonce' => 'abcdef0123456789abcdef01234540',
    'target_digest' => UltraMode::nativeScopeDigest($forwardId, $direction, $transport, 'cn_only'),
]), 7, 'device-native');
assertNativeLease(($wrongDigest['error'] ?? '') === 'policy_scope_invalid', 'wrong native scope digest was accepted');

assertNativeLease(
    UltraMode::nativeRouteScopesForDirection($direction) === ['cn_only', 'global'],
    'international-to-mainland direction did not expose both scopes'
);
assertNativeLease(
    UltraMode::nativeRouteScopesForDirection('Shanghai to Japan') === ['global'],
    'non-mainland direction exposed cn_only'
);

@unlink($envPath);
echo "ok\n";
