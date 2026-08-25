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
use Leikwan\Master\Support\LeigodTokenBroker;
use Leikwan\Master\Support\UltraMaterialService;
use Leikwan\Master\Support\UltraMode;

function failUltraContract(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertUltraContract(bool $condition, string $message): void
{
    if (!$condition) {
        failUltraContract($message);
    }
}

$now = 1_893_456_000;
$envPath = tempnam(sys_get_temp_dir(), 'lw-ultra-env-');
if ($envPath === false) {
    failUltraContract('failed to create test files');
}
file_put_contents(
    $envPath,
    "ULTRA_TOKEN_KEY=token-key-for-tests\n"
    . "ULTRA_MAX_TTL_SECONDS=1800\n"
    . "ULTRA_REFRESH_SKEW_SECONDS=600\n"
    . "ULTRA_MATERIAL_MAX_TTL_SECONDS=2100\n"
);

$config = new Config($envPath);
$materials = new UltraMaterialService($config, null, null, static fn(): int => $now);

$ticket = $materials->issueUploadTicket('source-a', [453]);
$accepted = $materials->acceptSourceUpload('source-a', $ticket, [[
    'line_id' => 453,
    'generation' => 'material-generation-1',
    'acquired_at' => gmdate('Y-m-d\\TH:i:s\\Z', $now),
    'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', $now + 1200),
    'user' => 'line-user',
    'credential' => '1234567890123456',
    'relays' => [
        ['ip' => '203.0.113.20', 'port' => 5438],
        ['ip' => '203.0.113.21', 'port' => 5440],
    ],
    'probe' => ['ok' => true],
]]);
assertUltraContract($accepted === [453], 'fresh source material was not accepted');

$broker = new LeigodTokenBroker($config, null, $materials, static fn(): int => $now);
$grants = $broker->grantsForClientForwards(7, 'device-a', [[
    'forward_id' => 42,
    'service_id' => 5,
    'service_status' => 'active',
    'backend_mode' => 'ultra',
    'target' => '198.51.100.10:443',
    'transport' => 'tcp+udp',
    'speed_limit_mbps' => 20,
    '_ultra_line_ids' => [453],
    '_ultra_lines' => [[
        'ultra_line_id' => 10,
        'line_id' => 453,
        'source_id' => 'source-a',
        'ingress_zone' => 'shanghai',
        'destination_zone' => 'tokyo',
        'sort_order' => 10,
    ]],
    '_ultra_line_direction' => 'Shanghai to Japan',
    '_ultra_destination_zone' => 'tokyo',
    '_ultra_allowed_ingress_zones' => ['shanghai', 'beijing'],
    '_ultra_fallback_policy' => 'detected_then_any',
], [
    'forward_id' => 43,
    'service_status' => 'active',
    'backend_mode' => 'current_mode',
]]);

$grant = $grants['42'] ?? null;
assertUltraContract(is_array($grant), 'active ultra forward must receive a local grant');
assertUltraContract(!isset($grants['43']), 'current_mode forward must not receive an ultra grant');
assertUltraContract(($grant['protocol'] ?? '') === UltraMode::POLICY_PROTOCOL, 'grant protocol mismatch');
assertUltraContract(trim((string) ($grant['session_ticket'] ?? '')) !== '', 'policy grant ticket missing');
assertUltraContract(preg_match('/^[a-f0-9]{64}$/', (string) ($grant['policy_generation'] ?? '')) === 1, 'policy generation missing');
assertUltraContract(($grant['quota_remaining_bytes'] ?? 0) > 0, 'policy quota missing');
assertUltraContract(preg_match('/^[a-f0-9]{64}$/', (string) ($grant['target_digest'] ?? '')) === 1, 'target digest missing');
assertUltraContract(!array_key_exists('line_ips', $grant), 'policy grant exposed line IPs');
assertUltraContract(!array_key_exists('gateway_ips', $grant), 'legacy gateway metadata must not survive');
assertUltraContract(!array_key_exists('port', $grant), 'normal grant must not expose a business port');
assertUltraContract(!array_key_exists('line_ids', $grant), 'normal grant must not expose line numbers');
assertUltraContract(!str_contains(strtolower(json_encode($grant) ?: ''), 'credential'), 'normal grant exposed line material');

$quotaForward = [
    'forward_id' => 44,
    'service_id' => 5,
    'service_status' => 'active',
    'backend_mode' => 'ultra',
    'target' => '198.51.100.11:443',
    'transport' => 'tcp',
    'speed_limit_mbps' => 20,
    'quota_remaining_bytes' => 1073741824,
    '_ultra_line_ids' => [453],
    '_ultra_lines' => [[
        'line_id' => 453,
        'ingress_zone' => 'shanghai',
        'destination_zone' => 'tokyo',
    ]],
];
$quotaGrant = $broker->grantsForClientForwards(7, 'device-quota', [$quotaForward]);
assertUltraContract(isset($quotaGrant['44']), 'quota canary grant was not issued');
$quotaForward['quota_remaining_bytes'] = 0;
$exhaustedGrant = $broker->grantsForClientForwards(7, 'device-quota', [$quotaForward]);
assertUltraContract(!isset($exhaustedGrant['44']), 'cached grant survived an exhausted Panel quota');

$expiryForward = $quotaForward;
$expiryForward['quota_remaining_bytes'] = 1073741824;
$expiryForward['service_expire_at'] = gmdate('Y-m-d H:i:s', $now - 1);
$expiredGrant = $broker->grantsForClientForwards(7, 'device-expiry', [$expiryForward]);
assertUltraContract(!isset($expiredGrant['44']), 'expired Panel service still received an Ultra grant');

$candidate = $materials->serverCandidateForClientToken(
    (string) $grant['session_ticket'],
    7,
    'device-a',
    42,
    (string) $grant['policy_generation']
);
assertUltraContract(($candidate['ip'] ?? '') === '203.0.113.20', 'server candidate ordering changed');
assertUltraContract(($candidate['port'] ?? 0) === 5438, 'server candidate port missing');
assertUltraContract(($candidate['user'] ?? '') === 'line-user', 'server candidate user missing');
assertUltraContract(($candidate['credential'] ?? '') === '1234567890123456', 'server candidate credential missing');
assertUltraContract(preg_match('/^[A-Za-z0-9_-]{16,128}$/', (string) ($candidate['selection_ref'] ?? '')) === 1, 'opaque selection reference missing');
assertUltraContract(!array_key_exists('line_id', $candidate), 'server candidate exposed line number');
assertUltraContract(
    $materials->serverCandidateForClientToken((string) $grant['session_ticket'], 7, 'wrong-device', 42, (string) $grant['policy_generation']) === [],
    'token must be device scoped'
);
assertUltraContract(
    $materials->serverCandidateForClientToken((string) $grant['session_ticket'], 7, 'device-a', 99, (string) $grant['policy_generation']) === [],
    'token must be forward scoped'
);

$bootstrap = (string) file_get_contents(__DIR__ . '/../src/Bootstrap.php');
$controller = (string) file_get_contents(__DIR__ . '/../src/Controllers/ClientAgentController.php');
$sourceController = (string) file_get_contents(__DIR__ . '/../src/Controllers/UltraSourceController.php');
foreach ([
    "'/api/client/ultra/materials'",
    "'/api/ultra/source/jobs'",
    "'/api/ultra/source/materials'",
] as $route) {
    assertUltraContract(str_contains($bootstrap, $route), 'integrated panel route missing: ' . $route);
}
assertUltraContract(str_contains($controller, 'resolveUltraMaterials'), 'client material controller integration missing');
assertUltraContract(str_contains($controller, 'resolveUltraLease'), 'client lease controller integration missing');
assertUltraContract(str_contains($sourceController, 'verifySourceRequest'), 'source request signature verification missing');
assertUltraContract(!str_contains($bootstrap, '18443'), 'panel must not introduce an independent Ultra port');
assertUltraContract(UltraMode::isBackendMode('ultra'), 'ultra mode constant mismatch');
assertUltraContract(!UltraMode::isBackendMode('leigod_mode'), 'legacy mode must remain separate');
assertUltraContract(!UltraMode::isBackendMode('current_mode'), 'current mode must remain separate');

@unlink($envPath);
echo "ok\n";
