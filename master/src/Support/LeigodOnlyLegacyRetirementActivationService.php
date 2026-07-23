<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

require_once __DIR__ . '/LeigodRetirementActivationContext.php';
require_once __DIR__ . '/LeigodOnlyActivationPlan.php';

final class LeigodOnlyLegacyRetirementActivationService
{
    public function __construct(
        private object $db,
        private object $lockService,
        private object $activationGate,
        private object $leaseAdapter,
        private ?int $nowUnixSeconds = null
    ) {}

    /** @return array<string,mixed> */
    public function activate(LeigodRetirementActivationContext $context): array
    {
        $blockers = $this->preflight($context);
        if ($blockers !== []) { return $this->result(false, $blockers, 0); }

        [$affectedSql, $affectedParams] = $this->idListPredicate('id', $context->affected_forward_ids, 'affected_forward_id');
        [$retirementSql, $retirementParams] = $this->idListPredicate('id', $context->retirement_forward_ids, 'retirement_forward_id');
        $targets = $this->db->fetchAll(
            "SELECT * FROM forward_combinations WHERE {$affectedSql} AND {$retirementSql} FOR UPDATE",
            array_merge($affectedParams, $retirementParams)
        );
        $targetById = [];
        foreach ($targets as $row) { $targetById[(int)($row['id'] ?? 0)] = $row; }
        foreach ($context->retirement_forward_ids as $forwardId) {
            $row = $targetById[$forwardId] ?? null;
            if (!is_array($row)) { $blockers[] = 'missing_retirement_target'; continue; }
            $backend = (string)($row['backend_mode'] ?? '');
            if (!in_array($backend, ['current_mode','cf_mode'], true)) { $blockers[] = 'non_legacy_retirement_target'; }
            $blockers = array_merge($blockers, $this->validateReplacement($context, $forwardId, $row));
            $blockers = array_merge($blockers, $this->validateRuntimeRetirement($context, $forwardId));
        }
        if ($this->activeKeepSetIsEmpty($context)) { $blockers[] = 'replacement_not_in_active_keep_set'; }
        $blockers = array_merge($blockers, $this->validateRoleCapabilities($context));
        $blockers = array_merge($blockers, $this->validateState($context));
        $gate = $this->activationGate->gate($this->gateContext($context));
        if (!is_array($gate) || !($gate['ok'] ?? false)) { $blockers[] = 'replacement_activation_gate_failed'; }
        if ($blockers !== []) { return $this->result(false, $blockers, 0); }

        $leases = [];
        $records = 0;
        $this->db->transaction(function (object $db) use ($context, &$leases, &$records): void {
            foreach ($context->retirement_forward_ids as $forwardId) {
                $mapping = $context->replacement_map[$forwardId];
                $resources = $this->subtractKeepSet($context, $context->runtime_retirements[$forwardId]['resources'] ?? []);
                $state = $resources === [] ? 'preserved' : 'cleanup_pending';
                $evidence = ['replacement'=>$mapping,'resources'=>$resources,'state'=>$state];
                $recordJson = ['plan_hash'=>$context->plan_hash,'batch_hash'=>$context->batch_hash,'retirement_forward_id'=>$forwardId,'replacement'=>$mapping,'resources'=>$resources,'cleanup_protocol'=>$context->cleanup_protocol,'state'=>$state,'evidence'=>$evidence,'desired_state'=>$context->desired_state,'applied_state'=>$context->applied_state];
                $recordHash = hash('sha256', LeigodOnlyActivationPlan::canonicalJson(['scope'=>$context->scope,'forward_id'=>$forwardId,'artifact_snapshot_hash'=>$context->artifact_snapshot_hash,'record'=>$recordJson]));
                $writeHash = hash('sha256', LeigodOnlyActivationPlan::canonicalJson(['record_hash'=>$recordHash,'owner_token'=>$context->owner_token,'fencing_token'=>$context->fencing_token]));
                $db->insert('leigod_only_retirement_activation_records', [
                    'scope'=>$context->scope,
                    'forward_id'=>$forwardId,
                    'record_hash'=>$recordHash,
                    'retirement_state'=>$state,
                    'desired_generation'=>(int)$mapping['desired_generation'],
                    'desired_state_hash'=>$this->desiredStateHash($context, $mapping),
                    'artifact_snapshot_hash'=>$context->artifact_snapshot_hash,
                    'owner_token'=>$context->owner_token,
                    'fencing_token'=>$context->fencing_token,
                    'write_hash'=>$writeHash,
                    'record_json'=>LeigodOnlyActivationPlan::canonicalJson($recordJson),
                    'activated_at'=>$this->dbNow(),
                    'retired_at'=>$resources === [] ? $this->dbNow() : null,
                ]);
                $records++;
                if ($resources !== []) {
                    $db->insert('forward_retirement_cleanup_leases', ['forward_id'=>$forwardId,'scope'=>$context->scope,'lease_token'=>$context->cleanup_lease_token,'result_token'=>$context->cleanup_result_token,'state'=>'pending','cleanup_protocol'=>$context->cleanup_protocol,'owner_token'=>$context->owner_token,'fencing_token'=>$context->fencing_token,'artifact_snapshot_hash'=>$context->artifact_snapshot_hash,'resources_json'=>LeigodOnlyActivationPlan::canonicalJson($resources)]);
                    $leases[] = ['forward_id'=>$forwardId,'resources'=>$resources,'cleanup_lease_token'=>$context->cleanup_lease_token,'cleanup_result_token'=>$context->cleanup_result_token,'max_result_bytes'=>$context->max_result_bytes,'protocol'=>$context->cleanup_protocol];
                }
            }
        });
        $this->assertNoActiveTransaction();
        $issued = [];
        foreach ($leases as $lease) { $issued[] = $this->leaseAdapter->issue($lease); }
        return ['ok'=>true,'blockers'=>[],'records'=>$records,'leases_issued'=>count($issued),'lease_results'=>$issued];
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    public function recordLeaseResult(LeigodRetirementActivationContext $context, int $forwardId, array $result): array
    {
        $this->assertNoActiveTransaction();
        $raw = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        if (strlen($raw) > $context->max_result_bytes) { return $this->result(false, ['cleanup_result_oversized'], 0); }
        if (($result['lease_token'] ?? '') !== $context->cleanup_lease_token) { return $this->result(false, ['cleanup_lease_token_mismatch'], 0); }
        if (($result['result_token'] ?? '') !== $context->cleanup_result_token) { return $this->result(false, ['cleanup_result_token_mismatch'], 0); }
        if (($result['schema'] ?? '') !== 'retirement_result_v1' || !is_array($result['resources'] ?? null)) { return $this->result(false, ['cleanup_result_schema_invalid'], 0); }
        $count = $this->db->execute("UPDATE forward_retirement_cleanup_leases SET state = :state, result_json = :result_json, completed_at = :completed_at WHERE scope = :scope AND forward_id = :forward_id AND lease_token = :lease_token AND result_token = :result_token AND owner_token = :owner_token AND fencing_token = :fencing_token AND artifact_snapshot_hash = :artifact_snapshot_hash AND state = 'pending'", ['state'=>'completed','result_json'=>$raw,'completed_at'=>$this->dbNow(),'scope'=>$context->scope,'forward_id'=>$forwardId,'lease_token'=>$context->cleanup_lease_token,'result_token'=>$context->cleanup_result_token,'owner_token'=>$context->owner_token,'fencing_token'=>$context->fencing_token,'artifact_snapshot_hash'=>$context->artifact_snapshot_hash]);
        return ['ok'=>$count === 1,'blockers'=>$count === 1 ? [] : ['fenced_result_update_failed'],'writes'=>$count];
    }

    /** @return list<string> */
    private function preflight(LeigodRetirementActivationContext $context): array
    {
        $blockers = [];
        if ($context->plan_hash !== $context->current_plan_hash) { $blockers[] = 'activation_plan_hash_changed'; }
        if ($context->batch_hash !== $context->current_batch_hash) { $blockers[] = 'activation_batch_hash_changed'; }
        if ($context->expected_artifact_pins === []) { $blockers[] = 'missing_artifact_snapshot_pins'; }
        if (!is_object($this->leaseAdapter) || !method_exists($this->leaseAdapter, 'issue')) { $blockers[] = 'cleanup_lease_adapter_unavailable'; }
        try { $this->lockService->requireHeld($context->scope, 'leigod-only-retirement', $context->owner_token, $context->fencing_token); } catch (\Throwable) { $blockers[] = 'rollout_lock_not_held'; }
        return $blockers;
    }

    /** @param array<string,mixed> $target @return list<string> */
    private function validateReplacement(LeigodRetirementActivationContext $context, int $forwardId, array $target): array
    {
        $m = $context->replacement_map[$forwardId] ?? null;
        if (!is_array($m)) { return ['replacement_mapping_missing']; }
        foreach (['tenant_id','client_id','client_device_session_id'] as $field) {
            if (array_key_exists($field, $target) && (int)$target[$field] !== (int)($m[$field] ?? 0)) { return ['replacement_mapping_mismatch']; }
        }
        foreach (['replacement_forward_id','assignment_id','desired_generation'] as $field) { if ((int)($m[$field] ?? 0) <= 0) { return ['replacement_mapping_mismatch']; } }
        foreach (['assignment_hash','desired_leg_set_hash'] as $field) { if (!is_string($m[$field] ?? null) || !preg_match('/^[a-f0-9]{64}$/i', (string)$m[$field])) { return ['replacement_mapping_mismatch']; } }
        if (($m['active_family'] ?? '') !== 'standalone_bond') { return ['replacement_mapping_mismatch']; }
        if ((int)($m['created_at_unix'] ?? 0) <= $this->now() - 15) { return ['replacement_lifecycle_stale']; }
        if ((int)($m['publish_generation'] ?? 0) !== (int)($m['desired_generation'] ?? 0) || (string)($m['publish_fencing_token'] ?? '') !== $context->fencing_token) { return ['publish_fence_generation_mismatch']; }
        $keepForwards = array_map('intval', is_array($context->active_keep_set['forward_ids'] ?? null) ? $context->active_keep_set['forward_ids'] : []);
        if (!in_array((int)$m['replacement_forward_id'], $keepForwards, true)) { return ['replacement_not_in_active_keep_set']; }
        return [];
    }

    /** @return list<string> */
    private function validateRoleCapabilities(LeigodRetirementActivationContext $context): array
    {
        $blockers = [];
        foreach (['client','slave','sidecar'] as $role) {
            $cap = $context->role_capabilities[$role] ?? null;
            if (!is_array($cap)) { $blockers[] = 'missing_role_capability:' . $role; continue; }
            foreach (['role'=>$role,'protocol'=>'forward_retirement_v1','auth_schema'=>'lease_token_v1','result_schema'=>'retirement_result_v1'] as $field=>$expected) {
                if ((string)($cap[$field] ?? '') !== $expected) { $blockers[] = 'wrong_role_capability:' . $role; }
            }
            if ((int)($cap['max_result_bytes'] ?? 0) > $context->max_result_bytes) { $blockers[] = 'wrong_role_capability:' . $role; }
        }
        return $this->unique($blockers);
    }

    /** @return list<string> */
    private function validateState(LeigodRetirementActivationContext $context): array
    {
        $blockers = [];
        if (($context->desired_state['active_family'] ?? '') !== 'standalone_bond' || ($context->applied_state['active_family'] ?? '') !== 'standalone_bond') { $blockers[] = 'replacement_state_invalid'; }
        foreach (['forward_id','tenant_id','client_id','client_device_session_id','assignment_id','desired_generation','assignment_hash','desired_leg_set_hash','artifact_snapshot_hash','publish_generation','publish_fencing_token'] as $field) {
            if ((string)($context->desired_state[$field] ?? '') !== (string)($context->applied_state[$field] ?? '')) { $blockers[] = 'replacement_state_mismatch'; break; }
        }
        return $blockers;
    }

    /** @return list<string> */
    private function validateRuntimeRetirement(LeigodRetirementActivationContext $context, int $forwardId): array
    {
        $ret = $context->runtime_retirements[$forwardId] ?? null;
        if (!is_array($ret)) { return ['missing_runtime_retirements']; }
        $resources = $ret['resources'] ?? null;
        if (!is_array($resources)) { return ['missing_runtime_retirements']; }
        foreach ($resources as $resource) {
            if (!is_array($resource)) { return ['ownership_proof_rejected']; }
            $reason = $this->badOwnershipReason($resource, $forwardId);
            if ($reason !== null) { return [$reason]; }
        }
        return [];
    }

    /** @param array<string,mixed> $resource */
    private function badOwnershipReason(array $resource, int $forwardId): ?string
    {
        $type = (string)($resource['type'] ?? ''); $name = (string)($resource['name'] ?? ''); $owner = (string)($resource['owner'] ?? '');
        if (!in_array($type, ['systemd','wireguard','listener','file'], true)) { return 'ownership_proof_rejected'; }
        if (!in_array($owner, ['manifest','applied_state'], true)) { return 'ownership_proof_rejected'; }
        if ((int)($resource['forward_id'] ?? 0) !== $forwardId) { return 'ownership_proof_rejected'; }
        if ($name === '' || str_contains($name, '*') || str_contains($name, '..') || str_starts_with($name, 'leikwan-') && !str_contains($name, (string)$forwardId)) { return 'ownership_proof_rejected'; }
        if ($type === 'systemd' && $name !== 'leikwan-client-' . $forwardId . '.service' && !preg_match('/^leikwan-uot-lw-mptcp-' . preg_quote((string)$forwardId, '/') . '-[a-zA-Z0-9_.-]+\.service$/', $name)) { return 'ownership_proof_rejected'; }
        if ($type === 'wireguard' && !preg_match('/^(lw-mptcp-' . preg_quote((string)$forwardId, '/') . '-[a-zA-Z0-9_.-]+|lw-cfwg-' . preg_quote((string)$forwardId, '/') . '|lw-cfsr-' . preg_quote((string)$forwardId, '/') . '|lw-self-' . preg_quote((string)$forwardId, '/') . ')$/', $name)) { return 'ownership_proof_rejected'; }
        if ($type === 'listener' && (!is_numeric($resource['port'] ?? null) || (int)$resource['port'] <= 0 || (string)($resource['protocol'] ?? '') === '')) { return 'ownership_proof_rejected'; }
        return null;
    }

    /** @param list<array<string,mixed>> $resources @return list<array<string,mixed>> */
    private function subtractKeepSet(LeigodRetirementActivationContext $context, array $resources): array
    {
        $keep = [];
        foreach ((is_array($context->active_keep_set['resources'] ?? null) ? $context->active_keep_set['resources'] : []) as $resource) {
            if (is_array($resource)) { $keep[] = ($resource['type'] ?? '') . ':' . ($resource['name'] ?? ''); }
        }
        return array_values(array_filter($resources, static fn($r): bool => is_array($r) && !in_array(($r['type'] ?? '') . ':' . ($r['name'] ?? ''), $keep, true)));
    }

    /** @param list<int> $ids @return array{0:string,1:array<string,int>} */
    private function idListPredicate(string $column, array $ids, string $prefix): array
    {
        $placeholders = [];
        $params = [];
        foreach (array_values($ids) as $index => $id) {
            $key = $prefix . '_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = (int)$id;
        }
        return [$column . ' IN (' . implode(', ', $placeholders) . ')', $params];
    }

    /** @param array<string,mixed> $mapping */
    private function desiredStateHash(LeigodRetirementActivationContext $context, array $mapping): string
    {
        $hash = (string)($mapping['desired_state_hash'] ?? $context->desired_state['desired_state_hash'] ?? '');
        if (preg_match('/^[a-f0-9]{64}$/i', $hash) === 1) { return strtolower($hash); }
        return hash('sha256', LeigodOnlyActivationPlan::canonicalJson($context->desired_state));
    }

    private function gateContext(LeigodRetirementActivationContext $context): array { return ['scope'=>$context->scope,'replacements'=>$context->replacement_map,'desired_state'=>$context->desired_state,'artifact_pins'=>$context->expected_artifact_pins]; }
    private function now(): int { return $this->nowUnixSeconds ?? time(); }
    private function dbNow(): string { return gmdate('Y-m-d H:i:s', $this->now()); }
    private function activeKeepSetIsEmpty(LeigodRetirementActivationContext $context): bool { return !is_array($context->active_keep_set['forward_ids'] ?? null) || $context->active_keep_set['forward_ids'] === []; }
    private function assertNoActiveTransaction(): void { if ($this->dbInTransaction()) { throw new \RuntimeException('active_master_db_transaction'); } }
    private function dbInTransaction(): bool { return method_exists($this->db, 'inTransaction') && (bool)$this->db->inTransaction(); }
    /** @param list<string> $blockers @return array<string,mixed> */
    private function result(bool $ok, array $blockers, int $writes): array { return ['ok'=>$ok,'blockers'=>$this->unique($blockers),'writes'=>$writes]; }
    /** @param list<string> $items @return list<string> */
    private function unique(array $items): array { $items=array_values(array_unique(array_filter($items, 'is_string'))); sort($items, SORT_STRING); return $items; }
}
