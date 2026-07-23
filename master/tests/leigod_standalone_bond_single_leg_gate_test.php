<?php
declare(strict_types=1);
function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }
$repoRoot = dirname(__DIR__, 2);
require_once $repoRoot . '/master/src/Support/LeigodActivationGateContext.php';
require_once $repoRoot . '/master/src/Support/LeigodStandaloneBondActivationGateService.php';
function h(string $c): string { return str_repeat($c,64); }
function pins(): array { return ['client_agent'=>h('1'),'bond_binary'=>h('2')]; }
function runtime(array $p=[]): array { return array_replace(['backend_mode'=>'leigod_mode','client_mode'=>'bond','runtime_preference'=>'single','bond_ingress_mode'=>'plain_tcp_mux','active_family'=>'standalone_bond'], $p); }
function leg(array $p=[]): array { return array_replace(['forward_id'=>60,'desired_generation'=>8,'node_id'=>6,'endpoint_id'=>106,'protocol'=>'tcp','logical_leg_key'=>'bond:60:tcp:6:106','status'=>'active'], $p); }
final class SlDb { public array $calls=[],$lifecycle=[],$cap=[],$proof=[],$rej=[]; public function fetchAll(string $sql,array $params=[]): array { $this->calls[]=['fetchAll',$sql,$params]; if(str_contains($sql,'lifecycle'))return $this->lifecycle; if(str_contains($sql,'capability'))return $this->cap; if(str_contains($sql,'rejections'))return $this->rej; if(str_contains($sql,'activation_proofs'))return $this->proof; return []; } public function fetchOne(string $sql,array $params=[]): ?array { $r=$this->fetchAll($sql,$params); return $r[0]??null; } }
final class SlAssign { public function __construct(public array $current, public array $legs){} public function loadCurrent(int $f, ?int $c, ?int $d, string $s='global'): ?array { return $this->current; } public function desiredLegRows(int $f, ?int $c, ?int $d, string $s='global'): array { return $this->legs; } }
final class SlArtifact { public function evaluate(array $scope): array { return ['ok'=>true]; } }
final class SlLock { public function requireHeld(string $s,string $k,string $o,string $f): void {} }
function ctx(string $legHash): \Leikwan\Master\Support\LeigodActivationGateContext { return \Leikwan\Master\Support\LeigodActivationGateContext::fromArray(['scope'=>'global','forward_id'=>60,'client_id'=>22,'client_device_session_id'=>33,'desired_generation'=>8,'assignment_id'=>800,'assignment_hash'=>h('a'),'desired_leg_set_hash'=>$legHash,'platform_family'=>'linux','write_mode'=>'activation','flags'=>['activation_enabled'=>true],'settings'=>['allowlisted_forward_ids'=>[60],'allowlisted_client_ids'=>[22],'allowlisted_device_session_ids'=>[33]],'expected_artifact_pins'=>pins(),'owner_token'=>h('c'),'fencing_token'=>h('d')]); }
function db(?array $evidence=null): SlDb { $db=new SlDb(); $db->lifecycle[]=['scope'=>'global','forward_id'=>60,'client_id'=>22,'client_device_session_id'=>33,'desired_generation'=>8,'assignment_hash'=>h('a'),'desired_leg_set_hash'=>'','platform_family'=>'linux','snapshot_state'=>'accepted','created_at_unix'=>999,'observed_usable_leg_count'=>$evidence===null?1:($evidence['observed_usable_leg_count']??1),'single_leg_evidence_json'=>$evidence===null?null:json_encode($evidence)]; $db->cap[]=['scope'=>'global','forward_id'=>60,'client_id'=>22,'client_device_session_id'=>33,'assignment_id'=>800,'desired_generation'=>8,'assignment_hash'=>h('a'),'platform_family'=>'linux','report_state'=>'accepted','proof_source'=>'client','created_at_unix'=>999,'artifact_hashes_json'=>json_encode(pins())]; return $db; }
function runCase(SlDb $db,array $current,array $legs,string $legHash): array { $db->lifecycle[0]['desired_leg_set_hash']=$legHash; return (new \Leikwan\Master\Support\LeigodStandaloneBondActivationGateService($db,new SlAssign($current,$legs),new SlArtifact(),new SlLock(),1000))->gate(ctx($legHash)); }
function evidence(array $p=[]): array { return array_replace(['evidence_type'=>'degraded_single_leg','application_tcp_socket_id'=>'app-1','previous_application_tcp_socket_id'=>'app-1','standalone_bond_transport_session_id'=>'bond-1','previous_standalone_bond_transport_session_id'=>'bond-1','sidecar_target_tcp_connection_id'=>'target-1','previous_sidecar_target_tcp_connection_id'=>'target-1','desired_generation'=>8,'assignment_hash'=>h('a'),'desired_leg_set_hash'=>'','bond_protocol_family'=>'standalone_bond','observed_usable_leg_count'=>1], $p); }
$legs=[leg()]; $legHash=\Leikwan\Master\Support\StandaloneBondLegPolicy::canonicalLegSetHash(60,8,$legs); $current=['id'=>800,'forward_id'=>60,'client_id'=>22,'client_device_session_id'=>33,'desired_generation'=>8,'assignment_hash'=>h('a'),'desired_leg_set_hash'=>$legHash,'platform_family'=>'linux','active_family'=>'standalone_bond','runtime_contract_json'=>json_encode(runtime())];
$r=runCase(db(),$current,$legs,$legHash); if(!($r['ok']??false)) fail('fresh lifecycle/capability single leg should pass without proofs: '.json_encode($r));
$ev=evidence(['desired_leg_set_hash'=>$legHash]); $r=runCase(db($ev),$current,$legs,$legHash); if(!($r['ok']??false)) fail('valid degraded_single_leg should pass: '.json_encode($r));
$cases=[
 'application reconnect'=>evidence(['desired_leg_set_hash'=>$legHash,'previous_application_tcp_socket_id'=>'old']),
 'transport replacement'=>evidence(['desired_leg_set_hash'=>$legHash,'previous_standalone_bond_transport_session_id'=>'old']),
 'sidecar target replacement'=>evidence(['desired_leg_set_hash'=>$legHash,'previous_sidecar_target_tcp_connection_id'=>'old']),
 'generation drift'=>evidence(['desired_leg_set_hash'=>$legHash,'desired_generation'=>9]),
 'hash drift'=>evidence(['desired_leg_set_hash'=>h('9')]),
 'native mptcp'=>evidence(['desired_leg_set_hash'=>$legHash,'bond_protocol_family'=>'native_mptcp']),
 'bond fallback'=>evidence(['desired_leg_set_hash'=>$legHash,'bond_protocol_family'=>'bond_fallback']),
 'cloudflare marker'=>evidence(['desired_leg_set_hash'=>$legHash,'transport_marker'=>'Cloudflare']),
 'wss marker'=>evidence(['desired_leg_set_hash'=>$legHash,'transport_marker'=>'WSS']),
 'classic sidecar marker'=>evidence(['desired_leg_set_hash'=>$legHash,'transport_marker'=>'classic-sidecar']),
 'zero leg evidence'=>evidence(['desired_leg_set_hash'=>$legHash,'observed_usable_leg_count'=>0]),
];
foreach($cases as $label=>$ev){ $r=runCase(db($ev),$current,$legs,$legHash); if(($r['ok']??false) || !in_array('single_leg_evidence_invalid',$r['blockers']??[],true)) fail($label.' should block single leg evidence: '.json_encode($r)); }
echo "ok\n";
