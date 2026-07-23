<?php
declare(strict_types=1);

function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }

$repoRoot = dirname(__DIR__, 2);
require_once $repoRoot . '/master/src/Support/LeigodOnlyActivationPlan.php';
require_once $repoRoot . '/master/src/Support/LeigodOnlyActivationPlanStore.php';

final class PlanStoreRecorderDb
{
    public array $calls = [];
    public int $nextId = 1001;
    public function insert(string $table, array $data): int { $this->calls[] = ['insert', $table, $data]; return $this->nextId; }
    public function execute(string $sql, array $params = []): int { $this->calls[] = ['execute', $sql, $params]; fail('PlanStore must not update/delete activation plans'); }
    public function fetchOne(string $sql, array $params = []): ?array { $this->calls[] = ['fetchOne', $sql, $params]; return null; }
}

$payload = [
    'scope' => 'tenant:42',
    'dry_run_only' => true,
    'artifact_gate' => ['ok' => true, 'artifact_snapshot_hash' => str_repeat('a', 64)],
    'runtime_contract_gate' => ['ok' => true],
    'leg_policy_gate' => ['ok' => true],
    'telemetry_gate' => ['ok' => true],
    'canary_gate' => ['ok' => true],
    'normalization_gate' => ['ok' => true],
    'retirement_gate' => ['ok' => true],
    'rollback_gate' => ['ok' => true],
    'blockers' => [],
    'desired_generation' => 9,
    'desired_state_hash' => str_repeat('b', 64),
    'platform_family' => 'linux-bond',
];
$plan = \Leikwan\Master\Support\LeigodOnlyActivationPlan::fromPayload($payload);
$db = new PlanStoreRecorderDb();
$store = new \Leikwan\Master\Support\LeigodOnlyActivationPlanStore($db);

try { $store->record($plan, ['record' => false]); } catch (\InvalidArgumentException $e) { if (!str_contains($e->getMessage(), 'record_explicitly_required')) fail('wrong missing record message'); };
try { $store->record($plan, []); } catch (\InvalidArgumentException $e) { if (!str_contains($e->getMessage(), 'record_explicitly_required')) fail('wrong omitted record message'); };
if ($db->calls !== []) { fail('PlanStore must be read-only unless record=true explicit'); }

$id = $store->record($plan, [
    'record' => true,
    'owner_token' => str_repeat('c', 64),
    'fencing_token' => str_repeat('d', 64),
    'affected_forward_ids' => [47, 48],
    'artifact_snapshot_hash' => str_repeat('a', 64),
    'external_authorization_ref' => 'AUTH-1',
]);
if ($id !== 1001) { fail('expected insert id'); }
$insert = $db->calls[0] ?? null;
if (($insert[0] ?? '') !== 'insert' || ($insert[1] ?? '') !== 'leigod_only_activation_plans') { fail('expected immutable plan insert'); }
$data = $insert[2];
foreach (['scope','plan_hash','owner_token','fencing_token','write_hash','artifact_snapshot_hash','external_authorization_ref','plan_json'] as $key) {
    if (!array_key_exists($key, $data)) fail('missing persisted plan field: ' . $key);
}
if ($data['scope'] !== 'tenant:42' || $data['owner_token'] !== str_repeat('c',64) || $data['fencing_token'] !== str_repeat('d',64)) { fail('fence fields not persisted first-class'); }
if ($data['artifact_snapshot_hash'] !== str_repeat('a', 64) || $data['external_authorization_ref'] !== 'AUTH-1') { fail('artifact/external auth not persisted'); }
if (!str_contains($data['plan_json'], '"affected_forward_ids":[47,48]')) { fail('affected forward allowlist must be persisted in plan_json'); }
if (!str_contains($data['plan_json'], '"canonical_plan_hash":"' . $plan->hash() . '"')) { fail('plan_json must include recomputed canonical hash'); }
$expectedWrite = hash('sha256', \Leikwan\Master\Support\LeigodOnlyActivationPlan::canonicalJson(['plan_hash'=>$plan->hash(),'owner_token'=>str_repeat('c',64),'fencing_token'=>str_repeat('d',64),'affected_forward_ids'=>[47,48],'artifact_snapshot_hash'=>str_repeat('a',64)]));
if ($data['write_hash'] !== $expectedWrite) { fail('write_hash must be canonical and deterministic'); }

foreach (['update','delete','expire','authorize'] as $method) {
    try { $store->{$method}($plan->hash()); } catch (\LogicException $e) { if (str_contains($e->getMessage(), 'immutable_plan_store')) continue; fail('wrong immutable message for ' . $method); }
    fail('expected immutable method to reject: ' . $method);
}
try { $store->loadAuthorization($plan->hash()); } catch (\LogicException $e) { if (str_contains($e->getMessage(), 'plan_is_not_authorization')) { echo "ok\n"; return; } }
fail('PlanStore must never treat plan as authorization');
