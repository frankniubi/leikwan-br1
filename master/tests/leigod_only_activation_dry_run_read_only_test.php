<?php
declare(strict_types=1);

function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }

$repoRoot = dirname(__DIR__, 2);
foreach ([
    '/master/src/Support/LeigodOnlyRuntimeContract.php',
    '/master/src/Support/StandaloneBondLegPolicy.php',
    '/master/src/Support/LeigodOnlyActivationPlan.php',
    '/master/src/Support/LeigodOnlyActivationAssignmentService.php',
    '/master/src/Support/LeigodOnlyActivationPlanner.php',
] as $path) {
    require_once $repoRoot . $path;
}

final class ReadOnlyRecorderDb
{
    public array $calls = [];
    public function fetchAll(string $sql, array $params = []): array { $this->calls[] = ['fetchAll', $sql, $params]; return []; }
    public function fetchOne(string $sql, array $params = []): ?array { $this->calls[] = ['fetchOne', $sql, $params]; return null; }
    public function execute(string $sql, array $params = []): int { $this->calls[] = ['execute', $sql, $params]; fail('planner dry-run called mutating execute: ' . $sql); }
    public function insert(string $table, array $data): int { $this->calls[] = ['insert', $table, $data]; fail('planner dry-run called mutating insert: ' . $table); }
    public function transaction(callable $callback): mixed { $this->calls[] = ['transaction']; fail('planner dry-run opened transaction'); }
}

final class DryRunAssignmentService
{
    public array $calls = [];
    public function loadCurrent(int $forwardId, ?int $clientId, ?int $deviceId, string $scope = 'global'): ?array
    {
        $this->calls[] = ['loadCurrent', $forwardId, $clientId, $deviceId, $scope];
        return [
            'forward_id' => $forwardId,
            'client_id' => $clientId,
            'device_id' => $deviceId,
            'desired_generation' => 7,
            'assignment_hash' => str_repeat('a', 64),
            'desired_leg_set_hash' => str_repeat('b', 64),
            'active_family' => 'standalone_bond',
            'slot_generation' => 7,
            'slot_assignment_hash' => str_repeat('a', 64),
            'runtime_contract_json' => json_encode([
                'backend_mode' => 'leigod_mode',
                'client_mode' => 'bond',
                'runtime_preference' => 'single',
                'bond_ingress_mode' => 'plain_tcp_mux',
                'active_family' => 'standalone_bond',
            ]),
        ];
    }
    public function desiredLegRows(int $forwardId, ?int $clientId, ?int $deviceId, string $scope = 'global'): array
    {
        $this->calls[] = ['desiredLegRows', $forwardId, $clientId, $deviceId, $scope];
        return [
            ['forward_id' => $forwardId, 'desired_generation' => 7, 'node_id' => 1, 'endpoint_id' => 101, 'protocol' => 'tcp', 'logical_leg_key' => 'bond:' . $forwardId . ':tcp:1:101', 'status' => 'active'],
            ['forward_id' => $forwardId, 'desired_generation' => 7, 'node_id' => 2, 'endpoint_id' => 102, 'protocol' => 'tcp', 'logical_leg_key' => 'bond:' . $forwardId . ':tcp:2:102', 'status' => 'active'],
        ];
    }
}

final class DryRunArtifactGate
{
    public function evaluate(array $scope): array
    {
        return ['ok' => false, 'artifact_snapshot_hash' => str_repeat('c', 64), 'blockers' => ['artifact_gate_pending']];
    }
}

final class ForbiddenSideEffectService
{
    public function reconcileForwardEndpoints(): void { fail('dry-run must not call reconcileForwardEndpoints'); }
    public function republishActiveForwardsForCombination(): void { fail('dry-run must not call republishActiveForwardsForCombination'); }
    public function issueCleanupLease(): void { fail('dry-run must not issue cleanup lease'); }
    public function runSystemCommand(): void { fail('dry-run must not run system command'); }
    public function writeArtifactPointer(): void { fail('dry-run must not write artifact pointer'); }
}

$assignment = new DryRunAssignmentService();
$planner = new \Leikwan\Master\Support\LeigodOnlyActivationPlanner($assignment, new DryRunArtifactGate(), new ForbiddenSideEffectService());
$plan = $planner->plan([
    'scope' => 'tenant:42',
    'dry_run_only' => true,
    'candidates' => [['forward_id' => 47, 'client_id' => 12, 'device_id' => 99]],
    'runtime_contract_gate' => ['ok' => false, 'blockers' => ['runtime_contract_not_locked']],
    'telemetry_gate' => ['ok' => false, 'blockers' => ['telemetry_missing']],
    'canary_gate' => ['ok' => true],
    'normalization_gate' => ['ok' => false, 'blockers' => ['needs_normalization']],
    'retirement_gate' => ['ok' => false, 'blockers' => ['retirement_not_armed']],
    'rollback_gate' => ['ok' => false, 'blockers' => ['rollback_plan_missing']],
]);

$payload = $plan->payload();
foreach (['scope','dry_run_only','artifact_gate','runtime_contract_gate','leg_policy_gate','telemetry_gate','canary_gate','normalization_gate','retirement_gate','rollback_gate','blockers'] as $key) {
    if (!array_key_exists($key, $payload)) { fail('plan payload missing key: ' . $key); }
}
if ($payload['scope'] !== 'tenant:42' || $payload['dry_run_only'] !== true) { fail('scope/dry_run_only not preserved'); }
foreach (['artifact_gate_pending','runtime_contract_not_locked','telemetry_missing','needs_normalization','retirement_not_armed','rollback_plan_missing'] as $blocker) {
    if (!in_array($blocker, $payload['blockers'], true)) { fail('missing expected non-activating blocker: ' . $blocker); }
}
if (($payload['leg_policy_gate']['candidates'][0]['classification']['outcome'] ?? '') !== 'speed_plus_eligible') { fail('expected planner to call StandaloneBondLegPolicy'); }
if (!is_string($plan->hash()) || strlen($plan->hash()) !== 64 || !ctype_xdigit($plan->hash())) { fail('expected canonical sha256 plan hash'); }
$changed = \Leikwan\Master\Support\LeigodOnlyActivationPlan::fromPayload(array_replace($payload, ['telemetry_gate' => ['ok' => true]]));
if ($changed->hash() === $plan->hash()) { fail('plan hash must change with canonical payload'); }

if (count($assignment->calls) !== 2 || $assignment->calls[0][0] !== 'loadCurrent' || $assignment->calls[1][0] !== 'desiredLegRows') {
    fail('planner must load current assignment then desired leg rows read-only');
}

echo "ok\n";
