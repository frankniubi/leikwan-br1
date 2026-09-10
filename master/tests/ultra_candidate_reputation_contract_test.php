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
assertUltraCandidateReputation(($third['quarantine_until'] ?? 0) === $now + 1800, 'initial quarantine TTL mismatch');
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

$now += 1801;
$probation = $service->stateFor($scope);
assertUltraCandidateReputation(($probation['state'] ?? '') === 'probation', 'expired quarantine must enter probation');
assertUltraCandidateReputation(($probation['quarantine_until'] ?? 0) === 0, 'expired quarantine retained TTL');

$success1 = $service->observeSuccess($scope, 'observer-a', 'success-1');
$success2 = $service->observeSuccess($scope, 'observer-a', 'success-2');
$success3 = $service->observeSuccess($scope, 'observer-b', 'success-3');
assertUltraCandidateReputation(($success1['state'] ?? '') === 'probation', 'first recovery success must remain probation');
assertUltraCandidateReputation(($success2['state'] ?? '') === 'probation', 'second recovery success must remain probation');
assertUltraCandidateReputation(($success3['state'] ?? '') === 'healthy', 'third recovery success must restore healthy');

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
