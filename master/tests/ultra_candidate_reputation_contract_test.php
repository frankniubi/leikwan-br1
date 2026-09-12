<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Support/UltraCandidateReputationService.php';

use Leikwan\Master\Support\UltraCandidateReputationService;

function failUltraCandidateReputation(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertUltraCandidateReputation(bool $condition, string $message): void
{
    if (!$condition) {
        failUltraCandidateReputation($message);
    }
}

$now = 1_900_000_000;
$service = new UltraCandidateReputationService(null, static function () use (&$now): int {
    return $now;
});
$scope = [
    'source_id' => 'source-a',
    'line_id' => 347,
    'ingress_zone' => 'guangzhou',
    'destination_zone' => 'hong-kong',
    'protocol' => 'tcp',
];

$key = UltraCandidateReputationService::candidateKey($scope);
$keyHash = UltraCandidateReputationService::candidateKeyHash($scope);
assertUltraCandidateReputation($key === 'source-a|347|guangzhou|hong-kong|tcp', 'candidate key is not canonical');
assertUltraCandidateReputation(preg_match('/^[a-f0-9]{64}$/', $keyHash) === 1, 'candidate key hash is not sha256');

$first = $service->observeFailure($scope, 'relay_connect_timeout', 'observer-a', 'evidence-1');
assertUltraCandidateReputation(($first['state'] ?? '') === 'healthy', 'one failure must remain healthy');
assertUltraCandidateReputation(($first['consecutive_failures'] ?? 0) === 1, 'first failure count missing');

$second = $service->observeFailure($scope, 'relay_connect_timeout', 'observer-a', 'evidence-2');
assertUltraCandidateReputation(($second['state'] ?? '') === 'probation', 'two failures must enter probation');

$third = $service->observeFailure($scope, 'relay_connect_timeout', 'observer-b', 'evidence-3');
assertUltraCandidateReputation(($third['state'] ?? '') === 'quarantined', 'corroborated third failure must quarantine');
assertUltraCandidateReputation(($third['independent_observers'] ?? 0) === 2, 'independent observer count missing');
assertUltraCandidateReputation(($third['quarantine_until'] ?? 0) === $now + 3600, 'initial quarantine TTL mismatch');
assertUltraCandidateReputation($service->isQuarantined($scope), 'quarantined candidate was reported eligible');

$duplicate = $service->observeFailure($scope, 'relay_connect_timeout', 'observer-b', 'evidence-3');
assertUltraCandidateReputation(($duplicate['consecutive_failures'] ?? 0) === 3, 'duplicate evidence changed failure count');

$otherScope = $scope;
$otherScope['line_id'] = 197;
$candidates = [
    $scope,
    $otherScope,
];
$filtered = $service->filterCandidates($candidates, 1);
assertUltraCandidateReputation(count($filtered) === 1, 'healthy sibling was not preferred over quarantined candidate');
assertUltraCandidateReputation(($filtered[0]['line_id'] ?? 0) === 197, 'wrong candidate survived quarantine filtering');

$allBad = $service->filterCandidates([$scope], 1);
assertUltraCandidateReputation(count($allBad) === 1, 'availability floor removed the final candidate');
assertUltraCandidateReputation(($allBad[0]['line_id'] ?? 0) === 347, 'availability floor preserved wrong candidate');

$now += 3601;
$probation = $service->stateFor($scope);
assertUltraCandidateReputation(($probation['state'] ?? '') === 'probation', 'expired quarantine must enter probation');
assertUltraCandidateReputation(($probation['quarantine_until'] ?? 0) === 0, 'expired quarantine retained TTL');

$success1 = $service->observeSuccess($scope, 'observer-a', 'success-1');
$success2 = $service->observeSuccess($scope, 'observer-a', 'success-2');
$success3 = $service->observeSuccess($scope, 'observer-b', 'success-3');
assertUltraCandidateReputation(($success1['state'] ?? '') === 'probation', 'first recovery success must remain probation');
assertUltraCandidateReputation(($success2['state'] ?? '') === 'probation', 'second recovery success must remain probation');
assertUltraCandidateReputation(($success3['state'] ?? '') === 'healthy', 'third recovery success must restore healthy');
assertUltraCandidateReputation(($success3['backoff_level'] ?? 0) === 1, 'recovery must keep the quarantine history');

// A candidate that has already served a quarantine is a repeat offender: the
// next corroborated failure quarantines it immediately and on the next rung of
// the ladder, instead of walking the probation ramp from scratch.
$relapse = $service->observeFailure($scope, 'relay_connect_timeout', 'observer-a', 'evidence-4');
assertUltraCandidateReputation(($relapse['state'] ?? '') === 'quarantined', 'repeat offender must quarantine on the first failure');
assertUltraCandidateReputation(($relapse['quarantine_until'] ?? 0) === $now + 7200, 'repeat quarantine must escalate the TTL');
assertUltraCandidateReputation(($relapse['backoff_level'] ?? 0) === 2, 'repeat quarantine must advance the ladder');

$now += 7201;
$secondProbation = $service->stateFor($scope);
assertUltraCandidateReputation(($secondProbation['state'] ?? '') === 'probation', 'second expiry must enter probation');
$relapse2 = $service->observeFailure($scope, 'relay_connect_timeout', 'observer-a', 'evidence-5');
assertUltraCandidateReputation(($relapse2['state'] ?? '') === 'quarantined', 'third offence must quarantine on the first failure');
assertUltraCandidateReputation(($relapse2['quarantine_until'] ?? 0) === $now + 14400, 'third quarantine must keep doubling');

// A full clean day buys one rung back. The clock is only advanced past the
// quarantine, so the elapsed time since the last recorded failure is what
// decides the decay; three clean days take the ladder back to its base rung.
$now += 86401;
$decayedOnce = $service->observeFailure($scope, 'relay_connect_timeout', 'observer-a', 'evidence-6');
assertUltraCandidateReputation(($decayedOnce['quarantine_until'] ?? 0) === $now + 14400, 'a clean day must decay exactly one rung');

$now += (3 * 86400) + 1;
$decayedFully = $service->observeFailure($scope, 'relay_connect_timeout', 'observer-a', 'evidence-7');
assertUltraCandidateReputation(($decayedFully['quarantine_until'] ?? 0) === $now + 3600, 'three clean days must decay the ladder back to the base rung');
assertUltraCandidateReputation(($decayedFully['backoff_level'] ?? 0) === 1, 'a fully decayed candidate re-enters the ladder at the bottom');

$fingerprintService = new UltraCandidateReputationService(null, static function () use (&$now): int {
    return $now;
});
$fingerprintBefore = $fingerprintService->snapshotFingerprint([$scope, $otherScope]);
$fingerprintService->observeFailure($scope, 'relay_connect_timeout', 'observer-a', 'fingerprint-1');
$fingerprintService->observeFailure($scope, 'relay_connect_timeout', 'observer-a', 'fingerprint-2');
$fingerprintAfter = $fingerprintService->snapshotFingerprint([$scope, $otherScope]);
assertUltraCandidateReputation($fingerprintBefore !== $fingerprintAfter, 'reputation state change did not rotate snapshot fingerprint');
assertUltraCandidateReputation(preg_match('/^[a-f0-9]{64}$/', $fingerprintAfter) === 1, 'snapshot fingerprint is not sha256');

$invalid = $service->observeFailure(
    ['source_id' => '', 'line_id' => 0, 'ingress_zone' => 'bad zone', 'destination_zone' => 'x', 'protocol' => 'icmp'],
    'relay_connect_timeout',
    'observer-a',
    'invalid'
);
assertUltraCandidateReputation($invalid === [], 'invalid candidate scope was accepted');

$source = (string) file_get_contents(__DIR__ . '/../src/Support/UltraCandidateReputationService.php');
foreach (['candidateKey', 'candidateKeyHash', 'snapshotFingerprint', 'quarantined', 'probation', 'quarantine_until', 'filterCandidates'] as $needle) {
    assertUltraCandidateReputation(str_contains($source, $needle), 'missing reputation boundary: ' . $needle);
}

echo "ok\n";
