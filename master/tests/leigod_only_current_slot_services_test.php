<?php
declare(strict_types=1);
function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }
$repoRoot = dirname(__DIR__, 2);
require_once $repoRoot . '/master/src/Support/LeigodOnlyRuntimeContract.php';
require_once $repoRoot . '/master/src/Support/LeigodOnlyActivationAssignmentService.php';

final class CurrentSlotDb
{
    public array $calls = [];
    public array $fetchAllQueue = [];
    public array $fetchOneQueue = [];
    public array $executeQueue = [];
    public function fetchAll(string $sql, array $params = []): array { $this->calls[] = ['fetchAll',$sql,$params]; return array_shift($this->fetchAllQueue) ?? []; }
    public function fetchOne(string $sql, array $params = []): ?array { $this->calls[] = ['fetchOne',$sql,$params]; return array_shift($this->fetchOneQueue); }
    public function execute(string $sql, array $params = []): int { $this->calls[] = ['execute',$sql,$params]; return array_shift($this->executeQueue) ?? 1; }
    public function insert(string $table, array $data): int { $this->calls[] = ['insert',$table,$data]; return 777; }
    public function transaction(callable $callback): mixed { $this->calls[] = ['transaction']; return $callback($this); }
}
function validAssignmentRow(): array { return ['id'=>123,'slot_key'=>'47:12:99:current','current_slot_key'=>'47:12:99:current','forward_id'=>47,'client_id'=>12,'client_device_session_id'=>99,'desired_generation'=>9,'slot_generation'=>9,'assignment_hash'=>str_repeat('a',64),'slot_assignment_hash'=>str_repeat('a',64),'desired_leg_set_hash'=>str_repeat('b',64),'slot_desired_leg_set_hash'=>str_repeat('b',64),'active_family'=>'standalone_bond','slot_active_family'=>'standalone_bond','superseded_at'=>null,'row_expires_at'=>'2099-01-01 00:00:00','runtime_contract_json'=>json_encode(['backend_mode'=>'leigod_mode','client_mode'=>'bond','runtime_preference'=>'single','bond_ingress_mode'=>'plain_tcp_mux','active_family'=>'standalone_bond']),'owner_token'=>str_repeat('c',64),'fencing_token'=>str_repeat('d',64),'write_hash'=>str_repeat('e',64),'artifact_snapshot_hash'=>str_repeat('f',64)]; }

$db = new CurrentSlotDb(); $db->fetchAllQueue[] = [validAssignmentRow()]; $svc = new \Leikwan\Master\Support\LeigodOnlyActivationAssignmentService($db); $row = $svc->loadCurrent(47,12,99,'global');
if (($row['assignment_hash'] ?? '') !== str_repeat('a',64)) fail('loadCurrent did not return validated row');
$sql = $db->calls[0][1]; foreach (['leigod_only_activation_assignment_current_slots','JOIN leigod_only_activation_assignments','a.id = cs.assignment_id','current_slot_key','superseded_at IS NULL','row_expires_at','LIMIT 2'] as $needle) if (!str_contains($sql,$needle)) fail('loadCurrent SQL missing ' . $needle);
$params = $db->calls[0][2]; if (($params['slot_key'] ?? '') !== '47:12:99:current') fail('deterministic slot key mismatch');

$db = new CurrentSlotDb(); $db->fetchAllQueue[] = []; $svc = new \Leikwan\Master\Support\LeigodOnlyActivationAssignmentService($db); if ($svc->loadCurrent(47,12,99,'global') !== null) fail('missing current slot should fail closed as null');
$db = new CurrentSlotDb(); $db->fetchAllQueue[] = [validAssignmentRow(), validAssignmentRow()]; $svc = new \Leikwan\Master\Support\LeigodOnlyActivationAssignmentService($db); try { $svc->loadCurrent(47,12,99,'global'); } catch (\RuntimeException $e) { if (!str_contains($e->getMessage(),'current_assignment_not_unique')) fail('wrong non-unique error'); }

$bad = validAssignmentRow(); $bad['active_family'] = 'classic_sidecar'; $db = new CurrentSlotDb(); $db->fetchAllQueue[] = [$bad]; $svc = new \Leikwan\Master\Support\LeigodOnlyActivationAssignmentService($db); try { $svc->loadCurrent(47,12,99,'global'); } catch (\Throwable $e) { if (!str_contains($e->getMessage(),'active_family')) fail('bad family rejected for wrong reason'); }

$db = new CurrentSlotDb(); $db->fetchOneQueue[] = validAssignmentRow(); $db->executeQueue = [1,1]; $svc = new \Leikwan\Master\Support\LeigodOnlyActivationAssignmentService($db);
$svc->supersedeCurrent(['scope'=>'global','forward_id'=>47,'client_id'=>12,'device_id'=>99,'owner_token'=>str_repeat('c',64),'fencing_token'=>str_repeat('d',64),'expected_assignment_hash'=>str_repeat('a',64),'expected_desired_leg_set_hash'=>str_repeat('b',64),'artifact_snapshot_hash'=>str_repeat('f',64),'assignment'=>['endpoint_id'=>101,'node_id'=>1,'desired_generation'=>10,'logical_leg_key'=>'bond:47:tcp:1:101','assignment_hash'=>str_repeat('1',64),'desired_leg_set_hash'=>str_repeat('2',64),'runtime_contract_json'=>['backend_mode'=>'leigod_mode','client_mode'=>'bond','runtime_preference'=>'single','bond_ingress_mode'=>'plain_tcp_mux','active_family'=>'standalone_bond']]]);
$joined = json_encode($db->calls); foreach (['FOR UPDATE','current_slot_key = NULL','superseded_at','leigod_only_activation_assignments','leigod_only_activation_assignment_current_slots','owner_token','fencing_token','assignment_hash','desired_leg_set_hash'] as $needle) if (!str_contains($joined,$needle)) fail('supersede missing current-slot/fence token SQL or data ' . $needle);

echo "ok\n";
