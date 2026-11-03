<?php
declare(strict_types=1);

/**
 * A quarantine must be escapable by the fleet's own successes, not only by its
 * timer.
 *
 * The review path that was supposed to disprove a bad report cannot
 * discriminate - a probe on the source host is not evidence about a client path
 * - so with that gate in place a quarantined candidate would otherwise sit until
 * its backoff expired, up to max_backoff_seconds. The fleet's data-plane
 * successes are the only honest signal left that a candidate works again, and
 * they do not depend on the source host at all.
 *
 * The asymmetry is what makes this safe, and these tests pin both halves of it:
 * releasing wrongly only re-admits a candidate, which the still-gated failure
 * path puts straight back on renewed corroborated failures; never releasing
 * removes capacity for the whole backoff. The release therefore needs the SAME
 * independent-witness floor a quarantine needs, so no single device can
 * manufacture one any more than it can manufacture a quarantine.
 */

require_once __DIR__ . '/../src/Support/UltraCandidateReputationService.php';

use Leikwan\Master\Support\UltraCandidateReputationService;

function failSuccessRelease(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertSuccessRelease(bool $condition, string $message): void
{
    if (!$condition) {
        failSuccessRelease($message);
    }
}

/** A quarantined candidate: three corroborated failures from two devices. */
function quarantineCandidate(UltraCandidateReputationService $service, array $scope, int &$now): void
{
    $service->observeFailure($scope, 'relay_connect_timeout', 'observer-a', 'f-1');
    $now += UltraCandidateReputationService::FAILURE_EVIDENCE_WINDOW_SECONDS + 1;
    $service->observeFailure($scope, 'relay_connect_timeout', 'observer-a', 'f-2');
    $service->observeFailure($scope, 'relay_connect_timeout', 'observer-b', 'f-3');
}

function scopeFor(int $lineId): array
{
    return [
        'source_id' => 'source-home-171',
        'line_id' => $lineId,
        'ingress_zone' => 'guangzhou',
        'destination_zone' => 'tokyo',
        'protocol' => 'tcp+udp',
    ];
}

// --- A. Two independent devices reporting the candidate working release it ---
$now = 1_900_000_000;
$service = new UltraCandidateReputationService(null, static function () use (&$now): int {
    return $now;
});
$scope = scopeFor(453);
quarantineCandidate($service, $scope, $now);
assertSuccessRelease($service->isQuarantined($scope), 'the fixture must start quarantined');

// Below the recovery threshold nothing moves, however clean the reports are.
$service->observeSuccess($scope, 'observer-a', 's-1');
$service->observeSuccess($scope, 'observer-b', 's-2');
assertSuccessRelease(
    $service->isQuarantined($scope),
    'two successes must not release a quarantine that needs three'
);

// The third success crosses the threshold, and two DISTINCT devices is the floor.
$released = $service->observeSuccess($scope, 'observer-c', 's-3');
assertSuccessRelease(
    ($released['state'] ?? '') === UltraCandidateReputationService::STATE_PROBATION,
    'independent successes must release the quarantine onto probation'
);
assertSuccessRelease(
    (int) ($released['quarantine_until'] ?? -1) === 0,
    'a released quarantine must clear its TTL'
);
assertSuccessRelease(
    (string) ($released['last_reason'] ?? '') === 'quarantine_lifted_by_independent_successes',
    'the release must record why it happened'
);
assertSuccessRelease(!$service->isQuarantined($scope), 'a released candidate must be eligible again');
assertSuccessRelease(
    (int) ($released['backoff_level'] ?? 0) === 1,
    'a release must keep the quarantine history, so the next one is still a repeat offence'
);

// --- B. One device cannot manufacture a release, however many reports it files ---
$now2 = 1_900_000_000;
$solo = new UltraCandidateReputationService(null, static function () use (&$now2): int {
    return $now2;
});
$soloScope = scopeFor(325);
quarantineCandidate($solo, $soloScope, $now2);
assertSuccessRelease($solo->isQuarantined($soloScope), 'the solo fixture must start quarantined');
for ($i = 1; $i <= 6; $i++) {
    $solo->observeSuccess($soloScope, 'observer-a', 'solo-' . $i);
}
assertSuccessRelease(
    $solo->isQuarantined($soloScope),
    'a single device must not lift a quarantine on its own, however many successes it reports'
);

// --- C. The release is self-correcting: one corroborated failure re-quarantines ---
$service->observeFailure($scope, 'relay_connect_timeout', 'observer-d', 'f-4');
assertSuccessRelease(
    $service->isQuarantined($scope),
    'a released candidate whose failures resume must be quarantined again'
);

echo "ultra_candidate_success_release_test: OK" . PHP_EOL;
