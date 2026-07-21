<?php
declare(strict_types=1);
function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }
$repoRoot = dirname(__DIR__, 2);
require_once $repoRoot . '/master/src/Support/LeigodActivationGateContext.php';
require_once $repoRoot . '/master/src/Support/LeigodStandaloneBondActivationGateService.php';
function h(string $c): string { return str_repeat($c,64); }
function pins(): array { return ['client_agent'=>h('1'),'bond_binary'=>h('2')]; }
function runtime(): array { return ['backend_mode'=>'leigod_mode','client_mode'=>'bond','runtime_preference'=>'single','bond_ingress_mode'=>'plain_tcp_mux','active_family'=>'standalone_bond']; }
function leg(int $n): array { return ['forward_id'=>70,'desired_generation'=>4,'node_id'=>$n,'endpoint_id'=>100+$n,'protocol'=>'tcp','logical_leg_key'=>'bond:70:tcp:' . $n . ':' . (100+$n),'status'=>'active']; }
final class TpDb { public array $lifecycle=[],$cap=[],$proof=[],$rej=[]; public function fetchAll(string $sql,array $params=[]): array { if(str_contains($sql,'lifecycle'))return $this->lifecycle; if(str_contains($sql,'capability'))return $this->cap; if(str_contains($sql,'rejections'))return $this->rej; if(str_contains($sql,'activation_proofs'))return array_values(array_filter($this->proof,fn($r)=>($r['direction']??'')===($params['direction']??''))); return []; } public function fetchOne(string $sql,array $params=[]): ?array { $r=$this->fetchAll($sql,$params); return $r[0]??null; } }
final class TpAssign { public function __construct(public array $current, public array $legs){} public function loadCurrent(int $f, ?int $c, ?int $d, string $s='global'): ?array { return $this->current; } public function desiredLegRows(int $f, ?int $c, ?int $d, string $s='global'): array { return $this->legs; } }
final class TpArtifact { public function evaluate(array $scope): array { return ['ok'=>true]; } }
final class TpLock { public function requireHeld(string $s,string $k,string $o,string $f): void {} }
function ctx(string $legHash): \Leikwan\Master\Support\LeigodActivationGateContext { return \Leikwan\Master\Support\LeigodActivationGateContext::fromArray(['scope'=>'global','forward_id'=>70,'client_id'=>24,'client_device_session_id'=>34,'desired_generation'=>4,'assignment_id'=>700,'assignment_hash'=>h('a'),'desired_leg_set_hash'=>$legHash,'platform_family'=>'linux','write_mode'=>'activation','flags'=>['activation_enabled'=>true],'settings'=>['allowlisted_forward_ids'=>[70],'allowlisted_client_ids'=>[24],'allowlisted_device_session_ids'=>[34],'upload_min_mbps'=>10,'download_min_mbps'=>10],'expected_artifact_pins'=>pins(),'owner_token'=>h('c'),'fencing_token'=>h('d')]); }
function db(array $capRows, array $proofRows): TpDb { $db=new TpDb(); $db->lifecycle[]=['scope'=>'global','forward_id'=>70,'client_id'=>24,'client_device_session_id'=>34,'desired_generation'=>4,'assignment_hash'=>h('a'),'desired_leg_set_hash'=>'','platform_family'=>'linux','snapshot_state'=>'accepted','created_at_unix'=>999]; $db->cap=$capRows; $db->proof=$proofRows; return $db; }
function cap(string $source='client', array $hashes=null, int $created=999): array { return ['scope'=>'global','forward_id'=>70,'client_id'=>24,'client_device_session_id'=>34,'assignment_id'=>700,'desired_generation'=>4,'assignment_hash'=>h('a'),'platform_family'=>'linux','report_state'=>'accepted','proof_source'=>$source,'created_at_unix'=>$created,'artifact_hashes_json'=>json_encode($hashes??pins())]; }
function proof(string $direction,string $source='client_runtime_telemetry'): array { return ['scope'=>'global','forward_id'=>70,'client_id'=>24,'client_device_session_id'=>34,'assignment_id'=>700,'desired_generation'=>4,'assignment_hash'=>h('a'),'platform_family'=>'linux','direction'=>$direction,'proof_state'=>'accepted','proof_source'=>$source,'created_at_unix'=>999,'mbps'=>50]; }
$legs=[leg(1),leg(2)]; $legHash=\Leikwan\Master\Support\StandaloneBondLegPolicy::canonicalLegSetHash(70,4,$legs); $current=['id'=>700,'forward_id'=>70,'client_id'=>24,'client_device_session_id'=>34,'desired_generation'=>4,'assignment_hash'=>h('a'),'desired_leg_set_hash'=>$legHash,'platform_family'=>'linux','active_family'=>'standalone_bond','runtime_contract_json'=>json_encode(runtime())];
$run=function(TpDb $db) use($current,$legs,$legHash){ $db->lifecycle[0]['desired_leg_set_hash']=$legHash; return (new \Leikwan\Master\Support\LeigodStandaloneBondActivationGateService($db,new TpAssign($current,$legs),new TpArtifact(),new TpLock(),1000))->gate(ctx($legHash)); };
$r=$run(db([cap('client')],[proof('upload'),proof('download')])); if(!($r['ok']??false)) fail('client proof should pass: '.json_encode($r));
foreach([
 'node capability no client'=>db([cap('node')],[proof('upload'),proof('download')]),
 'sidecar capability no client'=>db([cap('sidecar')],[proof('upload'),proof('download')]),
 'missing capability'=>db([],[proof('upload'),proof('download')]),
 'expired capability'=>db([cap('client',null,900)],[proof('upload'),proof('download')]),
] as $label=>$db){ $r=$run($db); if(!in_array('fresh_client_capability_missing',$r['blockers']??[],true)) fail($label.' expected fresh_client_capability_missing: '.json_encode($r)); }
$r=$run(db([cap('client',['client_agent'=>h('9'),'bond_binary'=>h('2')])],[proof('upload'),proof('download')])); if(!in_array('capability_reported_hash_mismatch',$r['blockers']??[],true)) fail('hash drift expected mismatch: '.json_encode($r));
$r=$run(db([cap('client')],[proof('upload','BondLegReputationService'),proof('download','BondLegReputationService')])); if(!in_array('upload_gate_failed',$r['blockers']??[],true)||!in_array('download_gate_failed',$r['blockers']??[],true)) fail('BondLegReputationService-only rows must not count: '.json_encode($r));
echo "ok\n";
