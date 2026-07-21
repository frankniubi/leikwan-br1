<?php
declare(strict_types=1);

function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }

$repoRoot = dirname(__DIR__, 2);
foreach ([
    '/master/src/Support/LeigodOnlyRuntimeContract.php',
    '/master/src/Support/StandaloneBondLegPolicy.php',
    '/master/src/Support/LeigodOnlyActivationPlan.php',
    '/master/src/Support/LeigodOnlyActivationPlanner.php',
] as $path) require_once $repoRoot . $path;

final class MatrixAssignmentService
{
    private array $rowsByForward;
    public function __construct(array $rowsByForward) { $this->rowsByForward = $rowsByForward; }
    public function loadCurrent(int $forwardId, ?int $clientId, ?int $deviceId, string $scope = 'global'): ?array { return null; }
    public function desiredLegRows(int $forwardId, ?int $clientId, ?int $deviceId, string $scope = 'global'): array { return $this->rowsByForward[$forwardId] ?? []; }
}
final class OkArtifactGate { public function evaluate(array $scope): array { return ['ok'=>true,'artifact_snapshot_hash'=>str_repeat('e',64)]; } }
function leg(int $forwardId, int $generation, int $node, int $endpoint, array $overrides = []): array { return array_replace(['forward_id'=>$forwardId,'desired_generation'=>$generation,'node_id'=>$node,'endpoint_id'=>$endpoint,'protocol'=>'tcp','logical_leg_key'=>'bond:' . $forwardId . ':tcp:' . $node . ':' . $endpoint,'status'=>'active'], $overrides); }

$rows = [
    100 => [],
    101 => [leg(101, 3, 1, 11)],
    102 => [leg(102, 3, 1, 11), leg(102, 3, 2, 12)],
    108 => [leg(108, 3, 1, 11), leg(108, 3, 2, 12), leg(108, 3, 3, 13), leg(108, 3, 4, 14), leg(108, 3, 5, 15), leg(108, 3, 6, 16), leg(108, 3, 7, 17), leg(108, 3, 8, 18)],
    109 => [leg(109, 3, 1, 11), leg(109, 3, 2, 12), leg(109, 3, 3, 13), leg(109, 3, 4, 14), leg(109, 3, 5, 15), leg(109, 3, 6, 16), leg(109, 3, 7, 17), leg(109, 3, 8, 18), leg(109, 3, 9, 19)],
    110 => [leg(110, 3, 1, 11), leg(110, 3, 1, 12)],
    111 => [leg(111, 3, 1, 11), leg(111, 3, 1, 11)],
];
$planner = new \Leikwan\Master\Support\LeigodOnlyActivationPlanner(new MatrixAssignmentService($rows), new OkArtifactGate());
$plan = $planner->plan(['scope'=>'global','dry_run_only'=>true,'desired_generation'=>3,'candidates'=>array_map(static fn($id): array => ['forward_id'=>$id], array_keys($rows))]);
$candidates = $plan->payload()['leg_policy_gate']['candidates'] ?? [];
$outcomes = [];
foreach ($candidates as $candidate) { $outcomes[$candidate['forward_id']] = [$candidate['classification']['outcome'] ?? '', $candidate['classification']['reason'] ?? '']; }
$expected = [
    100 => ['invalid_speed_plus_leg_set','no_usable_legs'],
    101 => ['single_leg_only','single_usable_slave'],
    102 => ['speed_plus_eligible','speed_plus_eligible'],
    108 => ['speed_plus_eligible','speed_plus_eligible'],
    109 => ['too_many_speed_plus_legs','too_many_speed_plus_legs'],
    110 => ['speed_plus_requires_distinct_slave_nodes','multiple_endpoints_one_slave_node'],
    111 => ['invalid_speed_plus_leg_set','duplicate_leg_key'],
];
if ($outcomes !== $expected) { fail('planner leg policy matrix mismatch: ' . json_encode($outcomes)); }
foreach ([100,109,110,111] as $forwardId) {
    if (!in_array('leg_policy:' . $forwardId . ':' . $expected[$forwardId][0], $plan->payload()['blockers'], true)) fail('missing leg policy blocker for ' . $forwardId);
}
$hashA = $plan->hash();
$hashB = $planner->plan(['candidates'=>array_reverse(array_map(static fn($id): array => ['forward_id'=>$id], array_keys($rows))), 'desired_generation'=>3, 'dry_run_only'=>true, 'scope'=>'global'])->hash();
if ($hashA !== $hashB) { fail('canonical plan hash must be stable for candidate order'); }

echo "ok\n";
