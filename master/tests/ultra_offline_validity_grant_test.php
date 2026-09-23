<?php
declare(strict_types=1);

/*
 * W1 (control-plane outage survival): long policy-v2 grants for clients that
 * send X-Leikwan-Ultra-Offline-Validity. Covers the header parse, the legacy
 * path staying bit-for-bit, validity / renew_after / clamps, material and
 * service caps, grant-cache separation, generation stability, quota slices,
 * the lease ceiling and the controller wiring.
 */

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
use Leikwan\Master\Controllers\ClientAgentController;
use Leikwan\Master\Support\LeigodTokenBroker;
use Leikwan\Master\Support\LeigodTokenProvider;
use Leikwan\Master\Support\UltraLeaseService;
use Leikwan\Master\Support\UltraMaterialService;
use Leikwan\Master\Support\UltraMode;
use Leikwan\Master\Support\UltraOfflineValidityTokenProvider;

function failOffline(string $message): never
{
    fwrite(STDERR, 'FAIL ' . $message . PHP_EOL);
    exit(1);
}

function assertOffline(bool $condition, string $message): void
{
    if (!$condition) {
        failOffline($message);
    }
}

const OV_GIB = 1073741824;

/** @var list<string> $ovEnvFiles */
$ovEnvFiles = [];

function ovConfig(array $env): Config
{
    global $ovEnvFiles;
    $path = tempnam(sys_get_temp_dir(), 'lw-ultra-ov-');
    if ($path === false) {
        failOffline('failed to create env file');
    }
    $lines = ['ULTRA_TOKEN_KEY=token-key-for-offline-validity-tests'];
    foreach ($env as $key => $value) {
        $lines[] = $key . '=' . $value;
    }
    file_put_contents($path, implode("\n", $lines) . "\n");
    $ovEnvFiles[] = $path;
    return new Config($path);
}

/**
 * @return array{0: LeigodTokenBroker, 1: UltraMaterialService}
 */
function ovBroker(Config $config, callable $clock, int $materialTtl): array
{
    $materials = new UltraMaterialService($config, null, null, $clock);
    $now = (int) $clock();
    $ticket = $materials->issueUploadTicket('source-a', [453]);
    $accepted = $materials->acceptSourceUpload('source-a', $ticket, [[
        'line_id' => 453,
        'generation' => 'material-generation-' . $materialTtl,
        'acquired_at' => gmdate('Y-m-d\\TH:i:s\\Z', $now),
        'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', $now + $materialTtl),
        'user' => 'line-user',
        'credential' => '1234567890123456',
        'relays' => [['ip' => '203.0.113.20', 'port' => 5438]],
        'probe' => ['ok' => true],
    ]]);
    assertOffline($accepted === [453], 'test material was not accepted (ttl ' . $materialTtl . ')');
    return [new LeigodTokenBroker($config, null, $materials, $clock), $materials];
}

/** @param array<string,mixed> $overrides @return array<int, array<string,mixed>> */
function ovForwards(array $overrides = []): array
{
    return [array_merge([
        'forward_id' => 42,
        'service_id' => 5,
        'service_status' => 'active',
        'backend_mode' => 'ultra',
        'target' => '198.51.100.10:443',
        'transport' => 'tcp+udp',
        'speed_limit_mbps' => 20,
        '_ultra_line_ids' => [453],
        '_ultra_lines' => [[
            'line_id' => 453,
            'source_id' => 'source-a',
            'ingress_zone' => 'shanghai',
            'destination_zone' => 'tokyo',
        ]],
        '_ultra_line_direction' => 'Shanghai to Japan',
        '_ultra_destination_zone' => 'tokyo',
        '_ultra_allowed_ingress_zones' => ['shanghai'],
        '_ultra_fallback_policy' => 'detected_then_any',
    ], $overrides)];
}

function ovTs(array $grant, string $field): int
{
    $value = strtotime((string) ($grant[$field] ?? ''));
    if ($value === false) {
        failOffline('grant field ' . $field . ' is not a timestamp');
    }
    return $value;
}

/** @return array<string,mixed> */
function ovGrant(LeigodTokenBroker $broker, int $header, array $overrides = [], string $device = 'device-a'): array
{
    $grants = $broker->grantsForClientForwardsWithOfflineValidity(7, $device, ovForwards($overrides), true, $header);
    $grant = $grants['42'] ?? null;
    if (!is_array($grant)) {
        failOffline('no grant issued (header ' . $header . ')');
    }
    return $grant;
}

$now = 1_893_456_000;
$clock = static function () use (&$now): int {
    return $now;
};

// ---------------------------------------------------------------- header parse
foreach ([
    [null, 0], ['', 0], ['299', 0], ['300', 300], ['1800', 1800], [' 1800 ', 1800],
    ['3600', 3600], ['3601', 0], ['0300', 0], ['+1800', 0], ['-1800', 0], ['1800.0', 0],
    ['1800s', 0], ['1e3', 0], ['18000', 0], ['0', 0], ['1800,1800', 0], ["1800\n1", 0],
] as [$raw, $expected]) {
    assertOffline(
        UltraMode::parseOfflineValidityHeader($raw) === $expected,
        'header ' . var_export($raw, true) . ' should parse to ' . $expected
    );
}

// --------------------------------------------------------- validity ceiling
$capCases = [
    [[], 1800, 1800],
    [[], 3600, 1800],
    [[], 900, 900],
    [['ULTRA_OFFLINE_VALIDITY_SECONDS' => '3600'], 3600, 3600],
    [['ULTRA_OFFLINE_VALIDITY_SECONDS' => '99999'], 3600, 3600],
    [['ULTRA_OFFLINE_VALIDITY_SECONDS' => '100'], 1800, 300],
    [['ULTRA_OFFLINE_VALIDITY_SECONDS' => '0'], 1800, 0],
    [['ULTRA_OFFLINE_VALIDITY_SECONDS' => 'nonsense'], 1800, 1800],
    [['ULTRA_OFFLINE_VALIDITY_SECONDS' => ''], 1800, 1800],
    [[], 0, 0],
    [[], 200, 0],
];
foreach ($capCases as [$env, $header, $expected]) {
    assertOffline(
        UltraMode::offlineValidityCapSeconds(ovConfig($env), $header) === $expected,
        'validity cap for env ' . json_encode($env) . ' header ' . $header . ' should be ' . $expected
    );
}

// ------------------------------------------- legacy path: exactly as before
$config = ovConfig([]);
[$broker] = ovBroker($config, $clock, 2000);
$legacy = $broker->grantsForClientForwards(7, 'device-a', ovForwards(), true)['42'] ?? null;
assertOffline(is_array($legacy), 'legacy grant missing');
assertOffline(ovTs($legacy, 'expires_at') === $now + UltraMode::MAX_POLICY_TTL_SECONDS, 'legacy expires_at changed');
assertOffline(ovTs($legacy, 'renew_after') === $now + 240, 'legacy renew_after changed');
assertOffline((int) $legacy['quota_remaining_bytes'] === UltraMode::MAX_QUOTA_LEASE_BYTES, 'legacy quota must be the full remaining quota');

// A zero header through the new entry point is the legacy call, cache included.
[$brokerZero] = ovBroker($config, $clock, 2000);
$zero = ovGrant($brokerZero, 0);
foreach (['expires_at', 'renew_after', 'quota_remaining_bytes', 'policy_generation', 'rate_limit_mbps', 'transport', 'target_digest'] as $field) {
    assertOffline(($zero[$field] ?? null) === ($legacy[$field] ?? null), 'header-absent grant differs from legacy in ' . $field);
}
assertOffline(array_keys($zero) === array_keys($legacy), 'header-absent grant has a different field set');

// The legacy knobs keep working on the legacy path only.
[$brokerKnobs] = ovBroker(ovConfig(['ULTRA_MAX_TTL_SECONDS' => '120', 'ULTRA_REFRESH_SKEW_SECONDS' => '45']), $clock, 2000);
$knobs = $brokerKnobs->grantsForClientForwards(7, 'device-a', ovForwards(), true)['42'];
assertOffline(ovTs($knobs, 'expires_at') === $now + 120, 'legacy ULTRA_MAX_TTL_SECONDS no longer honoured');
assertOffline(ovTs($knobs, 'renew_after') === $now + 75, 'legacy ULTRA_REFRESH_SKEW_SECONDS no longer honoured');
$knobsLong = ovGrant($brokerKnobs, 1800);
assertOffline(ovTs($knobsLong, 'expires_at') === $now + 1800, 'legacy TTL knob must not cap the long path');

// Header ignored by an operator kill switch.
[$brokerOff] = ovBroker(ovConfig(['ULTRA_OFFLINE_VALIDITY_SECONDS' => '0']), $clock, 2000);
$off = ovGrant($brokerOff, 1800);
assertOffline(ovTs($off, 'expires_at') === $now + 300, 'ULTRA_OFFLINE_VALIDITY_SECONDS=0 must fall back to short grants');

// ------------------------------------------------ long path: 1800 / 240
[$brokerLong] = ovBroker($config, $clock, 2000);
$long = ovGrant($brokerLong, 1800);
assertOffline(ovTs($long, 'expires_at') === $now + 1800, 'long grant must be valid 1800 s');
assertOffline(ovTs($long, 'renew_after') === $now + 240, 'long grant must renew after 240 s');
assertOffline(ovTs($long, 'issued_at') === $now, 'long grant issued_at mismatch');
assertOffline($long['policy_generation'] === $legacy['policy_generation'], 'long and short grants must share the policy generation');

// Header above the panel setting is capped by it.
[$brokerHigh] = ovBroker($config, $clock, 2000);
assertOffline(ovTs(ovGrant($brokerHigh, 3600), 'expires_at') === $now + 1800, 'header 3600 must be capped at the default 1800');

// Renew interval knob and its clamps.
[$brokerRenew] = ovBroker(ovConfig(['ULTRA_RENEW_INTERVAL_SECONDS' => '600']), $clock, 2000);
assertOffline(ovTs(ovGrant($brokerRenew, 1800), 'renew_after') === $now + 600, 'ULTRA_RENEW_INTERVAL_SECONDS not honoured');
[$brokerRenewLow] = ovBroker(ovConfig(['ULTRA_RENEW_INTERVAL_SECONDS' => '5']), $clock, 2000);
assertOffline(ovTs(ovGrant($brokerRenewLow, 1800), 'renew_after') === $now + 30, 'renew interval must clamp to >= 30');
[$brokerRenewHigh] = ovBroker(ovConfig(['ULTRA_RENEW_INTERVAL_SECONDS' => '5000']), $clock, 2000);
assertOffline(ovTs(ovGrant($brokerRenewHigh, 1800), 'renew_after') === $now + 1740, 'renew interval must clamp to <= V - 60');
[$brokerRenewBad] = ovBroker(ovConfig(['ULTRA_RENEW_INTERVAL_SECONDS' => 'abc']), $clock, 2000);
assertOffline(ovTs(ovGrant($brokerRenewBad, 1800), 'renew_after') === $now + 240, 'bad renew interval must fall back to 240');

// ------------------------------------------------ material / service caps
[$brokerMat] = ovBroker($config, $clock, 700);
$mat = ovGrant($brokerMat, 1800);
assertOffline(ovTs($mat, 'expires_at') === $now + 700, 'material expiry must cap a long grant');
assertOffline(ovTs($mat, 'renew_after') === $now + 240, 'material-capped grant renews on the normal cadence');
[$brokerMatShort] = ovBroker($config, $clock, 200);
$matShort = ovGrant($brokerMatShort, 1800);
assertOffline(ovTs($matShort, 'expires_at') === $now + 200, 'short material must cap a long grant');
assertOffline(ovTs($matShort, 'renew_after') === $now + 140, 'renew must keep 60 s of slack before a short material expiry');
[$brokerMat3600] = ovBroker(ovConfig(['ULTRA_OFFLINE_VALIDITY_SECONDS' => '3600']), $clock, 2000);
assertOffline(ovTs(ovGrant($brokerMat3600, 3600), 'expires_at') === $now + 2000, 'material expiry must cap a 3600 s grant');
[$brokerSvc] = ovBroker($config, $clock, 2000);
$svc = ovGrant($brokerSvc, 1800, ['service_expire_at' => gmdate('Y-m-d H:i:s', $now + 1000) . ' UTC']);
assertOffline(ovTs($svc, 'expires_at') === $now + 1000, 'service expiry must cap a long grant');
[$brokerSvcGone] = ovBroker($config, $clock, 2000);
$gone = $brokerSvcGone->grantsForClientForwardsWithOfflineValidity(7, 'device-a', ovForwards([
    'service_expire_at' => gmdate('Y-m-d H:i:s', $now - 10) . ' UTC',
]), true, 1800);
assertOffline(!isset($gone['42']), 'an expired service must not receive a long grant');

// --------------------------------------------------- grant-cache separation
[$brokerCache] = ovBroker($config, $clock, 2000);
$cacheMaterialExpiry = $now + 2000;
$short1 = $brokerCache->grantsForClientForwards(7, 'device-a', ovForwards(), true)['42'];
$long1 = ovGrant($brokerCache, 1800);
assertOffline(ovTs($long1, 'expires_at') === $now + 1800, 'a cached short grant was handed to a capable client');
assertOffline($long1['session_ticket'] !== $short1['session_ticket'], 'long grant reused the short ticket');
$now += 10;
$short2 = $brokerCache->grantsForClientForwards(7, 'device-a', ovForwards(), true)['42'];
$long2 = ovGrant($brokerCache, 1800);
assertOffline($short2['session_ticket'] === $short1['session_ticket'], 'short grant cache stopped being reused');
assertOffline(ovTs($short2, 'expires_at') === ovTs($short1, 'expires_at'), 'a long grant leaked into the short cache');
assertOffline($long2['session_ticket'] === $long1['session_ticket'], 'long grant cache is not reused before renew_after');
assertOffline($short2['policy_generation'] === $long2['policy_generation'], 'cache classes produced different generations');
// A different announced validity is a different class.
$long900 = ovGrant($brokerCache, 900);
assertOffline(ovTs($long900, 'expires_at') === $now + 900, 'a 1800 s grant was handed to a client that accepts 900 s');
// Past renew_after the long grant is re-minted with a fresh expiry.
$now += 240;
$long3 = ovGrant($brokerCache, 1800);
assertOffline($long3['session_ticket'] !== $long1['session_ticket'], 'long grant was not renewed after renew_after');
assertOffline(
    ovTs($long3, 'expires_at') === min($now + 1800, $cacheMaterialExpiry),
    'renewed long grant must restart its validity (capped by the ageing material)'
);
assertOffline($long3['policy_generation'] === $long1['policy_generation'], 'renewal without a scope change must keep the generation');
$now -= 250;

// ------------------------------------------------------------ quota slices
$quotaCases = [
    // [env, forward overrides, expected long-grant quota]
    [[], ['quota_remaining_bytes' => 50 * OV_GIB, 'speed_limit_mbps' => 20], 2_500_000 * 1800],
    [[], ['quota_remaining_bytes' => 100 * 1024 * 1024, 'speed_limit_mbps' => 20], 100 * 1024 * 1024],
    [[], ['quota_remaining_bytes' => 50 * OV_GIB, 'speed_limit_mbps' => 1], OV_GIB],
    [[], ['quota_remaining_bytes' => 90 * OV_GIB, 'speed_limit_mbps' => 0], 125_000_000 * 2 * 240],
    [[], ['speed_limit_mbps' => 0], 125_000_000 * 2 * 240],
    [['ULTRA_GRANT_QUOTA_UNLIMITED_RATE_MBPS' => '100'], ['quota_remaining_bytes' => 90 * OV_GIB, 'speed_limit_mbps' => 0], 10 * OV_GIB],
    [['ULTRA_GRANT_QUOTA_SLICE_BYTES' => (string) (2 * OV_GIB)], ['quota_remaining_bytes' => 50 * OV_GIB, 'speed_limit_mbps' => 20], 2 * OV_GIB],
    [['ULTRA_GRANT_QUOTA_SLICE_BYTES' => '1000'], ['quota_remaining_bytes' => 50 * OV_GIB, 'speed_limit_mbps' => 1], OV_GIB],
    // An explicit slice below the renewal floor is raised to the floor.
    [['ULTRA_GRANT_QUOTA_SLICE_BYTES' => (string) OV_GIB], ['quota_remaining_bytes' => 50 * OV_GIB, 'speed_limit_mbps' => 100], 12_500_000 * 2 * 240],
    [['ULTRA_GRANT_QUOTA_SLICE_ENABLED' => '0'], ['quota_remaining_bytes' => 50 * OV_GIB, 'speed_limit_mbps' => 20], 50 * OV_GIB],
];
foreach ($quotaCases as $index => [$env, $overrides, $expected]) {
    $caseConfig = ovConfig($env);
    [$caseBroker] = ovBroker($caseConfig, $clock, 2000);
    $longCase = ovGrant($caseBroker, 1800, $overrides);
    assertOffline(
        (int) $longCase['quota_remaining_bytes'] === $expected,
        'quota case ' . $index . ': expected ' . $expected . ', got ' . (int) $longCase['quota_remaining_bytes']
    );
    [$caseLegacyBroker] = ovBroker($caseConfig, $clock, 2000);
    $shortCase = $caseLegacyBroker->grantsForClientForwards(7, 'device-a', ovForwards($overrides), true)['42'];
    $fullQuota = (int) ($overrides['quota_remaining_bytes'] ?? UltraMode::MAX_QUOTA_LEASE_BYTES);
    assertOffline((int) $shortCase['quota_remaining_bytes'] === $fullQuota, 'quota case ' . $index . ': legacy grant must keep the full quota');
    assertOffline($shortCase['policy_generation'] === $longCase['policy_generation'], 'quota case ' . $index . ': slice changed the generation');
    // Renewal safety: the slice outlasts two renewal intervals at the forward's rate.
    $rate = (int) ($overrides['speed_limit_mbps'] ?? 0);
    if ($rate > 0 && $expected < $fullQuota) {
        assertOffline((int) $longCase['quota_remaining_bytes'] >= $rate * 125000 * 480, 'quota case ' . $index . ': slice can run out before a renewal');
    }
}

// A renewal re-slices from the reconciled remaining quota.
[$brokerReslice] = ovBroker($config, $clock, 2000);
$before = ovGrant($brokerReslice, 1800, ['quota_remaining_bytes' => 50 * OV_GIB, 'speed_limit_mbps' => 20]);
$after = ovGrant($brokerReslice, 1800, ['quota_remaining_bytes' => 3 * OV_GIB, 'speed_limit_mbps' => 20]);
assertOffline((int) $before['quota_remaining_bytes'] === 2_500_000 * 1800, 'first slice mismatch');
assertOffline((int) $after['quota_remaining_bytes'] === 3 * OV_GIB, 'renewal must re-slice from the reconciled remaining quota');
assertOffline($after['session_ticket'] !== $before['session_ticket'], 'a quota change must mint a fresh grant');
$exhausted = $brokerReslice->grantsForClientForwardsWithOfflineValidity(7, 'device-a', ovForwards([
    'quota_remaining_bytes' => 0,
]), true, 1800);
assertOffline(!isset($exhausted['42']), 'an exhausted quota must not receive a long grant');

// ------------------------------------------------------------ lease ceiling
$generation = str_repeat('c', 64);
$leasePolicy = [
    'forward_id' => 42,
    'device_hash' => hash('sha256', 'device-a'),
    'service_state' => 'active',
    'policy_generation' => $generation,
    'target' => '198.51.100.10:443',
    'transport' => 'tcp',
    'rate_limit_mbps' => 20,
    'quota_remaining_bytes' => 4 * OV_GIB,
    'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', $now + 1800),
    'renew_after' => gmdate('Y-m-d\\TH:i:s\\Z', $now + 240),
];
$leases = new UltraLeaseService(
    $config,
    null,
    null,
    static fn(array $request, int $clientId, string $deviceId): array => [
        'selection_ref' => str_repeat('r', 43),
        'ip' => '203.0.113.20',
        'port' => 5438,
        'user' => 'line-user',
        'credential' => 'line-password',
    ],
    $clock,
);
$nonceCounter = 0;
$leaseFor = static function (array $policy) use ($leases, $generation, &$nonceCounter): array {
    $nonceCounter++;
    $result = $leases->issueLease([
        'forward_id' => 42,
        'session_ticket' => 'ticket-a',
        'policy_generation' => $generation,
        'nonce' => hash('sha256', 'nonce-' . $nonceCounter),
        'target_digest' => hash('sha256', '198.51.100.10:443'),
        'transport' => 'tcp',
    ], 7, 'device-a', $policy);
    if (($result['ok'] ?? false) !== true) {
        failOffline('lease refused: ' . (string) ($result['error'] ?? ''));
    }
    return $result['lease'];
};
$shortLease = $leaseFor($leasePolicy);
assertOffline(strtotime($shortLease['expires_at']) === $now + UltraMode::MAX_POLICY_TTL_SECONDS, 'lease without offline validity must stay <= 300 s');
$longLease = $leaseFor($leasePolicy + ['offline_validity_seconds' => 1800]);
assertOffline(strtotime($longLease['expires_at']) === $now + 1800, 'long lease must be valid 1800 s');
assertOffline(($longLease['policy_expires_at'] ?? '') === $leasePolicy['expires_at'], 'long lease lost policy_expires_at');
assertOffline(($longLease['policy_renew_after'] ?? '') === $leasePolicy['renew_after'], 'long lease lost policy_renew_after');
assertOffline((int) $longLease['quota_remaining_bytes'] === 4 * OV_GIB, 'lease must carry the grant slice');
$cappedLease = $leaseFor(array_merge($leasePolicy, [
    'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', $now + 700),
    'offline_validity_seconds' => 1800,
]));
assertOffline(strtotime($cappedLease['expires_at']) === $now + 700, 'lease must never outlive its policy');
$bogusLease = $leaseFor($leasePolicy + ['offline_validity_seconds' => 99999]);
assertOffline(strtotime($bogusLease['expires_at']) === $now + 300, 'out-of-range offline validity must fall back to the short lease');

// ------------------------------------------------------- controller wiring
final class OfflineRecordingProvider implements UltraOfflineValidityTokenProvider
{
    public string $called = '';
    public int $validity = -1;

    public function grantsForClientForwards(int $clientId, string $deviceId, array $forwards, bool $policyV2 = false): array
    {
        $this->called = 'legacy';
        return [];
    }

    public function grantsForClientForwardsWithOfflineValidity(int $clientId, string $deviceId, array $forwards, bool $policyV2, int $offlineValiditySeconds): array
    {
        $this->called = 'offline';
        $this->validity = $offlineValiditySeconds;
        return [];
    }
}

final class ShortOnlyProvider implements LeigodTokenProvider
{
    public string $called = '';

    public function grantsForClientForwards(int $clientId, string $deviceId, array $forwards, bool $policyV2 = false): array
    {
        $this->called = 'legacy';
        return [];
    }
}

$reflection = new ReflectionClass(ClientAgentController::class);
$attach = new ReflectionMethod(ClientAgentController::class, 'attachUltraAccessGrants');
foreach ([[1800, 'offline'], [0, 'legacy']] as [$validity, $expectedCall]) {
    $provider = new OfflineRecordingProvider();
    $controller = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('config')->setValue($controller, $config);
    $reflection->getProperty('ultraTokenProvider')->setValue($controller, $provider);
    $attach->invoke($controller, ovForwards(), 7, 'device-a', true, $validity);
    assertOffline($provider->called === $expectedCall, 'controller called the ' . $provider->called . ' grant path for validity ' . $validity);
    if ($validity > 0) {
        assertOffline($provider->validity === $validity, 'controller did not pass the parsed header through');
    }
}
$shortOnly = new ShortOnlyProvider();
$controller = $reflection->newInstanceWithoutConstructor();
$reflection->getProperty('config')->setValue($controller, $config);
$reflection->getProperty('ultraTokenProvider')->setValue($controller, $shortOnly);
$attach->invoke($controller, ovForwards(), 7, 'device-a', true, 1800);
assertOffline($shortOnly->called === 'legacy', 'a provider without long-grant support must keep serving short grants');

$source = (string) file_get_contents(__DIR__ . '/../src/Controllers/ClientAgentController.php');
$pullStart = strpos($source, 'public function pullConfigs');
$leaseStart = strpos($source, 'public function resolveUltraLease');
assertOffline($pullStart !== false && $leaseStart !== false, 'controller entry points not found');
$pullBody = substr($source, $pullStart, 6000);
$leaseBody = substr($source, $leaseStart, 9000);
assertOffline(str_contains($pullBody, 'UltraMode::OFFLINE_VALIDITY_HEADER'), 'config pull does not read the offline-validity header');
assertOffline(str_contains($leaseBody, 'UltraMode::OFFLINE_VALIDITY_HEADER'), 'lease endpoint does not read the offline-validity header');
assertOffline(str_contains($leaseBody, "\$policy['offline_validity_seconds']"), 'lease endpoint does not bound the lease by the offline validity');
assertOffline(UltraMode::MIN_POLICY_TTL_SECONDS === 60 && UltraMode::MAX_POLICY_TTL_SECONDS === 300, 'legacy TTL constants changed');

foreach ($ovEnvFiles as $path) {
    @unlink($path);
}
echo "ok\n";
