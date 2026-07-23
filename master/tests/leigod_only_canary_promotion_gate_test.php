<?php
declare(strict_types=1);
function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }
$repoRoot = dirname(__DIR__, 2);
require_once $repoRoot . '/master/src/Support/LeigodOnlyCanaryCampaignService.php';
function h(string $c): string { return str_repeat($c,64); }
final class CpDb { public array $calls=[]; public array $affected=[]; public function insert(string $table,array $data): int { $this->calls[]=['insert',$table,$data]; return 123; } public function execute(string $sql,array $params=[]): int { $this->calls[]=['execute',$sql,$params]; return array_shift($this->affected) ?? 1; } public function fetchOne(string $sql,array $params=[]): ?array { $this->calls[]=['fetchOne',$sql,$params]; return null; } }
final class CpGate { public array $calls=[]; public array $result=['ok'=>true,'promotion_ready'=>true,'policy_state'=>['outcome'=>'speed_plus_eligible'],'artifact_gate'=>['ok'=>true]]; public function gate(object $ctx): array { $this->calls[]=$ctx; return $this->result; } }
final class CpMutator { public int $calls=0; public function activate(array $r): void { $this->calls++; } }
function slave(int $node): array { return ['node_id'=>$node,'endpoint_id'=>100+$node,'usable'=>true]; }
$pred=['cohort'=>0,'status'=>'success','observing'=>false,'blockers'=>[],'artifact_version_pins_json'=>json_encode(['client_agent'=>h('1')]),'generation_fences_json'=>json_encode(['desired_generation'=>5]),'artifact_snapshot_hash'=>h('e'),'fencing_token'=>h('f'),'leg_policy_hash'=>h('l'),'scope'=>'global'];
$db=new CpDb(); $gate=new CpGate(); $svc=new \Leikwan\Master\Support\LeigodOnlyCanaryCampaignService($db,$gate,new CpMutator());
$baseCampaign=['scope'=>'global','cohort'=>1,'predecessor_snapshot'=>$pred,'expected_predecessor_artifact_version_pins_json'=>json_encode(['client_agent'=>h('1')]),'expected_predecessor_fencing_token'=>h('f'),'expected_predecessor_leg_policy_hash'=>h('l'),'artifact_version_pins_json'=>json_encode(['client_agent'=>h('1')]),'generation_fences_json'=>json_encode(['desired_generation'=>6])];
$r=$svc->evaluateForward($baseCampaign,['forward_id'=>10,'platform_family'=>'linux','leg_policy'=>'shared','slaves'=>[slave(1)]]); if(!($r['promotion_ready']??false)) fail('successful immutable predecessor should allow promotion: '.json_encode($r));
foreach([
 'missing'=>null,
 'wrong'=>array_replace($pred,['cohort'=>99]),
 'not successful'=>array_replace($pred,['status'=>'failed']),
 'still observing'=>array_replace($pred,['observing'=>true]),
 'blockers'=>array_replace($pred,['blockers'=>['x']]),
 'pin drift'=>array_replace($pred,['artifact_version_pins_json'=>json_encode(['client_agent'=>h('9')])]),
 'fence drift'=>array_replace($pred,['fencing_token'=>h('9')]),
 'leg policy drift'=>array_replace($pred,['leg_policy_hash'=>h('9')]),
 'scope drift'=>array_replace($pred,['scope'=>'other']),
] as $label=>$snapshot){ $campaign=$baseCampaign; $campaign['predecessor_snapshot']=$snapshot; $r=$svc->evaluateForward($campaign,['forward_id'=>11,'platform_family'=>'linux','leg_policy'=>'shared','slaves'=>[slave(1)]]); if(($r['promotion_ready']??false)||!in_array('predecessor_snapshot_not_immutable_success',$r['blockers']??[],true)) fail($label.' predecessor should block: '.json_encode($r)); }
$gate=new CpGate(); $svc=new \Leikwan\Master\Support\LeigodOnlyCanaryCampaignService(new CpDb(),$gate,new CpMutator()); $r=$svc->evaluateForward(['scope'=>'global','cohort'=>1],['forward_id'=>12,'platform_family'=>'android-bond','leg_policy'=>'shared','slaves'=>[slave(1)]]); if(($r['promotion_ready']??false)||!in_array('unsupported_platform_family:android-bond',$r['blockers']??[],true)||count($gate->calls)!==0) fail('android-bond must fail before artifact probes/locks/writes: '.json_encode($r));
foreach(['native_mptcp','leikwan-mptcp-relay'] as $diag){ $gate=new CpGate(); $gate->result=['ok'=>true,'promotion_ready'=>true,'policy_state'=>['outcome'=>'speed_plus_eligible'],'artifact_gate'=>['ok'=>true],'diagnostic_artifact_family'=>$diag,'capability_family'=>$diag]; $svc=new \Leikwan\Master\Support\LeigodOnlyCanaryCampaignService(new CpDb(),$gate,new CpMutator()); $r=$svc->evaluateForward(['scope'=>'global','cohort'=>2,'predecessor_snapshot'=>array_replace($pred,['cohort'=>1])],['forward_id'=>13,'platform_family'=>'linux','slaves'=>[slave(1),slave(2)]]); if(($r['promotion_ready']??false)||!in_array('diagnostic_family_not_promotable',$r['blockers']??[],true)) fail($diag.' diagnostics cannot promote: '.json_encode($r)); }
$db=new CpDb(); $svc=new \Leikwan\Master\Support\LeigodOnlyCanaryCampaignService($db,new CpGate(),new CpMutator()); $write=$svc->writeStatus(['scope'=>'global','campaign_id'=>77,'campaign_hash'=>h('a'),'write_hash'=>h('b'),'plan_hash'=>h('c'),'batch_hash'=>h('d'),'owner_token'=>h('o'),'fencing_token'=>h('f'),'affected_forward_ids'=>[10,11],'artifact_snapshot_hash'=>h('e')],['status'=>'success','promotion_ready'=>true]); if(($write['affected_rows']['insert']??0)!==1||($write['affected_rows']['update']??0)!==1) fail('status writes must return exact affected row counts: '.json_encode($write)); $joined=json_encode($db->calls); foreach(['scope','plan_hash','batch_hash','owner_token','fencing_token','affected_forward_ids_json','artifact_snapshot_hash','campaign_hash','write_hash'] as $needle) if(!str_contains($joined,$needle)) fail('fenced canary status write missing '.$needle);
echo "ok\n";
