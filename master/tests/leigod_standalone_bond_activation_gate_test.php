<?php
declare(strict_types=1);

function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }
$repoRoot = dirname(__DIR__, 2);
foreach ([
    '/master/src/Support/LeigodActivationGateContext.php',
    '/master/src/Support/LeigodStandaloneBondActivationGateService.php',
] as $path) require_once $repoRoot . $path;

final class GateTestDb
{
    public array $calls = [];
    public array $lifecycleRows = [];
    public array $capabilityRows = [];
    public array $rejectionRows = [];
    public array $proofRows = [];
    public function fetchAll(string $sql, array $params = []): array
    {
        $this->calls[] = ['fetchAll', $sql, $params];
        if (str_contains($sql, 'leigod_only_lifecycle_snapshots')) return $this->lifecycleRows;
        if (str_contains($sql, 'leigod_only_client_capability_reports')) return $this->capabilityRows;
        if (str_contains($sql, 'leigod_only_runtime_snapshot_rejections')) return $this->rejectionRows;
        if (str_contains($sql, 'leigod_only_activation_proofs')) return $this->proofRows;
        return [];
    }
    public function fetchOne(string $sql, array $params = []): ?array { $rows = $this->fetchAll($sql, $params); return $rows[0] ?? null; }
}
final class GateAssignmentService
{
    public ?array $current;
    public array $legs;
    public function __construct(?array $current, array $legs) { $this->current = $current; $this->legs = $legs; }
    public function loadCurrent(int $forwardId, ?int $clientId, ?int $deviceId, string $scope = 'global'): ?array { return $this->current; }
    public function desiredLegRows(int $forwardId, ?int $clientId, ?int $deviceId, string $scope = 'global'): array { return $this->legs; }
}
final class GateArtifactGate { public array $calls = []; public array $result = ['ok'=>true]; public function evaluate(array $scope): array { $this->calls[] = $scope; return $this->result; } }
final class GateLock { public array $calls = []; public bool $held = true; public function requireHeld(string $scope, string $lockKey, string $owner, string $fence): void { $this->calls[] = compact('scope','lockKey','owner','fence'); if (!$this->held) throw new RuntimeException('not held'); } }

function h(string $char): string { return str_repeat($char, 64); }
function runtimeRow(array $patch = []): array { return array_replace(['backend_mode'=>'leigod_mode','client_mode'=>'bond','runtime_preference'=>'single','bond_ingress_mode'=>'plain_tcp_mux','active_family'=>'standalone_bond'], $patch); }
function assignmentRow(array $patch = []): array { return array_replace(['id'=>7001,'scope'=>'global','forward_id'=>47,'client_id'=>12,'client_device_session_id'=>99,'desired_generation'=>6,'assignment_hash'=>h('a'),'desired_leg_set_hash'=>'','active_family'=>'standalone_bond','platform_family'=>'linux','runtime_contract_json'=>json_encode(runtimeRow()),'owner_token'=>h('c'),'fencing_token'=>h('d'),'artifact_snapshot_hash'=>h('e')], $patch); }
function legRow(int $node, int $endpoint, array $patch = []): array { return array_replace(['forward_id'=>47,'desired_generation'=>6,'node_id'=>$node,'endpoint_id'=>$endpoint,'protocol'=>'tcp','logical_leg_key'=>'bond:47:tcp:' . $node . ':' . $endpoint,'status'=>'active'], $patch); }
function pins(): array { return ['client_agent'=>h('1'),'bond_binary'=>h('2'),'slave_agent'=>h('3')]; }
function baseContext(array $patch = []): \Leikwan\Master\Support\LeigodActivationGateContext
{
    return \Leikwan\Master\Support\LeigodActivationGateContext::fromArray(array_replace_recursive([
        'scope'=>'global','forward_id'=>47,'client_id'=>12,'client_device_session_id'=>99,'desired_generation'=>6,
        'assignment_id'=>7001,'assignment_hash'=>h('a'),'platform_family'=>'linux','write_mode'=>'activation',
        'flags'=>['activation_enabled'=>true],
        'settings'=>['allowlisted_forward_ids'=>[47],'allowlisted_client_ids'=>[12],'allowlisted_device_session_ids'=>[99],'upload_min_mbps'=>10,'download_min_mbps'=>10],
        'expected_artifact_pins'=>pins(),'owner_token'=>h('c'),'fencing_token'=>h('d'),'campaign_id'=>null,
    ], $patch));
}
function populatedDb(): GateTestDb
{
    $db = new GateTestDb();
    $db->lifecycleRows[] = ['scope'=>'global','forward_id'=>47,'client_id'=>12,'client_device_session_id'=>99,'desired_generation'=>6,'assignment_hash'=>h('a'),'desired_leg_set_hash'=>'','platform_family'=>'linux','snapshot_state'=>'accepted','created_at_unix'=>990,'observed_usable_leg_count'=>2];
    $db->capabilityRows[] = ['scope'=>'global','forward_id'=>47,'client_id'=>12,'client_device_session_id'=>99,'assignment_id'=>7001,'desired_generation'=>6,'assignment_hash'=>h('a'),'platform_family'=>'linux','report_state'=>'accepted','proof_source'=>'client','created_at_unix'=>995,'artifact_hashes_json'=>json_encode(pins())];
    $db->proofRows[] = ['scope'=>'global','forward_id'=>47,'client_id'=>12,'client_device_session_id'=>99,'assignment_id'=>7001,'desired_generation'=>6,'assignment_hash'=>h('a'),'platform_family'=>'linux','direction'=>'upload','proof_state'=>'accepted','proof_source'=>'client_runtime_telemetry','created_at_unix'=>996,'mbps'=>25,'proof_id'=>'up-1'];
    $db->proofRows[] = ['scope'=>'global','forward_id'=>47,'client_id'=>12,'client_device_session_id'=>99,'assignment_id'=>7001,'desired_generation'=>6,'assignment_hash'=>h('a'),'platform_family'=>'linux','direction'=>'download','proof_state'=>'accepted','proof_source'=>'client_runtime_telemetry','created_at_unix'=>997,'mbps'=>40,'proof_id'=>'down-1'];
    return $db;
}
function gateResult(GateTestDb $db, GateAssignmentService $assign, GateArtifactGate $artifact, GateLock $lock, ?\Leikwan\Master\Support\LeigodActivationGateContext $ctx = null): array
{
    $svc = new \Leikwan\Master\Support\LeigodStandaloneBondActivationGateService($db, $assign, $artifact, $lock, 1000);
    return $svc->gate($ctx ?? baseContext());
}
function assertBlocker(array $result, string $blocker): void { if (!in_array($blocker, $result['blockers'] ?? [], true)) fail('missing blocker ' . $blocker . ': ' . json_encode($result)); }

$legs = [legRow(1, 101), legRow(2, 102)];
$computedLegHash = \Leikwan\Master\Support\StandaloneBondLegPolicy::canonicalLegSetHash(47, 6, $legs);
$current = assignmentRow(['desired_leg_set_hash'=>$computedLegHash]);
$db = populatedDb(); $db->lifecycleRows[0]['desired_leg_set_hash'] = $computedLegHash;
$artifact = new GateArtifactGate(); $lock = new GateLock();
$result = gateResult($db, new GateAssignmentService($current, $legs), $artifact, $lock, baseContext(['desired_leg_set_hash'=>$computedLegHash]));
if (($result['ok'] ?? false) !== true || ($result['policy_state']['outcome'] ?? '') !== 'speed_plus_eligible' || ($result['promotion_ready'] ?? false) !== true) fail('expected speed_plus activation to pass: ' . json_encode($result));
if (count($artifact->calls) !== 1 || count($lock->calls) !== 1) fail('expected artifact gate and write fence checks');
$sqlJoined = json_encode(array_column($db->calls, 1));
foreach (['leigod_only_lifecycle_snapshots','forward_id = :forward_id','client_id = :client_id','client_device_session_id = :client_device_session_id','desired_generation = :desired_generation','assignment_hash = :assignment_hash','desired_leg_set_hash = :desired_leg_set_hash','platform_family = :platform_family','snapshot_state = \'accepted\'','created_at_unix > :fresh_after','leigod_only_activation_proofs','direction = :direction'] as $needle) if (!str_contains($sqlJoined, $needle)) fail('gate SQL missing required predicate ' . $needle);

$result = gateResult(populatedDb(), new GateAssignmentService($current, $legs), new GateArtifactGate(), new GateLock(), baseContext(['flags'=>['activation_enabled'=>false],'desired_leg_set_hash'=>$computedLegHash]));
assertBlocker($result, 'activation_flag_disabled');
$result = gateResult(populatedDb(), new GateAssignmentService($current, $legs), new GateArtifactGate(), new GateLock(), baseContext(['settings'=>['allowlisted_forward_ids'=>[999],'allowlisted_client_ids'=>[12],'allowlisted_device_session_ids'=>[99]],'desired_leg_set_hash'=>$computedLegHash]));
assertBlocker($result, 'not_allowlisted:forward');
$result = gateResult(populatedDb(), new GateAssignmentService($current, $legs), new GateArtifactGate(), new GateLock(), baseContext(['settings'=>['allowlisted_forward_ids'=>[47],'allowlisted_client_ids'=>[999],'allowlisted_device_session_ids'=>[99]],'desired_leg_set_hash'=>$computedLegHash]));
assertBlocker($result, 'not_allowlisted:client');
$result = gateResult(populatedDb(), new GateAssignmentService($current, $legs), new GateArtifactGate(), new GateLock(), baseContext(['settings'=>['allowlisted_forward_ids'=>[47],'allowlisted_client_ids'=>[12],'allowlisted_device_session_ids'=>[999]],'desired_leg_set_hash'=>$computedLegHash]));
assertBlocker($result, 'not_allowlisted:device');

foreach (['current_mode','cf_mode','native_mptcp','platform_default','bond_fallback'] as $marker) {
    $bad = assignmentRow(['desired_leg_set_hash'=>$computedLegHash,'runtime_contract_json'=>json_encode(runtimeRow(['legacy_marker'=>$marker]))]);
    $result = gateResult(populatedDb(), new GateAssignmentService($bad, $legs), new GateArtifactGate(), new GateLock(), baseContext(['desired_leg_set_hash'=>$computedLegHash]));
    assertBlocker($result, 'unsupported_runtime_contract');
}

$result = gateResult(populatedDb(), new GateAssignmentService($current, $legs), new GateArtifactGate(), new GateLock(), baseContext(['assignment_hash'=>h('b'),'desired_leg_set_hash'=>$computedLegHash]));
assertBlocker($result, 'assignment_context_mismatch');
$result = gateResult(populatedDb(), new GateAssignmentService($current, $legs), new GateArtifactGate(), new GateLock(), baseContext(['runtime_row'=>runtimeRow(['backend_mode'=>'cf_mode']),'desired_leg_set_hash'=>$computedLegHash]));
assertBlocker($result, 'assignment_context_mismatch');
$result = gateResult(populatedDb(), new GateAssignmentService($current, $legs), new GateArtifactGate(), new GateLock(), baseContext(['leg_rows'=>[legRow(9, 909)],'desired_leg_set_hash'=>$computedLegHash]));
assertBlocker($result, 'assignment_context_mismatch');
$badStored = assignmentRow(['desired_leg_set_hash'=>h('9')]);
$result = gateResult(populatedDb(), new GateAssignmentService($badStored, $legs), new GateArtifactGate(), new GateLock(), baseContext(['desired_leg_set_hash'=>h('9')]));
assertBlocker($result, 'desired_leg_set_hash_mismatch');
$artifact = new GateArtifactGate(); $artifact->result = ['ok'=>false,'blockers'=>['route_static_shadow']];
$result = gateResult(populatedDb(), new GateAssignmentService($current, $legs), $artifact, new GateLock(), baseContext(['desired_leg_set_hash'=>$computedLegHash]));
assertBlocker($result, 'artifact_gate_failed');
$lock = new GateLock(); $lock->held = false;
$result = gateResult(populatedDb(), new GateAssignmentService($current, $legs), new GateArtifactGate(), $lock, baseContext(['desired_leg_set_hash'=>$computedLegHash]));
assertBlocker($result, 'rollout_lock_not_held');

 echo "ok\n";
