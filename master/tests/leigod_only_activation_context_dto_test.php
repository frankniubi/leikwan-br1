<?php
declare(strict_types=1);

function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }
$repoRoot = dirname(__DIR__, 2);
require_once $repoRoot . '/master/src/Support/LeigodNormalizationActivationContext.php';
require_once $repoRoot . '/master/src/Support/LeigodRetirementActivationContext.php';

function h(string $c): string { return str_repeat($c, 64); }
function expectThrows(string $label, callable $fn, string $needle): void {
    try { $fn(); } catch (Throwable $e) { if (!str_contains($e->getMessage(), $needle)) fail($label . ' wrong message: ' . $e->getMessage()); return; }
    fail($label . ' should throw');
}

$normPayload = [
    'scope'=>'global','plan_hash'=>h('a'),'current_plan_hash'=>h('a'),'owner_token'=>h('b'),'fencing_token'=>h('c'),
    'artifact_snapshot_hash'=>h('d'),'affected_forward_ids'=>[47, 65],
    'expected_artifact_pins'=>['client_agent'=>h('e'),'slave_agent'=>h('f')],
];
$norm = Leikwan\Master\Support\LeigodNormalizationActivationContext::fromArray($normPayload);
if ($norm->scope !== 'global' || $norm->affected_forward_ids !== [47,65] || $norm->expected_artifact_pins['client_agent'] !== h('e')) fail('normalization dto did not preserve validated fields');
$ref = new ReflectionClass($norm);
if (!$ref->isFinal() || $ref->getConstructor()?->isPublic()) fail('normalization dto must be final with non-public constructor');
expectThrows('normalization malformed scope', fn() => Leikwan\Master\Support\LeigodNormalizationActivationContext::fromArray(array_replace($normPayload, ['scope'=>'../broad'])), 'malformed_scope');
expectThrows('normalization malformed hash', fn() => Leikwan\Master\Support\LeigodNormalizationActivationContext::fromArray(array_replace($normPayload, ['plan_hash'=>'not-a-hash'])), 'malformed_plan_hash');
expectThrows('normalization empty allowlist', fn() => Leikwan\Master\Support\LeigodNormalizationActivationContext::fromArray(array_replace($normPayload, ['affected_forward_ids'=>[]])), 'malformed_affected_forward_ids');
expectThrows('normalization bad owner', fn() => Leikwan\Master\Support\LeigodNormalizationActivationContext::fromArray(array_replace($normPayload, ['owner_token'=>'short'])), 'malformed_owner_token');

$retPayload = [
    'scope'=>'global','plan_hash'=>h('a'),'current_plan_hash'=>h('a'),'batch_hash'=>h('1'),'current_batch_hash'=>h('1'),
    'owner_token'=>h('b'),'fencing_token'=>h('c'),'artifact_snapshot_hash'=>h('d'),'expected_artifact_pins'=>['client_agent'=>h('e')],
    'affected_forward_ids'=>[47],'retirement_forward_ids'=>[47],'cleanup_protocol'=>'forward_retirement_v1','cleanup_lease_token'=>h('6'),'cleanup_result_token'=>h('7'),'max_result_bytes'=>2048,
    'replacement_map'=>[47=>['retirement_forward_id'=>47,'replacement_forward_id'=>147,'tenant_id'=>9,'client_id'=>12,'client_device_session_id'=>99,'assignment_id'=>7001,'desired_generation'=>6,'assignment_hash'=>h('8'),'desired_leg_set_hash'=>h('9'),'active_family'=>'standalone_bond','created_at_unix'=>995,'publish_generation'=>6,'publish_fencing_token'=>h('c')]],
    'role_capabilities'=>['client'=>['role'=>'client','forward_id'=>147,'desired_generation'=>6,'assignment_hash'=>h('8'),'protocol'=>'forward_retirement_v1','version'=>'1','auth_schema'=>'lease_token_v1','result_schema'=>'retirement_result_v1','max_result_bytes'=>2048]],
    'desired_state'=>['forward_id'=>147,'backend_mode'=>'leigod_mode'],'applied_state'=>['forward_id'=>147,'manifest'=>['resources'=>[]]],
    'runtime_retirements'=>[47=>['forward_id'=>47,'resources'=>[]]],'active_keep_set'=>['forward_ids'=>[147],'resources'=>[]],
];
$ret = Leikwan\Master\Support\LeigodRetirementActivationContext::fromArray($retPayload);
if ($ret->cleanup_protocol !== 'forward_retirement_v1' || $ret->retirement_forward_ids !== [47]) fail('retirement dto did not preserve validated fields');
$ref = new ReflectionClass($ret);
if (!$ref->isFinal() || $ref->getConstructor()?->isPublic()) fail('retirement dto must be final with non-public constructor');
expectThrows('retirement malformed fence', fn() => Leikwan\Master\Support\LeigodRetirementActivationContext::fromArray(array_replace($retPayload, ['fencing_token'=>''])), 'malformed_fencing_token');
expectThrows('retirement malformed artifact', fn() => Leikwan\Master\Support\LeigodRetirementActivationContext::fromArray(array_replace($retPayload, ['artifact_snapshot_hash'=>'bad'])), 'malformed_artifact_snapshot_hash');
expectThrows('retirement bad protocol', fn() => Leikwan\Master\Support\LeigodRetirementActivationContext::fromArray(array_replace($retPayload, ['cleanup_protocol'=>'rm_rf'])), 'unsupported_cleanup_protocol');
expectThrows('retirement oversize cap', fn() => Leikwan\Master\Support\LeigodRetirementActivationContext::fromArray(array_replace($retPayload, ['max_result_bytes'=>999999])), 'oversized_result_bound');
expectThrows('retirement empty replacements', fn() => Leikwan\Master\Support\LeigodRetirementActivationContext::fromArray(array_replace($retPayload, ['replacement_map'=>[]])), 'missing_replacement_mapping');

echo "ok\n";
