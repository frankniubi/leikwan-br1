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
use Leikwan\Master\Support\UltraCandidateReputationService;
use Leikwan\Master\Support\UltraMaterialService;

function failUltraCandidateResolution(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertUltraCandidateResolution(bool $condition, string $message): void
{
    if (!$condition) {
        failUltraCandidateResolution($message);
    }
}

$now = 1_900_000_000;
$envPath = tempnam(sys_get_temp_dir(), 'lw-ultra-quarantine-env-');
if ($envPath === false) {
    failUltraCandidateResolution('failed to create env file');
}
file_put_contents(
    $envPath,
    "ULTRA_TOKEN_KEY=token-key-for-quarantine-tests\n"
    . "ULTRA_SOURCE_ID_KEY=source-a\n"
    . "ULTRA_CANDIDATE_QUARANTINE_ENABLED=1\n"
    . "ULTRA_MATERIAL_MAX_TTL_SECONDS=2100\n"
);
$config = new Config($envPath);
$clock = static function () use (&$now): int {
    return $now;
};
$reputation = new UltraCandidateReputationService(null, $clock);
$materials = new UltraMaterialService($config, null, null, $clock, $reputation);

foreach ([347, 197] as $lineId) {
    $ticket = $materials->issueUploadTicket('source-a', [$lineId]);
    $accepted = $materials->acceptSourceUpload('source-a', $ticket, [[
        'line_id' => $lineId,
        'generation' => 'material-' . $lineId,
        'acquired_at' => gmdate('Y-m-d\\TH:i:s\\Z', $now),
        'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', $now + 1200),
        'user' => 'line-user-' . $lineId,
        'credential' => 'line-password-' . $lineId,
        'relays' => [['ip' => '203.0.113.' . ($lineId === 347 ? '47' : '97'), 'port' => 5438]],
    ]]);
    assertUltraCandidateResolution($accepted === [$lineId], 'material was not accepted for line ' . $lineId);
}

$generation = str_repeat('a', 64);
$token = $materials->issueClientToken([
    'client_id' => 7,
    'device_hash' => hash('sha256', 'device-a'),
    'forward_id' => 42,
    'policy_generation' => $generation,
    'transport' => 'tcp',
    'destination_zone' => 'hong-kong',
    'lines' => [
        ['source_id' => 'source-a', 'line_id' => 347, 'ingress_zone' => 'guangzhou', 'destination_zone' => 'hong-kong'],
        ['source_id' => 'source-a', 'line_id' => 197, 'ingress_zone' => 'guangzhou', 'destination_zone' => 'hong-kong'],
    ],
], $now + 1200);
assertUltraCandidateResolution($token !== '', 'client token was not issued');

$scope = [
    'source_id' => 'source-a',
    'line_id' => 347,
    'ingress_zone' => 'guangzhou',
    'destination_zone' => 'hong-kong',
    'protocol' => 'tcp',
];
$reputation->observeFailure($scope, 'relay_connect_timeout', 'observer-a', 'failure-1');
$reputation->observeFailure($scope, 'relay_connect_timeout', 'observer-a', 'failure-2');
$fingerprintBefore = $materials->candidateReputationFingerprint([
    ['source_id' => 'source-a', 'line_id' => 347, 'ingress_zone' => 'guangzhou', 'destination_zone' => 'hong-kong'],
    ['source_id' => 'source-a', 'line_id' => 197, 'ingress_zone' => 'guangzhou', 'destination_zone' => 'hong-kong'],
], 'tcp');
$quarantined = $reputation->observeFailure($scope, 'relay_connect_timeout', 'observer-b', 'failure-3');
assertUltraCandidateResolution(($quarantined['state'] ?? '') === 'quarantined', 'test candidate did not quarantine');
$fingerprintAfter = $materials->candidateReputationFingerprint([
    ['source_id' => 'source-a', 'line_id' => 347, 'ingress_zone' => 'guangzhou', 'destination_zone' => 'hong-kong'],
    ['source_id' => 'source-a', 'line_id' => 197, 'ingress_zone' => 'guangzhou', 'destination_zone' => 'hong-kong'],
], 'tcp');
assertUltraCandidateResolution($fingerprintBefore !== $fingerprintAfter, 'quarantine did not rotate candidate reputation fingerprint');

$candidate = $materials->serverCandidateForClientToken($token, 7, 'device-a', 42, $generation, 0);
assertUltraCandidateResolution(($candidate['ip'] ?? '') === '203.0.113.97', 'quarantined candidate was still issued first');
assertUltraCandidateResolution(($candidate['candidate_key_hash'] ?? '') !== '', 'candidate reputation identity was not carried internally');
assertUltraCandidateResolution(!array_key_exists('line_id', $candidate), 'candidate leaked a raw line ID');

$disabledEnvPath = tempnam(sys_get_temp_dir(), 'lw-ultra-quarantine-disabled-');
if ($disabledEnvPath === false) {
    failUltraCandidateResolution('failed to create disabled env file');
}
file_put_contents(
    $disabledEnvPath,
    "ULTRA_TOKEN_KEY=token-key-for-quarantine-tests\n"
    . "ULTRA_SOURCE_ID_KEY=source-a\n"
    . "ULTRA_CANDIDATE_QUARANTINE_ENABLED=0\n"
    . "ULTRA_MATERIAL_MAX_TTL_SECONDS=2100\n"
);
$disabledMaterials = new UltraMaterialService(new Config($disabledEnvPath), null, null, $clock, $reputation);
$disabledTicket = $disabledMaterials->issueUploadTicket('source-a', [347]);
$disabledMaterials->acceptSourceUpload('source-a', $disabledTicket, [[
    'line_id' => 347,
    'generation' => 'material-347-disabled',
    'acquired_at' => gmdate('Y-m-d\\TH:i:s\\Z', $now),
    'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', $now + 1200),
    'user' => 'line-user-347',
    'credential' => 'line-password-347',
    'relays' => [['ip' => '203.0.113.47', 'port' => 5438]],
]]);
$disabledToken = $disabledMaterials->issueClientToken([
    'client_id' => 7,
    'device_hash' => hash('sha256', 'device-a'),
    'forward_id' => 43,
    'policy_generation' => $generation,
    'transport' => 'tcp',
    'destination_zone' => 'hong-kong',
    'lines' => [['source_id' => 'source-a', 'line_id' => 347, 'ingress_zone' => 'guangzhou', 'destination_zone' => 'hong-kong']],
], $now + 1200);
$disabledCandidate = $disabledMaterials->serverCandidateForClientToken($disabledToken, 7, 'device-a', 43, $generation, 0);
assertUltraCandidateResolution(($disabledCandidate['ip'] ?? '') === '203.0.113.47', 'feature flag off changed legacy candidate ordering');

$shadowTicket = $disabledMaterials->issueUploadTicket('source-a', [555]);
$disabledMaterials->acceptSourceUpload('source-a', $shadowTicket, [[
    'line_id' => 555,
    'generation' => 'material-555-shadow',
    'acquired_at' => gmdate('Y-m-d\\TH:i:s\\Z', $now),
    'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', $now + 1200),
    'user' => 'line-user-555',
    'credential' => 'line-password-555',
    'relays' => [['ip' => '203.0.113.55', 'port' => 5438]],
]]);
$shadowToken = $disabledMaterials->issueClientToken([
    'client_id' => 7,
    'device_hash' => hash('sha256', 'device-a'),
    'forward_id' => 44,
    'policy_generation' => $generation,
    'transport' => 'tcp',
    'destination_zone' => 'hong-kong',
    'lines' => [['source_id' => 'source-a', 'line_id' => 555, 'ingress_zone' => 'guangzhou', 'destination_zone' => 'hong-kong']],
], $now + 1200);
$shadowCandidate = $disabledMaterials->serverCandidateForClientToken($shadowToken, 7, 'device-a', 44, $generation, 0);
assertUltraCandidateResolution(($shadowCandidate['candidate_key_hash'] ?? '') !== '', 'shadow candidate did not receive an opaque identity');
$shadowRows = array_filter(
    $reputation->latest(32),
    static fn(array $row): bool => (int) ($row['line_id'] ?? 0) === 555
        && (string) ($row['destination_zone'] ?? '') === 'hong-kong'
);
assertUltraCandidateResolution($shadowRows !== [], 'shadow mode did not register the candidate scope');

@unlink($envPath);
@unlink($disabledEnvPath);
echo "ok\n";
