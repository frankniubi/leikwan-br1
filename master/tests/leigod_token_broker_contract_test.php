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
use Leikwan\Master\Http\Request;
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
    . "ULTRA_SOURCE_CREDENTIALS_JSON={\"source-a\":\"source-a-secret\",\"source-b\":\"source-b-secret\"}\n"
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

$ticketB = $materials->issueUploadTicket('source-b', [453]);
$acceptedB = $materials->acceptSourceUpload('source-b', $ticketB, [[
    'line_id' => 453,
    'generation' => 'material-generation-b',
    'acquired_at' => gmdate('Y-m-d\\TH:i:s\\Z', $now + 1),
    'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', $now + 1201),
    'user' => 'line-user-b',
    'credential' => '2234567890123456',
    'relays' => [['ip' => '198.51.100.20', 'port' => 6438]],
    'probe' => ['ok' => true],
]]);
assertUltraContract($acceptedB === [453], 'second source material was not accepted');
$sourceASnapshot = $materials->lineSnapshot([['source_id' => 'source-a', 'line_id' => 453]]);
$sourceBSnapshot = $materials->lineSnapshot([['source_id' => 'source-b', 'line_id' => 453]]);
assertUltraContract(($sourceASnapshot['line_ips'] ?? []) === ['203.0.113.20', '203.0.113.21'], 'source-qualified material was overwritten');
assertUltraContract(($sourceBSnapshot['line_ips'] ?? []) === ['198.51.100.20'], 'second source material lookup mismatch');

$authBody = json_encode(['source' => 'b'], JSON_UNESCAPED_SLASHES);
$authTimestamp = (string) $now;
$authNonce = str_repeat('b', 32);
$authPath = '/api/ultra/source/jobs';
$authCanonical = implode("\n", ['GET', $authPath, 'source-b', $authTimestamp, $authNonce, hash('sha256', $authBody)]);
$authRequest = new Request('GET', $authPath, [], [], [
    'x-ultra-source-id' => 'source-b',
    'x-ultra-timestamp' => $authTimestamp,
    'x-ultra-nonce' => $authNonce,
    'x-ultra-signature' => hash_hmac('sha256', $authCanonical, 'source-b-secret'),
], $authBody);
assertUltraContract($materials->verifySourceRequest($authRequest) === 'source-b', 'source credential map did not authenticate second source');

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
]], true);

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
$quotaGrant = $broker->grantsForClientForwards(7, 'device-quota', [$quotaForward], true);
assertUltraContract(isset($quotaGrant['44']), 'quota canary grant was not issued');
$quotaForward['quota_remaining_bytes'] = 0;
$exhaustedGrant = $broker->grantsForClientForwards(7, 'device-quota', [$quotaForward], true);
assertUltraContract(!isset($exhaustedGrant['44']), 'cached grant survived an exhausted Panel quota');

$expiryForward = $quotaForward;
$expiryForward['quota_remaining_bytes'] = 1073741824;
$expiryForward['service_expire_at'] = gmdate('Y-m-d H:i:s', $now - 1);
$expiredGrant = $broker->grantsForClientForwards(7, 'device-expiry', [$expiryForward], true);
assertUltraContract(!isset($expiredGrant['44']), 'expired Panel service still received an Ultra grant');

$unlimitedForward = $quotaForward;
$unlimitedForward['forward_id'] = 45;
$unlimitedForward['quota_remaining_bytes'] = null;
$unlimitedGrant = $broker->grantsForClientForwards(7, 'device-unlimited', [$unlimitedForward], true);
assertUltraContract(isset($unlimitedGrant['45']), 'an unspecified quota was treated as exhausted');
assertUltraContract(($unlimitedGrant['45']['quota_remaining_bytes'] ?? 0) > 0, 'unlimited policy grant must receive a bounded lease quota');

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
$selectionRefBeforeRotation = (string) ($candidate['selection_ref'] ?? '');
$rotationTicket = $materials->issueUploadTicket('source-a', [453]);
$rotationAccepted = $materials->acceptSourceUpload('source-a', $rotationTicket, [[
    'line_id' => 453,
    'generation' => 'material-generation-2',
    'acquired_at' => gmdate('Y-m-d\\TH:i:s\\Z', $now + 2),
    'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', $now + 1202),
    'user' => 'line-user',
    'credential' => '3234567890123456',
    'relays' => [
        ['ip' => '203.0.113.20', 'port' => 5438],
        ['ip' => '203.0.113.21', 'port' => 5440],
    ],
    'probe' => ['ok' => true],
]]);
assertUltraContract($rotationAccepted === [453], 'rotated source material was not accepted');
$rotatedCandidate = $materials->serverCandidateForClientToken(
    (string) $grant['session_ticket'],
    7,
    'device-a',
    42,
    (string) $grant['policy_generation']
);
assertUltraContract(($rotatedCandidate['credential'] ?? '') === '3234567890123456', 'rotated source credential was not returned');
assertUltraContract(
    ($rotatedCandidate['selection_ref'] ?? '') === $selectionRefBeforeRotation,
    'selection reference changed across credential generation rotation'
);
assertUltraContract(
    $materials->serverCandidateForClientToken((string) $grant['session_ticket'], 7, 'wrong-device', 42, (string) $grant['policy_generation']) === [],
    'token must be device scoped'
);
assertUltraContract(
    $materials->serverCandidateForClientToken((string) $grant['session_ticket'], 7, 'device-a', 99, (string) $grant['policy_generation']) === [],
    'token must be forward scoped'
);

$policyGrants = $broker->grantsForClientForwards(7, 'windows-device-a', [[
    'forward_id' => 42,
    'service_id' => 5,
    'service_status' => 'active',
    'backend_mode' => 'ultra',
    'target' => '198.51.100.10:443',
    'transport' => 'tcp+udp',
    'speed_limit_mbps' => 20,
    'quota_remaining_bytes' => 1024 * 1024,
    '_ultra_lines' => [[
        'line_id' => 453,
        'source_id' => 'source-a',
        'ingress_zone' => 'shanghai',
        'destination_zone' => 'tokyo',
    ]],
]], true);
$policyGrant = $policyGrants['42'] ?? null;
assertUltraContract(is_array($policyGrant), 'Windows Ultra policy grant missing');
assertUltraContract(($policyGrant['protocol'] ?? '') === UltraMode::POLICY_PROTOCOL, 'Windows policy protocol mismatch');
assertUltraContract(trim((string) ($policyGrant['session_ticket'] ?? '')) !== '', 'Windows policy ticket missing');
assertUltraContract(preg_match('/^[a-f0-9]{64}$/', (string) ($policyGrant['policy_generation'] ?? '')) === 1, 'Windows policy generation missing');
assertUltraContract(($policyGrant['quota_remaining_bytes'] ?? 0) === 1024 * 1024, 'Windows policy quota mismatch');
assertUltraContract(!array_key_exists('line_ips', $policyGrant), 'Windows policy exposed provider IPs');
assertUltraContract(!array_key_exists('token', $policyGrant), 'Windows policy exposed legacy token field');
$candidate = $materials->serverCandidateForClientToken(
    (string) $policyGrant['session_ticket'],
    7,
    'windows-device-a',
    42,
    (string) $policyGrant['policy_generation']
);
assertUltraContract(($candidate['ip'] ?? '') === '203.0.113.20', 'Windows policy candidate did not preserve source priority');
assertUltraContract(($candidate['port'] ?? 0) === 5438, 'Windows policy candidate port missing');
assertUltraContract(($candidate['user'] ?? '') === 'line-user', 'Windows policy candidate user missing');
assertUltraContract(!array_key_exists('line_id', $candidate), 'Windows policy candidate exposed line ID');

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
