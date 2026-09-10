<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

require_once __DIR__ . '/LeigodOnlyActivationPlan.php';
require_once __DIR__ . '/LeigodOnlyRolloutLockService.php';

final class LeigodOnlyActivationPlanStore
{
    public function __construct(private object $db) {}

    /** @param array<string,mixed> $options */
    public function record(LeigodOnlyActivationPlan $plan, array $options): int
    {
        if (($options['record'] ?? null) !== true) {
            throw new \InvalidArgumentException('record_explicitly_required');
        }
        $scope = (string)$plan->payload()['scope'];
        $owner = $this->requiredString($options, 'owner_token');
        $fence = $this->requiredString($options, 'fencing_token');
        LeigodOnlyRolloutLockService::requireFence($scope, $owner, $fence);
        $artifact = (string)($options['artifact_snapshot_hash'] ?? ($plan->payload()['artifact_gate']['artifact_snapshot_hash'] ?? $plan->payload()['artifact_snapshot_hash'] ?? ''));
        LeigodOnlyRolloutLockService::requireHash($artifact, 'artifact_snapshot_hash');
        $affected = $this->forwardIds($options['affected_forward_ids'] ?? []);
        $jsonPayload = $plan->payload();
        $jsonPayload['affected_forward_ids'] = $affected;
        $jsonPayload['artifact_snapshot_hash'] = $artifact;
        $jsonPayload['canonical_plan_hash'] = LeigodOnlyActivationPlan::canonicalHash($plan->payload());
        $writeHash = hash('sha256', LeigodOnlyActivationPlan::canonicalJson(['plan_hash'=>$plan->hash(),'owner_token'=>$owner,'fencing_token'=>$fence,'affected_forward_ids'=>$affected,'artifact_snapshot_hash'=>$artifact]));
        return $this->db->insert('leigod_only_activation_plans', [
            'scope'=>$scope,
            'plan_hash'=>$plan->hash(),
            'plan_state'=>'planned',
            'backend_mode'=>'leigod_mode',
            'client_mode'=>'bond',
            'runtime_preference'=>'single',
            'bond_ingress_mode'=>'plain_tcp_mux',
            'active_family'=>'standalone_bond',
            'platform_family'=>(string)($plan->payload()['platform_family'] ?? 'linux-bond'),
            'desired_generation'=>(int)($plan->payload()['desired_generation'] ?? 0),
            'desired_state_hash'=>(string)($plan->payload()['desired_state_hash'] ?? str_repeat('0', 64)),
            'artifact_snapshot_hash'=>$artifact,
            'owner_token'=>$owner,
            'fencing_token'=>$fence,
            'write_hash'=>$writeHash,
            'external_authorization_ref'=>$options['external_authorization_ref'] ?? null,
            'plan_json'=>LeigodOnlyActivationPlan::canonicalJson($jsonPayload),
        ]);
    }

    public function update(string $planHash): never { throw new \LogicException('immutable_plan_store_update_rejected'); }
    public function delete(string $planHash): never { throw new \LogicException('immutable_plan_store_delete_rejected'); }
    public function expire(string $planHash): never { throw new \LogicException('immutable_plan_store_expire_rejected'); }
    public function authorize(string $planHash): never { throw new \LogicException('immutable_plan_store_authorize_rejected'); }
    public function loadAuthorization(string $planHash): never { throw new \LogicException('plan_is_not_authorization'); }

    /** @param array<string,mixed> $options */
    private function requiredString(array $options, string $field): string
    {
        $value = $options[$field] ?? null;
        if (!is_scalar($value) || trim((string)$value) === '') {
            throw new \InvalidArgumentException('fencing_token_required ' . $field);
        }
        return trim((string)$value);
    }

    /** @return list<int> */
    private function forwardIds(mixed $value): array
    {
        if (!is_array($value) || $value === []) { throw new \InvalidArgumentException('affected_forward_ids_required'); }
        $ids=[];
        foreach ($value as $id) { $int=(int)$id; if ($int<=0) throw new \InvalidArgumentException('affected_forward_ids_required'); $ids[]=$int; }
        $ids=array_values(array_unique($ids)); sort($ids, SORT_NUMERIC); return $ids;
    }
}
