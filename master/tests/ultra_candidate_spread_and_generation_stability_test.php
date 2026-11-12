<?php
declare(strict_types=1);

/**
 * Two production defects seen on an OpenWrt router (client 47, forwards
 * 265/292/322/333), driven through the real broker, material and reputation
 * services:
 *
 * A. Every lease batch carried four candidates but only two lines: the first
 *    line's three relays (speed_ip, test_speed_ip, a fail-over IP, one shared
 *    credential and candidate_key_hash) took three of the four slots, so the
 *    runtime's failover mostly retried the line that had just failed.
 *    Candidates are now spread line-first-relay-first.
 *
 * B. policy_generation changed every 4-8 minutes all night, at a different
 *    time for every forward, and every change made the runtime re-probe its
 *    candidates. It hashed the per-acquisition material fingerprint (a new
 *    credential on every line refresh) and the reputation rows' state,
 *    quarantine_until and backoff_level (healthy <-> probation). It now moves
 *    only when the relay paths, the line scope, candidate eligibility or the
 *    forward's own policy change.
 */

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
use Leikwan\Master\Support\LeigodTokenBroker;
use Leikwan\Master\Support\UltraCandidateReputationService;
use Leikwan\Master\Support\UltraMaterialService;

function failSpread(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertSpread(bool $condition, string $message): void
{
    if (!$condition) {
        failSpread($message);
    }
}

$now = 1_900_200_000;
$envPath = tempnam(sys_get_temp_dir(), 'lw-ultra-spread-env-');
if ($envPath === false) {
    failSpread('failed to create env file');
}
file_put_contents(
    $envPath,
    "ULTRA_TOKEN_KEY=token-key-for-spread-tests\n"
    . "ULTRA_MATERIAL_MAX_TTL_SECONDS=2100\n"
    . "ULTRA_CANDIDATE_QUARANTINE_ENABLED=1\n"
    . "ULTRA_SOURCE_CREDENTIALS_JSON={\"source-a\":\"source-a-secret\"}\n"
);
$config = new Config($envPath);
$clock = static function () use (&$now): int {
    return $now;
};
$reputation = new UltraCandidateReputationService(null, $clock);
$materials = new UltraMaterialService($config, null, null, $clock, $reputation);
$broker = new LeigodTokenBroker($config, null, $materials, $clock);

$relaysOf = [
    41 => [['ip' => '203.0.113.41', 'port' => 5438], ['ip' => '203.0.113.42', 'port' => 5438], ['ip' => '203.0.113.43', 'port' => 5440]],
    916 => [['ip' => '198.51.100.91', 'port' => 5438], ['ip' => '198.51.100.92', 'port' => 5438], ['ip' => '198.51.100.93', 'port' => 5440]],
    77 => [['ip' => '192.0.2.77', 'port' => 6000]],
];
$acquisitions = 0;
$acquire = static function (int $lineId, ?array $relays = null) use ($materials, &$now, &$acquisitions, $relaysOf): void {
    $acquisitions++;
    $ticket = $materials->issueUploadTicket('source-a', [$lineId]);
    $accepted = $materials->acceptSourceUpload('source-a', $ticket, [[
        'line_id' => $lineId,
        // The source hashes user, credential and relays: a new value per acquisition.
        'generation' => hash('sha256', $lineId . ':' . $acquisitions),
        'acquired_at' => gmdate('Y-m-d\\TH:i:s\\Z', $now),
        'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', $now + 1800),
        // LeiGod rotates both the user and the token by time window.
        'user' => 'line-user-' . $lineId . '-' . $acquisitions,
        'credential' => 'credential-' . str_pad((string) $acquisitions, 12, '0', STR_PAD_LEFT),
        'relays' => $relays ?? $relaysOf[$lineId],
    ]]);
    assertSpread($accepted === [$lineId], 'line ' . $lineId . ' material was not accepted');
};

$line = static fn(int $lineId, string $ingress = 'fuzhou'): array => [
    'line_id' => $lineId,
    'source_id' => 'source-a',
    'ingress_zone' => $ingress,
    'destination_zone' => 'taiwan',
    'access_class' => 'native_udp',
    'native_udp_state' => 'verified',
    'native_socks5_state' => 'unknown',
];
$forward = [
    'forward_id' => 292,
    'service_id' => 9,
    'service_status' => 'active',
    'backend_mode' => 'ultra',
    'target' => '198.51.100.10:443',
    'transport' => 'tcp+udp',
    'speed_limit_mbps' => 0,
    'service_expire_at' => '2035-01-01 00:00:00',
    '_ultra_lines' => [$line(41), $line(916)],
    '_ultra_destination_zone' => 'taiwan',
    '_ultra_fallback_policy' => 'detected_then_any',
];
$grantFor = static function (array $forward, string $device = 'router-47') use ($broker): array {
    $grant = $broker->grantsForClientForwards(47, $device, [$forward], true)[(string) $forward['forward_id']] ?? null;
    assertSpread(is_array($grant), 'forward ' . $forward['forward_id'] . ' did not receive a grant');
    return $grant;
};
$batchFor = static function (array $grant, int $forwardId, string $device = 'router-47', int $max = 4) use ($materials): array {
    // Mirrors ClientAgentController::ultraLease(): walk offsets until the
    // batch holds max distinct endpoint+selection pairs.
    $batch = [];
    $seen = [];
    for ($offset = 0; $offset < 8 && count($batch) < $max; $offset++) {
        $candidate = $materials->serverCandidateForClientToken(
            (string) $grant['session_ticket'],
            47,
            $device,
            $forwardId,
            (string) $grant['policy_generation'],
            $offset
        );
        if ($candidate === []) {
            break;
        }
        $key = $candidate['ip'] . ':' . $candidate['port'] . ':' . $candidate['selection_ref'];
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $batch[] = $candidate;
        }
    }
    return $batch;
};

// ---------------------------------------------------------------- Defect A
$acquire(41);
$acquire(916);
$grant = $grantFor($forward);
$batch = $batchFor($grant, 292);
assertSpread(count($batch) === 4, 'a two-line forward must still fill a four-candidate batch');
assertSpread(
    array_map(static fn(array $c): string => $c['ip'], $batch) === ['203.0.113.41', '198.51.100.91', '203.0.113.42', '198.51.100.92'],
    'candidates must alternate lines, primary relay first: ' . implode(',', array_map(static fn(array $c): string => $c['ip'], $batch))
);
$hashCounts = array_count_values(array_map(static fn(array $c): string => $c['candidate_key_hash'], $batch));
assertSpread(count($hashCounts) === 2 && max($hashCounts) === 2, 'one line must not take more than half of a two-line batch');
assertSpread(count(array_unique(array_map(static fn(array $c): string => $c['selection_ref'], $batch))) === 4, 'every relay keeps its own selection reference');

// Uneven relay counts: every line gets its primary relay into the batch
// before any line gets a second one.
$threeLine = $forward;
$threeLine['forward_id'] = 322;
$threeLine['_ultra_lines'] = [$line(77), $line(41), $line(916)];
$acquire(77);
$threeBatch = $batchFor($grantFor($threeLine), 322);
assertSpread(
    array_map(static fn(array $c): string => $c['ip'], $threeBatch) === ['192.0.2.77', '203.0.113.41', '198.51.100.91', '203.0.113.42'],
    'uneven relay counts must still give every line its primary relay first'
);

// A line awaiting a credential_rejected refresh still goes behind the
// healthy ones, whole.
$now += 400;
assertSpread($materials->noteCredentialRejected('source-a', 41), 'credential rejection was not recorded');
$rejectedBatch = $batchFor($grantFor($forward), 292, 'router-47', 6);
assertSpread(
    array_map(static fn(array $c): string => $c['ip'], $rejectedBatch)
        === ['198.51.100.91', '198.51.100.92', '198.51.100.93', '203.0.113.41', '203.0.113.42', '203.0.113.43'],
    'a rejected line must sit behind every healthy relay'
);
$acquire(41); // the refresh clears the mark

// ---------------------------------------------------------------- Defect B
$now += 30;
$baseGrant = $grantFor($forward);
$baseGeneration = (string) $baseGrant['policy_generation'];
$baseRefs = array_map(static fn(array $c): string => $c['selection_ref'], $batchFor($baseGrant, 292));

// A night of ordinary refreshes: each line re-acquired every ~25 minutes at
// its own moment (new user, new credential, same relays), canary successes
// from many observers in between, a client pulling every four minutes.
$generations = [$baseGeneration => true];
$probeCounter = 0;
for ($minute = 1; $minute <= 180; $minute++) {
    $now += 60;
    if ($minute % 25 === 3) {
        $acquire(41);
    }
    if ($minute % 25 === 16) {
        $acquire(916);
    }
    foreach ([41, 916] as $lineId) {
        $probeCounter++;
        $reputation->observeSuccess(
            ['source_id' => 'source-a', 'line_id' => $lineId, 'ingress_zone' => 'fuzhou', 'destination_zone' => 'taiwan', 'protocol' => 'tcp+udp'],
            'observer-' . ($probeCounter % 9),
            'canary-' . $probeCounter
        );
    }
    if ($minute % 4 === 0) {
        $generations[(string) $grantFor($forward)['policy_generation']] = true;
    }
}
assertSpread(count($generations) === 1, 'ordinary refreshes and canary probes rotated the generation ' . (count($generations) - 1) . ' times');

// Across all those ticket re-mints and credential/user rotations the runtime
// can still prove its selected path by reference.
$nightGrant = $grantFor($forward);
assertSpread($nightGrant['session_ticket'] !== $baseGrant['session_ticket'], 'the grant must have been re-minted overnight');
$nightBatch = $batchFor($nightGrant, 292);
assertSpread(
    array_map(static fn(array $c): string => $c['selection_ref'], $nightBatch) === $baseRefs,
    'selection references must survive ticket re-mints and credential refreshes'
);
assertSpread(str_starts_with((string) $nightBatch[0]['user'], 'line-user-41-'), 'the refreshed user must be served');
assertSpread($nightBatch[0]['user'] !== $batch[0]['user'], 'the lease must carry the current credential, not the first one');
$otherDevice = $batchFor($grantFor($forward, 'router-48'), 292, 'router-48');
assertSpread($otherDevice[0]['selection_ref'] !== $nightBatch[0]['selection_ref'], 'selection references must stay device scoped');

// Probation is not eligibility: two independent failures leave it serving.
$scope41 = ['source_id' => 'source-a', 'line_id' => 41, 'ingress_zone' => 'fuzhou', 'destination_zone' => 'taiwan', 'protocol' => 'tcp+udp'];
$reputation->observeFailure($scope41, 'relay_connect_timeout', 'observer-x', 'f-1');
$probation = $reputation->observeFailure($scope41, 'relay_connect_timeout', 'observer-y', 'f-2');
assertSpread(($probation['state'] ?? '') === 'probation', 'two failures must put the line on probation');
assertSpread($grantFor($forward)['policy_generation'] === $baseGeneration, 'probation rotated the generation');

// What must still rotate it. A runtime refuses a generation it has already
// left (ErrPolicyRollback), so every rotation must also be a value the forward
// never had before, even when the state itself returns to an earlier one.
$history = [$baseGeneration];
$rotate = static function (string $label, ?array $changed = null) use (&$history, $grantFor, $forward): string {
    $generation = (string) $grantFor($changed ?? $forward)['policy_generation'];
    assertSpread($generation !== end($history), 'a ' . $label . ' must rotate the generation');
    assertSpread(!in_array($generation, $history, true), 'a ' . $label . ' brought back a generation the runtime already left');
    $history[] = $generation;
    // Nothing else changed: the next pull holds the new generation.
    assertSpread((string) $grantFor($changed ?? $forward)['policy_generation'] === $generation, 'the generation after a ' . $label . ' is not stable');
    return $generation;
};

$quarantined = $reputation->observeFailure($scope41, 'relay_connect_timeout', 'observer-z', 'f-3');
assertSpread(($quarantined['state'] ?? '') === 'quarantined', 'three independent failures must quarantine the line');
$rotate('quarantine entry');
$now += 3601;
// (the material ran out meanwhile; ordinary re-acquisitions)
$acquire(41);
$acquire(916);
$released = $rotate('quarantine exit');
$acquire(41);
$acquire(916);
assertSpread((string) $grantFor($forward)['policy_generation'] === $released, 'credential refreshes after quarantine rotated the generation');

$acquire(41, [['ip' => '203.0.113.41', 'port' => 5438], ['ip' => '203.0.113.44', 'port' => 5438]]);
$rotate('changed relay endpoint');
$acquire(41, [['ip' => '203.0.113.44', 'port' => 5438], ['ip' => '203.0.113.41', 'port' => 5438]]);
$rotate('reordered relay list (moves offset 0)');
$acquire(41);
$rotate('relay list restored to an earlier value');

$changes = [
    'target change' => ['target', '198.51.100.11:443'],
    'transport change' => ['transport', 'udp'],
    'rate change' => ['speed_limit_mbps', 100],
    'service expiry change' => ['service_expire_at', '2036-01-01 00:00:00'],
    'line set change' => ['_ultra_lines', [$line(41)]],
    'line order change' => ['_ultra_lines', [$line(916), $line(41)]],
    'line zone change' => ['_ultra_lines', [$line(41, 'xiamen'), $line(916)]],
];
foreach ($changes as $label => [$field, $value]) {
    $changed = $forward;
    $changed[$field] = $value;
    $rotate($label, $changed);
    $rotate($label . ' reverted');
}

// A line whose material lapsed is no longer offered: that is a path change,
// and so is its return.
$now += 1801;
$acquire(916);
$rotate('lapsed line');
$acquire(41);
$rotate('re-acquired line');

// The grace for a client still leasing on the generation before a path
// change keeps working with the serial (the ticket carries it).
$beforePathChange = $grantFor($forward);
$now += 30;
$acquire(916, [['ip' => '198.51.100.99', 'port' => 5438]]);
$presented = $forward;
$presented['_ultra_presented'] = [
    'session_ticket' => (string) $beforePathChange['session_ticket'],
    'policy_generation' => (string) $beforePathChange['policy_generation'],
];
$afterPathChange = $grantFor($presented);
assertSpread($afterPathChange['policy_generation'] !== $beforePathChange['policy_generation'], 'the path change did not rotate the generation');
assertSpread(
    ($afterPathChange['previous_generation_grace']['policy_generation'] ?? '') === $beforePathChange['policy_generation'],
    'a path-only change must still grant the previous generation a grace'
);

// The v1 capability grant still carries material itself, so its generation
// keeps following every acquisition.
$legacyForward = $forward;
$legacyForward['forward_id'] = 333;
$legacyBefore = $broker->grantsForClientForwards(47, 'linux-v1', [$legacyForward], false)['333'] ?? [];
$now += 1300; // past the legacy renew_after
$acquire(41);
$legacyAfter = $broker->grantsForClientForwards(47, 'linux-v1', [$legacyForward], false)['333'] ?? [];
assertSpread(
    ($legacyBefore['generation'] ?? '') !== '' && ($legacyBefore['generation'] ?? '') !== ($legacyAfter['generation'] ?? ''),
    'the v1 capability generation must still follow the material'
);

@unlink($envPath);
echo "ok\n";
