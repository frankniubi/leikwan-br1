<?php
declare(strict_types=1);

/**
 * Sealed Ultra leases (docs/specs/2026-10-02-ultra-sealed-lease-v1.md).
 *
 * Pins the panel half of the protocol: the spec's test vector, header
 * validation, which lease fields move into the envelope, tamper detection,
 * the ULTRA_LEASE_SEALING modes, the one-live-key-per-device pin and the
 * retired /api/client/ultra/materials endpoint.
 *
 * Needs the openssl and pdo_sqlite extensions, e.g. on Windows:
 *   php -d extension=openssl -d extension=pdo_sqlite master/tests/ultra_lease_sealing_test.php
 * (with OPENSSL_CONF pointing at an openssl.cnf for EC key generation).
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (str_starts_with($class, $prefix)) {
        require_once __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

use Leikwan\Master\Config;
use Leikwan\Master\Controllers\ClientAgentController;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Http\Request;
use Leikwan\Master\Support\ApiException;
use Leikwan\Master\Support\UltraLeaseSealer;

if (!function_exists('openssl_pkey_derive') || !class_exists(PDO::class) || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "ultra_lease_sealing_test: needs the openssl and pdo_sqlite extensions\n");
    exit(1);
}

$failures = 0;
$checks = 0;
function check(bool $condition, string $label): void
{
    global $failures, $checks;
    $checks++;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL $label\n");
    }
}

function expectApiError(callable $call, string $code, int $status, string $label): void
{
    try {
        $call();
        check(false, $label . ' (no exception)');
    } catch (ApiException $exception) {
        check($exception->getMessage() === $code && $exception->statusCode() === $status, $label . ' (' . $exception->getMessage() . ')');
    }
}

// 1. The spec vector opens to exactly the expected plaintext.
$vectorPem = "-----BEGIN PRIVATE KEY-----\nMIGHAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBG0wawIBAQQgiZhHiQ9fMsUxJrIt\nxybSkKIuY/3Cu8bdLwY2hRmeXfuhRANCAASQeYuYC08uD+/nil206s2P/mDVSSih\nGroDsdeJyQLhB604v4GPV63sgkZ7lwhPOhHIKataU210RkNAQj3yB4Sb\n-----END PRIVATE KEY-----\n";
$spec = (string) file_get_contents(__DIR__ . '/../../docs/specs/2026-10-02-ultra-sealed-lease-v1.md');
check(preg_match('/```json\n(\{\n  "device_private_scalar_hex".*?\n\})\n```/s', $spec, $match) === 1, 'spec carries the test vector');
$vector = json_decode($match[1] ?? '{}', true);
$vectorPublic = UltraLeaseSealer::parsePublicKey((string) ($vector['device_public_b64url'] ?? ''));
check($vectorPublic !== null, 'vector public key parses');
check(UltraLeaseSealer::keyId((string) $vectorPublic) === ($vector['lease']['sealed']['kid'] ?? null), 'vector kid matches');
check(
    UltraLeaseSealer::open($vector['lease']['sealed'] ?? [], $vectorPem, (string) ($vector['lease']['lease_id'] ?? '')) === ($vector['expected_plaintext'] ?? null),
    'vector opens to the expected plaintext'
);
check(UltraLeaseSealer::open($vector['lease']['sealed'] ?? [], $vectorPem, 'ul2_other') === null, 'vector bound to its lease_id');

// 2. Header validation.
$device = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
openssl_pkey_export($device, $devicePem);
$details = openssl_pkey_get_details($device);
$pad = static fn(string $value): string => str_pad($value, 32, "\0", STR_PAD_LEFT);
$devicePublic = "\x04" . $pad($details['ec']['x']) . $pad($details['ec']['y']);
$header = UltraLeaseSealer::base64UrlEncode($devicePublic);
check(UltraLeaseSealer::parsePublicKey($header) === $devicePublic, 'unpadded base64url key parses');
$offCurve = $devicePublic;
$offCurve[64] = chr(ord($offCurve[64]) ^ 0x01);
foreach ([
    'empty' => '',
    'garbage' => 'not a key!',
    'compressed point' => UltraLeaseSealer::base64UrlEncode("\x02" . substr($devicePublic, 1, 32)),
    'short point' => UltraLeaseSealer::base64UrlEncode(substr($devicePublic, 0, 64)),
    'off-curve point' => UltraLeaseSealer::base64UrlEncode($offCurve),
    'oversized' => str_repeat('A', 200),
] as $label => $value) {
    check(UltraLeaseSealer::parsePublicKey($value) === null, 'rejects ' . $label);
}

// 3. sealLease moves exactly the provider material into the envelope.
$lease = [
    'lease_id' => 'ul2_abc',
    'forward_id' => 9,
    'expires_at' => '2026-10-02T12:00:00Z',
    'endpoint' => ['ip' => '203.0.113.7', 'port' => 40123],
    'credential' => ['user' => 'lg-user', 'password' => 'lg-secret'],
    'external_uot' => ['enabled' => true, 'sidecar_target' => '198.51.100.9:7443', 'token' => 'sidecar-token'],
    'direct_ip_cidrs' => [],
];
$sealed = UltraLeaseSealer::sealLease($lease, $devicePublic);
$wire = json_encode($sealed);
foreach (['203.0.113.7', 'lg-user', 'lg-secret', 'sidecar-token', '"endpoint"', '"credential"', '"external_uot"'] as $needle) {
    check(!str_contains((string) $wire, $needle), 'sealed lease does not carry ' . $needle);
}
check($sealed['lease_id'] === 'ul2_abc' && $sealed['forward_id'] === 9 && $sealed['expires_at'] === $lease['expires_at'], 'scope fields stay in clear');
check($sealed['sealed']['v'] === 1 && $sealed['sealed']['alg'] === UltraLeaseSealer::ALGORITHM, 'envelope version and algorithm');
check($sealed['sealed']['kid'] === UltraLeaseSealer::keyId($devicePublic), 'envelope kid');
$opened = json_decode((string) UltraLeaseSealer::open($sealed['sealed'], $devicePem, 'ul2_abc'), true);
check($opened === ['endpoint' => $lease['endpoint'], 'credential' => $lease['credential'], 'external_uot' => $lease['external_uot']], 'envelope round-trips');
$tampered = $sealed['sealed'];
$blob = UltraLeaseSealer::base64UrlDecode($tampered['ct']);
$blob[0] = chr(ord($blob[0]) ^ 0x80);
$tampered['ct'] = UltraLeaseSealer::base64UrlEncode($blob);
check(UltraLeaseSealer::open($tampered, $devicePem, 'ul2_abc') === null, 'tampered ciphertext refused');
openssl_pkey_export(openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']), $otherPem);
check(UltraLeaseSealer::open($sealed['sealed'], $otherPem, 'ul2_abc') === null, 'another device key cannot open it');
$second = UltraLeaseSealer::sealLease($lease, $devicePublic);
check($second['sealed']['epk'] !== $sealed['sealed']['epk'] && $second['sealed']['iv'] !== $sealed['sealed']['iv'], 'every seal uses a fresh ephemeral key and iv');

// 4. Controller: modes, pin and conflict handling against a real (sqlite) session row.
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE client_device_sessions (client_id INTEGER, device_id TEXT, first_seen_at TEXT NULL, ultra_lease_key_sha256 TEXT NULL, ultra_lease_key_seen_at TEXT NULL)');
$pdo->exec("INSERT INTO client_device_sessions (client_id, device_id, first_seen_at) VALUES (7, 'device-a', '2026-09-01 00:00:00')");
$config = new Config(__DIR__ . '/does-not-exist.env');
$db = new Database($config);
$db->setPdo($pdo);
$class = new ReflectionClass(ClientAgentController::class);
$controller = $class->newInstanceWithoutConstructor();
foreach (['config' => $config, 'db' => $db] as $name => $value) {
    $property = new ReflectionProperty(\Leikwan\Master\Controllers\BaseController::class, $name);
    $property->setValue($controller, $value);
}
$sealKey = $class->getMethod('ultraLeaseSealKey');
$request = static fn(?string $key): Request => new Request(
    'POST',
    '/api/client/ultra/lease',
    [],
    [],
    $key === null ? [] : ['X-Leikwan-Lease-Key' => $key],
    ''
);
$pinned = static fn(): array => $pdo->query("SELECT ultra_lease_key_sha256, ultra_lease_key_seen_at FROM client_device_sessions WHERE device_id = 'device-a'")->fetch(PDO::FETCH_ASSOC);

putenv('ULTRA_LEASE_SEALING');
check($sealKey->invoke($controller, $request(null), 7, 'device-a') === null, 'optional: no header means a clear response');
check($sealKey->invoke($controller, $request($header), 7, 'device-a') === $devicePublic, 'optional: valid header seals');
check(($pinned()['ultra_lease_key_sha256'] ?? null) === UltraLeaseSealer::keyHash($devicePublic), 'first key is pinned');
expectApiError(static fn() => $sealKey->invoke($controller, $request('bad key'), 7, 'device-a'), 'lease_key_invalid', 400, 'malformed key refused');

openssl_pkey_export($thief = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']), $thiefPem);
$thiefDetails = openssl_pkey_get_details($thief);
$thiefPublic = "\x04" . $pad($thiefDetails['ec']['x']) . $pad($thiefDetails['ec']['y']);
$thiefHeader = UltraLeaseSealer::base64UrlEncode($thiefPublic);
check($sealKey->invoke($controller, $request($thiefHeader), 7, 'device-a') === $thiefPublic, 'optional: a conflicting key is only reported');
check(($pinned()['ultra_lease_key_sha256'] ?? null) === UltraLeaseSealer::keyHash($devicePublic), 'optional: a conflicting key does not steal the live pin');

putenv('ULTRA_LEASE_SEALING=require');
expectApiError(static fn() => $sealKey->invoke($controller, $request(null), 7, 'device-a'), 'lease_sealing_required', 409, 'require: missing header refused');
expectApiError(static fn() => $sealKey->invoke($controller, $request($thiefHeader), 7, 'device-a'), 'lease_device_key_conflict', 409, 'require: conflicting live key refused');
check($sealKey->invoke($controller, $request($header), 7, 'device-a') === $devicePublic, 'require: the pinned key keeps working');

$pdo->exec("UPDATE client_device_sessions SET ultra_lease_key_seen_at = '" . gmdate('Y-m-d H:i:s', time() - 3600) . "'");
check($sealKey->invoke($controller, $request($thiefHeader), 7, 'device-a') === $thiefPublic, 'require: an idle pin can be replaced');
check(($pinned()['ultra_lease_key_sha256'] ?? null) === UltraLeaseSealer::keyHash($thiefPublic), 'require: replacement is pinned');
check($sealKey->invoke($controller, $request($header), 7, 'device-unknown') === $devicePublic, 'no session row: never blocks');

// "auto": devices that predate the cut-over stay clear until they pin a key;
// a pinned device and any device first seen after the cut-over must seal.
putenv('ULTRA_LEASE_SEALING=auto');
putenv('ULTRA_LEASE_SEALING_NEW_DEVICES_SINCE=2026-10-02 13:35:00');
$pdo->exec("INSERT INTO client_device_sessions (client_id, device_id, first_seen_at) VALUES (7, 'old-keyless', '2026-09-20 08:00:00'), (7, 'new-keyless', '2026-10-02 14:00:00')");
check($sealKey->invoke($controller, $request(null), 7, 'old-keyless') === null, 'auto: an old keyless device keeps working in clear');
expectApiError(static fn() => $sealKey->invoke($controller, $request(null), 7, 'new-keyless'), 'lease_sealing_required', 409, 'auto: a device first seen after the cut-over must seal');
expectApiError(static fn() => $sealKey->invoke($controller, $request(null), 7, 'device-a'), 'lease_sealing_required', 409, 'auto: a device that pinned a key must keep sealing');
check($sealKey->invoke($controller, $request(null), 7, 'device-unknown') === null, 'auto: no session row never blocks');
$pdo->exec("UPDATE client_device_sessions SET ultra_lease_key_seen_at = '" . gmdate('Y-m-d H:i:s') . "' WHERE device_id = 'device-a'");
expectApiError(static fn() => $sealKey->invoke($controller, $request($header), 7, 'device-a'), 'lease_device_key_conflict', 409, 'auto: a conflicting live key is refused');
putenv('ULTRA_LEASE_SEALING_NEW_DEVICES_SINCE');
check($sealKey->invoke($controller, $request(null), 7, 'new-keyless') === null, 'auto: without a cut-over only pinned devices are held to sealing');

// ULTRA_LEASE_SEALING_EXEMPT_DEVICES: an operator-approved keyless client is
// served in clear, scoped to its own client; malformed entries exempt nothing.
putenv('ULTRA_LEASE_SEALING_NEW_DEVICES_SINCE=2026-10-02 13:35:00');
putenv('ULTRA_LEASE_SEALING_EXEMPT_DEVICES= 7:new-keyless , x7:device-a, 8:');
check($sealKey->invoke($controller, $request(null), 7, 'new-keyless') === null, 'exempt: a listed keyless device is served in clear under auto');
expectApiError(static fn() => $sealKey->invoke($controller, $request(null), 7, 'device-a'), 'lease_sealing_required', 409, 'exempt: a malformed entry exempts nothing');
putenv('ULTRA_LEASE_SEALING=require');
check($sealKey->invoke($controller, $request(null), 7, 'new-keyless') === null, 'exempt: a listed keyless device is served in clear under require');
expectApiError(static fn() => $sealKey->invoke($controller, $request(null), 8, 'new-keyless'), 'lease_sealing_required', 409, 'exempt: the entry is scoped to its own client');
check($sealKey->invoke($controller, $request($thiefHeader), 7, 'new-keyless') === $thiefPublic, 'exempt: a key sent by a listed device is still sealed');
putenv('ULTRA_LEASE_SEALING_EXEMPT_DEVICES');
putenv('ULTRA_LEASE_SEALING_NEW_DEVICES_SINCE');

putenv('ULTRA_LEASE_SEALING=off');
check($sealKey->invoke($controller, $request('bad key'), 7, 'device-a') === null, 'off: header ignored entirely');
putenv('ULTRA_LEASE_SEALING');

// 5. The plaintext materials endpoint is retired before it even authenticates.
$response = $controller->resolveUltraMaterials($request(null), []);
check($response->status() === 410 && str_contains($response->body(), 'ultra_materials_retired'), 'materials endpoint retired');

// 6. The real lease endpoint decides the key before issuing and seals every lease it returns.
$source = (string) file_get_contents(__DIR__ . '/../src/Controllers/ClientAgentController.php');
$keyAt = strpos($source, '$sealKey = $this->ultraLeaseSealKey($request');
$issueAt = strpos($source, '$this->ultraLeases->issueLease($candidateRequest');
check($keyAt !== false && $issueAt !== false && $keyAt < $issueAt, 'device key is checked before any lease nonce is consumed');
check(str_contains($source, '$leases[$index] = UltraLeaseSealer::sealLease($lease, $sealKey);'), 'every returned lease is sealed');

if ($failures > 0) {
    fwrite(STDERR, "ultra_lease_sealing_test: $failures of $checks checks failed\n");
    exit(1);
}
echo "ultra_lease_sealing_test: $checks checks passed\n";
