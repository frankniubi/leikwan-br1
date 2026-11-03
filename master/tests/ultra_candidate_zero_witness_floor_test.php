<?php
declare(strict_types=1);

/**
 * Zero independent witnesses must be a representable, and failing, answer.
 *
 * observerCount() was clamped with max(1, ...), so "no witnesses" could not be
 * expressed: the counter reported 1 for a candidate with no independent
 * reporters at all. The quarantine gate is 'independent_observers >=
 * minimum_observers' and minimum_observers is configurable down to 1, so at that
 * setting a clamped 1 would satisfy a floor of one on the strength of nobody.
 * The stored value is also what the admin console shows, so a clamp made the
 * displayed witness count untruthful too.
 *
 * The clamp is gone: the count is honest, including zero. The gate expression is
 * deliberately unchanged, because an honest 0 still fails the default floor of 2.
 * This test also pins the OTHER direction, so the fix cannot be "made to pass" by
 * simply refusing to count: a candidate with one genuine independent witness must
 * still satisfy a floor of one.
 */

require_once __DIR__ . '/../src/Support/UltraCandidateReputationService.php';

use Leikwan\Master\Support\UltraCandidateReputationService;

function failZeroWitness(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertZeroWitness(bool $condition, string $message): void
{
    if (!$condition) {
        failZeroWitness($message);
    }
}

/**
 * Answers the witness-count query with a fixed row. Deliberately has neither
 * tableRows() (which would switch the service onto the fake-table path) nor
 * transaction(), so the real database branch and the plain callback are used.
 */
final class CannedWitnessCount
{
    public function __construct(private int $count, private int $seen)
    {
    }

    /** @param array<string, mixed> $params @return array<string, mixed>|null */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        if (str_contains($sql, 'observer_count')) {
            return ['observer_count' => $this->count, 'observer_seen' => $this->seen];
        }
        return null;
    }

    /** @param array<string, mixed> $params */
    public function execute(string $sql, array $params = []): int
    {
        return 1;
    }
}

$now = 1_900_000_000;
$clock = static fn(): int => $now;
$ref = new ReflectionClass(UltraCandidateReputationService::class);
$counter = $ref->getMethod('observerCount');
$counter->setAccessible(true);

function serviceWith(object $db, callable $clock): UltraCandidateReputationService
{
    return new UltraCandidateReputationService($db, $clock);
}

// The hole: the table says zero independent witnesses and the reporting device is
// already accounted for. Honest answer is 0 - and 0 must NOT satisfy a floor of 1.
$zeroDb = new CannedWitnessCount(0, 1);
$zeroService = serviceWith($zeroDb, $clock);
$zero = $counter->invoke($zeroService, str_repeat('a', 64), str_repeat('b', 64));
assertZeroWitness($zero === 0, 'a candidate with no independent witnesses must count as zero, not one');
assertZeroWitness(!($zero >= 1), 'zero witnesses must not satisfy a floor of one');

// The other direction, so the fix cannot pass by refusing to count at all: one
// genuine independent witness must still satisfy a floor of one.
$oneDb = new CannedWitnessCount(1, 1);
$oneService = serviceWith($oneDb, $clock);
$one = $counter->invoke($oneService, str_repeat('a', 64), str_repeat('b', 64));
assertZeroWitness($one === 1, 'one genuine independent witness must count as one');
assertZeroWitness($one >= 1, 'one genuine independent witness must satisfy a floor of one');

// End to end on the in-memory path: with the floor configured to 1, a single real
// reporter still quarantines - the clamp was never what made a lone witness count.
$scope = [
    'source_id' => 'source-home-171',
    'line_id' => 453,
    'ingress_zone' => 'guangzhou',
    'destination_zone' => 'tokyo',
    'protocol' => 'tcp+udp',
];
$solo = new UltraCandidateReputationService(null, $clock, [
    'minimum_observers' => 1,
    'probation_failures' => 1,
    'quarantine_failures' => 1,
]);
$row = $solo->observeFailure($scope, 'relay_connect_timeout', 'observer-a', 'f-1');
assertZeroWitness(
    (int) ($row['independent_observers'] ?? -1) === 1,
    'a single genuine reporter must be recorded as exactly one witness'
);
assertZeroWitness(
    (string) ($row['state'] ?? '') === UltraCandidateReputationService::STATE_QUARANTINED,
    'at a floor of one, one genuine witness must still be able to quarantine'
);

// And the default floor of two is untouched: one reporter is still not enough.
$pair = new UltraCandidateReputationService(null, $clock, [
    'probation_failures' => 1,
    'quarantine_failures' => 1,
]);
$row = $pair->observeFailure($scope, 'relay_connect_timeout', 'observer-a', 'f-1');
assertZeroWitness(
    (string) ($row['state'] ?? '') !== UltraCandidateReputationService::STATE_QUARANTINED,
    'the default floor of two must not be reachable by a single witness'
);

echo "ultra_candidate_zero_witness_floor_test: OK" . PHP_EOL;
