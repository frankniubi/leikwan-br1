<?php
declare(strict_types=1);

function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }
$repoRoot = dirname(__DIR__, 2);
foreach (['/master/src/Support/LeigodNormalizationActivationContext.php','/master/src/Support/LeigodOnlyNormalizationActivationService.php'] as $p) require_once $repoRoot . $p;
function h(string $c): string { return str_repeat($c, 64); }
function runtimeRow(array $patch=[]): array { return array_replace(['id'=>47,'backend_mode'=>'leigod_mode','client_mode'=>'bond','runtime_preference'=>'single','bond_ingress_mode'=>'plain_tcp_mux','active_family'=>'standalone_bond'], $patch); }

final class NormDb {
    public array $rows=[]; public array $calls=[]; public int $updates=0;
    public function fetchAll(string $sql, array $params=[]): array { $this->assertScalarParams($sql,$params); $this->calls[]=['fetchAll',$sql,$params]; return array_values(array_filter($this->rows, fn($r)=>in_array((int)$r['id'], $this->idsFromParams($params), true))); }
    public function fetchOne(string $sql, array $params=[]): ?array { $this->assertScalarParams($sql,$params); $this->calls[]=['fetchOne',$sql,$params]; return null; }
    public function execute(string $sql, array $params=[]): int { $this->assertScalarParams($sql,$params); $this->calls[]=['execute',$sql,$params]; $upper = strtoupper($sql); if (str_contains($upper,'SET BACKEND_MODE') || str_contains($upper,'SET CLIENT_MODE') || str_contains($upper,'SET RUNTIME_PREFERENCE') || str_contains($upper,'SET BOND_INGRESS_MODE')) fail('normalization must not rewrite legacy runtime mode fields'); $ids=$this->idsFromParams($params); $count=0; foreach($this->rows as $row){ if(in_array((int)$row['id'],$ids,true)){ $count++; }} $this->updates += $count; return $count; }
    private function assertScalarParams(string $sql, array $params): void { if (str_contains($sql, 'IN (:affected_forward_ids)')) fail('normalization must expand IN-list placeholders: '.$sql); foreach ($params as $key=>$value) { if (is_array($value)) fail('normalization SQL param must be scalar: '.$key); } }
    private function idsFromParams(array $params): array { $ids=[]; foreach($params as $key=>$value){ if(str_starts_with((string)$key,'affected_forward_id_')) $ids[]=(int)$value; } sort($ids,SORT_NUMERIC); return $ids; }
}
final class NormLock { public bool $held=true; public array $calls=[]; public function requireHeld(string $scope,string $key,string $owner,string $fence): void { $this->calls[]=compact('scope','key','owner','fence'); if(!$this->held) throw new RuntimeException('rollout_lock_not_held'); } }
final class NormArtifact { public array $result=['ok'=>true]; public array $calls=[]; public function evaluate(array $scope): array { $this->calls[]=$scope; return $this->result; } }
function ctx(array $patch=[]): Leikwan\Master\Support\LeigodNormalizationActivationContext { return Leikwan\Master\Support\LeigodNormalizationActivationContext::fromArray(array_replace(['scope'=>'global','plan_hash'=>h('a'),'current_plan_hash'=>h('a'),'owner_token'=>h('b'),'fencing_token'=>h('c'),'artifact_snapshot_hash'=>h('d'),'affected_forward_ids'=>[47,65],'expected_artifact_pins'=>['client_agent'=>h('e')]], $patch)); }
function applyNorm(NormDb $db, ?NormLock $lock=null, ?NormArtifact $artifact=null, ?Leikwan\Master\Support\LeigodNormalizationActivationContext $ctx=null): array { $svc = new Leikwan\Master\Support\LeigodOnlyNormalizationActivationService($db, $lock ?? new NormLock(), $artifact ?? new NormArtifact()); return $svc->apply($ctx ?? ctx()); }

$db = new NormDb(); $db->rows=[runtimeRow(['id'=>47]), runtimeRow(['id'=>65])]; $lock=new NormLock(); $artifact=new NormArtifact(); $result=applyNorm($db,$lock,$artifact);
if (($result['ok']??false)!==true || $db->updates!==2) fail('expected normalization activation writes only locked rows: '.json_encode($result));
$fetchCalls=array_values(array_filter($db->calls, fn($c)=>$c[0]==='fetchAll'));
$executeCalls=array_values(array_filter($db->calls, fn($c)=>$c[0]==='execute'));
$fetchSql=(string)($fetchCalls[0][1]??'');
$execSql=(string)($executeCalls[0][1]??'');
foreach(['id IN (:affected_forward_id_0, :affected_forward_id_1)','FOR UPDATE'] as $needle) if(!str_contains($fetchSql,$needle)) fail('missing scalar locked SELECT predicate '.$needle.': '.$fetchSql);
if (str_contains($fetchSql,'owner_token') || str_contains($fetchSql,'fencing_token')) fail('forward_combinations SELECT must not predicate nonexistent fence columns: '.$fetchSql);
foreach(['owner_token','fencing_token','backend_mode = :backend_mode','client_mode = :client_mode','runtime_preference = :runtime_preference','bond_ingress_mode = :bond_ingress_mode','active_family = :active_family'] as $needle) if(!str_contains($execSql,$needle)) fail('missing event write predicate/param '.$needle.': '.$execSql);
if (count($lock->calls)!==1 || count($artifact->calls)!==1) fail('expected lock and artifact gate before write');

foreach ([['backend_mode'=>'current_mode','code'=>'deprecated_forward_combination_backend_mode'],['backend_mode'=>'cf_mode','code'=>'deprecated_forward_combination_backend_mode'],['backend_mode'=>'mystery','code'=>'invalid_forward_combination_backend_mode'],['runtime_preference'=>'native_mptcp','code'=>'unsupported_leigod_runtime_contract']] as $case) { $db=new NormDb(); $db->rows=[runtimeRow($case)]; $res=applyNorm($db,null,null,ctx(['affected_forward_ids'=>[47]])); if (($res['ok']??true)!==false || !in_array($case['code'],$res['blockers']??[],true) || $db->updates!==0) fail('expected blocker '.$case['code'].': '.json_encode($res)); }
$db=new NormDb(); $db->rows=[runtimeRow(['id'=>47]), runtimeRow(['id'=>999])]; $res=applyNorm($db,null,null,ctx(['affected_forward_ids'=>[47]])); if (($res['ok']??false)!==true || $db->updates!==1) fail('expected allowlist to constrain writes');
$db=new NormDb(); $db->rows=[runtimeRow(['id'=>47])]; $res=applyNorm($db,null,null,ctx(['current_plan_hash'=>h('9')])); if (($res['ok']??true)!==false || !in_array('activation_plan_hash_changed',$res['blockers']??[],true) || $db->updates!==0) fail('plan hash mismatch should block writes');
$db=new NormDb(); $db->rows=[runtimeRow(['id'=>47])]; $lock=new NormLock(); $lock->held=false; $res=applyNorm($db,$lock); if (($res['ok']??true)!==false || !in_array('rollout_lock_not_held',$res['blockers']??[],true) || $db->updates!==0) fail('missing held lock should block writes');
$db=new NormDb(); $db->rows=[runtimeRow(['id'=>47])]; $artifact=new NormArtifact(); $artifact->result=['ok'=>false,'blockers'=>['artifact_hash_mismatch']]; $res=applyNorm($db,null,$artifact); if (($res['ok']??true)!==false || !in_array('artifact_gate_failed',$res['blockers']??[],true) || $db->updates!==0) fail('artifact gate should block writes');
expectMalformed: try { Leikwan\Master\Support\LeigodNormalizationActivationContext::fromArray(['scope'=>'bad*']); } catch (Throwable $e) { $db=new NormDb(); if ($db->calls!==[]) { fail('malformed context must reject before DB'); } echo "ok\n"; return; } fail('malformed context should reject');
