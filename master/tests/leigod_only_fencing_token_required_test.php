<?php
declare(strict_types=1);
function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }
$repoRoot = dirname(__DIR__, 2);
foreach ([
 '/master/src/Support/LeigodOnlyRuntimeContract.php',
 '/master/src/Support/LeigodOnlyActivationAssignmentService.php',
 '/master/src/Support/LeigodOnlyActivationSettingsService.php',
 '/master/src/Support/LeigodOnlyDesiredStateFenceService.php',
 '/master/src/Support/LeigodOnlyArtifactPointerService.php',
 '/master/src/Support/LeigodOnlyFencedPublishQueue.php',
 '/master/src/Support/LeigodOnlyFencedPublishExecutor.php',
] as $path) require_once $repoRoot . $path;

final class FenceDb
{
    public array $calls = [];
    public function fetchOne(string $sql, array $params = []): ?array { $this->calls[] = ['fetchOne',$sql,$params]; return null; }
    public function fetchAll(string $sql, array $params = []): array { $this->calls[] = ['fetchAll',$sql,$params]; return []; }
    public function execute(string $sql, array $params = []): int { $this->calls[] = ['execute',$sql,$params]; return 1; }
    public function insert(string $table, array $data): int { $this->calls[] = ['insert',$table,$data]; return 1; }
    public function transaction(callable $callback): mixed { $this->calls[] = ['transaction']; return $callback($this); }
}
function assertRejectsBeforeWrites(string $label, callable $fn, FenceDb $db): void { try { $fn(); } catch (\InvalidArgumentException|\RuntimeException $e) { if (!str_contains($e->getMessage(),'fencing_token_required') && !str_contains($e->getMessage(),'rollout_lock_not_held')) fail($label . ' wrong error: ' . $e->getMessage()); if ($db->calls !== []) fail($label . ' wrote or read before rejecting missing fence'); return; } fail($label . ' should reject missing fence'); }

assertRejectsBeforeWrites('assignment supersede missing owner', fn() => (new \Leikwan\Master\Support\LeigodOnlyActivationAssignmentService(new FenceDb()))->supersedeCurrent(['scope'=>'global','forward_id'=>1,'client_id'=>2,'device_id'=>3,'assignment'=>[]]), $db = new FenceDb());
assertRejectsBeforeWrites('settings missing fence', fn() => (new \Leikwan\Master\Support\LeigodOnlyActivationSettingsService(new FenceDb()))->updateSetting(['scope'=>'global','setting_key'=>'x','owner_token'=>str_repeat('a',64)]), $db = new FenceDb());
assertRejectsBeforeWrites('desired missing fence', fn() => (new \Leikwan\Master\Support\LeigodOnlyDesiredStateFenceService(new FenceDb()))->writeDesiredState(['scope'=>'global','forward_id'=>1,'owner_token'=>str_repeat('a',64)]), $db = new FenceDb());
assertRejectsBeforeWrites('artifact pointer missing artifact hash', fn() => (new \Leikwan\Master\Support\LeigodOnlyArtifactPointerService(new FenceDb()))->writePointerState(['scope'=>'global','forward_id'=>1,'owner_token'=>str_repeat('a',64),'fencing_token'=>str_repeat('b',64)]), $db = new FenceDb());
assertRejectsBeforeWrites('queue missing fence', fn() => (new \Leikwan\Master\Support\LeigodOnlyFencedPublishQueue(new FenceDb()))->enqueue(['scope'=>'global','forward_id'=>1,'owner_token'=>str_repeat('a',64)]), $db = new FenceDb());
assertRejectsBeforeWrites('executor missing publish fence', fn() => (new \Leikwan\Master\Support\LeigodOnlyFencedPublishExecutor(new FenceDb(), new class { public function publishFenced(array $job): void {} }))->execute(['scope'=>'global','job_hash'=>str_repeat('c',64),'owner_token'=>str_repeat('a',64),'fencing_token'=>str_repeat('b',64)]), $db = new FenceDb());

function assertSqlHasFence(string $label, array $calls): void { $joined = json_encode($calls); foreach (['scope','owner_token','fencing_token'] as $needle) if (!str_contains($joined, $needle)) fail($label . ' missing first-class fence ' . $needle . ': ' . $joined); }

$db = new FenceDb(); (new \Leikwan\Master\Support\LeigodOnlyFencedPublishQueue($db))->enqueue(['scope'=>'global','forward_id'=>47,'desired_generation'=>9,'desired_state_hash'=>str_repeat('d',64),'artifact_snapshot_hash'=>str_repeat('e',64),'publish_fence_token'=>str_repeat('f',64),'owner_token'=>str_repeat('a',64),'fencing_token'=>str_repeat('b',64),'plan_hash'=>str_repeat('1',64),'batch_hash'=>str_repeat('2',64),'affected_forward_ids'=>[47]]); assertSqlHasFence('queue enqueue', $db->calls); $joined=json_encode($db->calls); foreach (['plan_hash','batch_hash','affected_forward_ids_json','artifact_snapshot_hash','publish_fence_token'] as $needle) if (!str_contains($joined,$needle)) fail('queue missing ' . $needle);

$db = new FenceDb(); (new \Leikwan\Master\Support\LeigodOnlyDesiredStateFenceService($db))->writeDesiredState(['scope'=>'global','forward_id'=>47,'desired_generation'=>9,'desired_state_hash'=>str_repeat('d',64),'artifact_snapshot_hash'=>str_repeat('e',64),'owner_token'=>str_repeat('a',64),'fencing_token'=>str_repeat('b',64),'plan_hash'=>str_repeat('1',64),'batch_hash'=>str_repeat('2',64),'affected_forward_ids'=>[47],'runtime_contract_json'=>['backend_mode'=>'leigod_mode','client_mode'=>'bond','runtime_preference'=>'single','bond_ingress_mode'=>'plain_tcp_mux','active_family'=>'standalone_bond'],'existing_desired_state'=>['mode'=>'old']]); assertSqlHasFence('desired write', $db->calls); $joined=json_encode($db->calls); foreach (['leigod_only_forward_desired_state_fences','plan_hash','batch_hash','affected_forward_ids_json','artifact_snapshot_hash'] as $needle) if (!str_contains($joined,$needle)) fail('desired missing ' . $needle);

echo "ok\n";
