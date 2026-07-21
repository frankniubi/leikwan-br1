<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

require_once __DIR__ . '/LeigodNormalizationActivationContext.php';
require_once __DIR__ . '/LeigodOnlyRuntimeContract.php';

final class LeigodOnlyNormalizationActivationService
{
    public function __construct(private object $db, private object $lockService, private object $artifactGate) {}

    /** @return array<string,mixed> */
    public function apply(LeigodNormalizationActivationContext $context): array
    {
        $blockers = [];
        if ($context->plan_hash !== $context->current_plan_hash) { $blockers[] = 'activation_plan_hash_changed'; }
        try { $this->lockService->requireHeld($context->scope, 'leigod-only-normalization', $context->owner_token, $context->fencing_token); }
        catch (\Throwable) { $blockers[] = 'rollout_lock_not_held'; }
        $artifact = $this->artifactGate->evaluate(['scope'=>$context->scope,'artifact_snapshot_hash'=>$context->artifact_snapshot_hash,'expected_pins'=>$context->expected_artifact_pins,'affected_forward_ids'=>$context->affected_forward_ids]);
        if (!is_array($artifact) || !($artifact['ok'] ?? false)) { $blockers[] = 'artifact_gate_failed'; }
        if ($blockers !== []) { return $this->result(false, $blockers, 0); }

        [$idSql, $idParams] = $this->idListPredicate('id', $context->affected_forward_ids, 'affected_forward_id');
        $rows = $this->db->fetchAll(
            "SELECT id, backend_mode, client_mode, runtime_preference, bond_ingress_mode, active_family FROM forward_combinations WHERE {$idSql} FOR UPDATE",
            $idParams
        );
        foreach ($rows as $row) {
            $backend = (string)($row['backend_mode'] ?? '');
            if (in_array($backend, ['current_mode','cf_mode'], true)) { return $this->result(false, ['deprecated_forward_combination_backend_mode'], 0); }
            if ($backend !== 'leigod_mode') { return $this->result(false, ['invalid_forward_combination_backend_mode'], 0); }
            try { LeigodOnlyRuntimeContract::assertActiveContract($row, 'normalization_activation'); }
            catch (\Throwable) { return $this->result(false, ['unsupported_leigod_runtime_contract'], 0); }
        }
        $foundIds = array_map('intval', array_column($rows, 'id'));
        sort($foundIds, SORT_NUMERIC);
        if ($foundIds !== $context->affected_forward_ids) { return $this->result(false, ['unsupported_leigod_runtime_contract'], 0); }

        $contract = ['backend_mode'=>'leigod_mode','client_mode'=>'bond','runtime_preference'=>'single','bond_ingress_mode'=>'plain_tcp_mux','active_family'=>'standalone_bond'];
        $written = $this->db->execute(
            "INSERT INTO leigod_only_normalization_activation_events (forward_id, scope, plan_hash, owner_token, fencing_token, artifact_snapshot_hash, event_type) SELECT id, :scope, :plan_hash, :owner_token, :fencing_token, :artifact_snapshot_hash, 'locked_contract_observed' FROM forward_combinations WHERE {$idSql} AND backend_mode = :backend_mode AND client_mode = :client_mode AND runtime_preference = :runtime_preference AND bond_ingress_mode = :bond_ingress_mode AND active_family = :active_family",
            array_merge(['scope'=>$context->scope,'plan_hash'=>$context->plan_hash,'owner_token'=>$context->owner_token,'fencing_token'=>$context->fencing_token,'artifact_snapshot_hash'=>$context->artifact_snapshot_hash], $idParams, $contract)
        );
        if ($written !== count($context->affected_forward_ids)) { return $this->result(false, ['normalization_event_write_mismatch'], $written); }
        return $this->result(true, [], $written);
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

    /** @param list<string> $blockers @return array<string,mixed> */
    private function result(bool $ok, array $blockers, int $writes): array
    {
        $blockers = array_values(array_unique($blockers)); sort($blockers, SORT_STRING);
        return ['ok'=>$ok,'blockers'=>$blockers,'writes'=>$writes];
    }
}
