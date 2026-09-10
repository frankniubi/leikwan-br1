<?php
declare(strict_types=1);
function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }
$repoRoot = dirname(__DIR__, 2);
require_once $repoRoot . '/master/src/Support/LeigodActivationGateContext.php';
require_once $repoRoot . '/master/src/Support/LeigodStandaloneBondActivationGateService.php';
function h(string $c): string { return str_repeat($c, 64); }
function pins(): array { return ['client_agent'=>h('1'),'bond_binary'=>h('2')]; }
function runtime(): array { return ['backend_mode'=>'leigod_mode','client_mode'=>'bond','runtime_preference'=>'single','bond_ingress_mode'=>'plain_tcp_mux','active_family'=>'standalone_bond']; }
function leg(int $n): array { return ['forward_id'=>50,'desired_generation'=>3,'node_id'=>$n,'endpoint_id'=>100+$n,'protocol'=>'tcp','logical_leg_key'=>'bond:50:tcp:' . $n . ':' . (100+$n),'status'=>'active']; }
final class UdDb { public array $calls=[],$lifecycle=[],$cap=[],$proof=[],$rej=[]; public function fetchAll(string $sql,array $params=[]): array { $this->calls[]=['fetchAll',$sql,$params]; if(str_contains($sql,'lifecycle'))return $this->lifecycle; if(str_contains($sql,'capability'))return $this->cap; if(str_contains($sql,'rejections'))return $this->rej; if(str_contains($sql,'activation_proofs'))return array_values(array_filter($this->proof, fn($r)=>($r['direction']??'')===($params['direction']??''))); return []; } public function fetchOne(string $sql,array $params=[]): ?array { $r=$this->fetchAll($sql,$params); return $r[0]??null; } }
final class UdAssign { public function __construct(public array $current, public array $legs){} public function loadCurrent(int $f, ?int $c, ?int $d, string $s='global'): ?array { return $this->current; } public function desiredLegRows(int $f, ?int $c, ?int $d, string $s='global'): array { return $this->legs; } }
final class UdArtifact { public function evaluate(array $scope): array { return ['ok'=>true]; } }
final class UdLock { public function requireHeld(string $s,string $k,string $o,string $f): void {} }
function ctx(string $legHash): \Leikwan\Master\Support\LeigodActivationGateContext { return \Leikwan\Master\Support\LeigodActivationGateContext::fromArray(['scope'=>'global','forward_id'=>50,'client_id'=>20,'client_device_session_id'=>30,'desired_generation'=>3,'assignment_id'=>900,'assignment_hash'=>h('a'),'desired_leg_set_hash'=>$legHash,'platform_family'=>'linux','write_mode'=>'activation','flags'=>['activation_enabled'=>true],'settings'=>['allowlisted_forward_ids'=>[50],'allowlisted_client_ids'=>[20],'allowlisted_device_session_ids'=>[30],'upload_min_mbps'=>100,'download_min_mbps'=>150],'expected_artifact_pins'=>pins(),'owner_token'=>h('c'),'fencing_token'=>h('d')]); }
function db(array $proofs): UdDb { $db=new UdDb(); $db->lifecycle[]=['scope'=>'global','forward_id'=>50,'client_id'=>20,'client_device_session_id'=>30,'desired_generation'=>3,'assignment_hash'=>h('a'),'desired_leg_set_hash'=>'','platform_family'=>'linux','snapshot_state'=>'accepted','created_at_unix'=>999]; $db->cap[]=['scope'=>'global','forward_id'=>50,'client_id'=>20,'client_device_session_id'=>30,'assignment_id'=>900,'desired_generation'=>3,'assignment_hash'=>h('a'),'platform_family'=>'linux','report_state'=>'accepted','proof_source'=>'client','created_at_unix'=>999,'artifact_hashes_json'=>json_encode(pins())]; $db->proof=$proofs; return $db; }
function runCase(UdDb $db, array $current, array $legs, string $legHash): array { $db->lifecycle[0]['desired_leg_set_hash']=$legHash; return (new \Leikwan\Master\Support\LeigodStandaloneBondActivationGateService($db,new UdAssign($current,$legs),new UdArtifact(),new UdLock(),1000))->gate(ctx($legHash)); }
function proof(string $direction, float $mbps, int $created=999): array { return ['scope'=>'global','forward_id'=>50,'client_id'=>20,'client_device_session_id'=>30,'assignment_id'=>900,'desired_generation'=>3,'assignment_hash'=>h('a'),'platform_family'=>'linux','direction'=>$direction,'proof_state'=>'accepted','proof_source'=>'client_runtime_telemetry','created_at_unix'=>$created,'mbps'=>$mbps]; }
$legs=[leg(1),leg(2)]; $legHash=\Leikwan\Master\Support\StandaloneBondLegPolicy::canonicalLegSetHash(50,3,$legs); $current=['id'=>900,'forward_id'=>50,'client_id'=>20,'client_device_session_id'=>30,'desired_generation'=>3,'assignment_hash'=>h('a'),'desired_leg_set_hash'=>$legHash,'platform_family'=>'linux','active_family'=>'standalone_bond','runtime_contract_json'=>json_encode(runtime())];
$result=runCase(db([proof('upload',120),proof('download',170)]),$current,$legs,$legHash); if(!($result['ok']??false)) fail('independent upload and download proofs should pass: '.json_encode($result));
foreach ([
    'missing upload'=>[db([proof('download',170)]),'upload_gate_failed'],
    'missing download'=>[db([proof('upload',120)]),'download_gate_failed'],
    'stale upload'=>[db([proof('upload',120,900),proof('download',170)]),'upload_gate_failed'],
    'stale download'=>[db([proof('upload',120),proof('download',170,900)]),'download_gate_failed'],
    'below upload'=>[db([proof('upload',99),proof('download',170)]),'upload_gate_failed'],
    'below download'=>[db([proof('upload',120),proof('download',149)]),'download_gate_failed'],
    'upload cannot satisfy download'=>[db([proof('upload',200),proof('upload',220)]),'download_gate_failed'],
    'download cannot satisfy upload'=>[db([proof('download',220),proof('download',230)]),'upload_gate_failed'],
] as $label=>$case) { $r=runCase($case[0],$current,$legs,$legHash); if(!in_array($case[1],$r['blockers']??[],true)) fail($label.' expected '.$case[1].': '.json_encode($r)); }
echo "ok\n";
