<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

require_once __DIR__ . '/LeigodActivationGateContext.php';
require_once __DIR__ . '/LeigodOnlyActivationPlan.php';
require_once __DIR__ . '/LeigodOnlyRolloutLockService.php';

final class LeigodOnlyCanaryCampaignService
{
    public function __construct(private object $db, private ?object $activationGate = null, private ?object $activationMutator = null) {}

    /** @param array<string,mixed> $campaign @param array<string,mixed> $forward @return array<string,mixed> */
    public function evaluateForward(array $campaign, array $forward): array
    {
        $scope = (string)($campaign['scope'] ?? 'global');
        $cohort = (int)($campaign['cohort'] ?? 0);
        $blockers = [];
        $gateResult = null;
        if ((string)($forward['platform_family'] ?? '') === 'android-bond') {
            return $this->result(false, ['unsupported_platform_family:android-bond'], $cohort, 'activation', null);
        }
        if ($cohort > 0) {
            $this->validatePredecessor($campaign, $cohort, $blockers);
        }
        if (in_array($cohort, [3,4], true) && !((bool)($campaign['owner_approved'] ?? false))) {
            $blockers[] = 'owner_approval_checkpoint_required';
        }
        $slaves = $this->usableSlaves($forward['slaves'] ?? []);
        if ($cohort === 1) {
            if (count($slaves) !== 1) { $blockers[] = 'cohort1_requires_exactly_one_usable_slave'; }
            if ((string)($forward['leg_policy'] ?? 'shared') !== 'shared') { $blockers[] = 'cohort1_requires_shared_leg_policy'; }
        }
        if ($cohort >= 2) {
            if (count($slaves) < 2 || count($slaves) > 8) { $blockers[] = 'cohort2_requires_2_to_8_usable_slaves'; }
            $nodes = array_values(array_unique(array_map(static fn(array $s): int => (int)($s['node_id'] ?? 0), $slaves)));
            if (count($nodes) !== count($slaves)) { $blockers[] = 'cohort2_requires_distinct_slave_nodes'; }
        }
        foreach ([
            'route_drift'=>'route_static_drift',
            'static_drift'=>'route_static_drift',
            'basecontroller_drift'=>'basecontroller_drift',
            'pin_drift'=>'artifact_pin_drift',
            'capability_drift'=>'capability_drift',
        ] as $field=>$blocker) {
            if ((bool)($forward[$field] ?? false)) { $blockers[] = $blocker; }
        }
        $writeMode = $cohort === 0 ? 'read_only' : 'activation';
        if ($cohort > 0 && $this->activationGate !== null && method_exists($this->activationGate, 'gate') && count($blockers) === 0) {
            $gateResult = $this->activationGate->gate((object)[
                'scope'=>$scope,
                'forward_id'=>(int)($forward['forward_id'] ?? 0),
                'write_mode'=>$writeMode,
                'expected_artifact_pins'=>$this->decodeJsonMap($campaign['artifact_version_pins_json'] ?? []),
                'generation_fences'=>$this->decodeJsonMap($campaign['generation_fences_json'] ?? []),
                'campaign'=>$campaign,
                'forward'=>$forward,
            ]);
            if (is_array($gateResult)) {
                foreach (($gateResult['blockers'] ?? []) as $blocker) { if (is_string($blocker)) { $blockers[] = $blocker; } }
                $diag = (string)($gateResult['diagnostic_artifact_family'] ?? $gateResult['capability_family'] ?? '');
                if (in_array($diag, ['native_mptcp','leikwan-mptcp-relay'], true)) { $blockers[] = 'diagnostic_family_not_promotable'; }
                if (!(bool)($gateResult['promotion_ready'] ?? $gateResult['ok'] ?? false)) { $blockers[] = 'activation_gate_not_ready'; }
            } else {
                $blockers[] = 'activation_gate_malformed';
            }
        }
        $blockers = $this->unique($blockers);
        $ready = count($blockers) === 0;
        return $this->result($ready, $blockers, $cohort, $writeMode, $gateResult);
    }

    /** @param array<string,mixed> $request @param array<string,mixed> $status @return array<string,mixed> */
    public function writeStatus(array $request, array $status): array
    {
        $scope = (string)($request['scope'] ?? '');
        $owner = (string)($request['owner_token'] ?? '');
        $fence = (string)($request['fencing_token'] ?? '');
        LeigodOnlyRolloutLockService::requireFence($scope, $owner, $fence);
        foreach (['campaign_hash','write_hash','artifact_snapshot_hash'] as $field) {
            LeigodOnlyRolloutLockService::requireHash((string)($request[$field] ?? ''), $field);
        }
        $affectedForwardIdsJson = LeigodOnlyActivationPlan::canonicalJson(array_values(array_map('intval', is_array($request['affected_forward_ids'] ?? null) ? $request['affected_forward_ids'] : [])));
        $row = [
            'scope'=>$scope,
            'campaign_id'=>(int)($request['campaign_id'] ?? 0),
            'plan_hash'=>$request['plan_hash'] ?? null,
            'batch_hash'=>$request['batch_hash'] ?? null,
            'owner_token'=>$owner,
            'fencing_token'=>$fence,
            'affected_forward_ids_json'=>$affectedForwardIdsJson,
            'artifact_snapshot_hash'=>(string)$request['artifact_snapshot_hash'],
            'campaign_hash'=>(string)$request['campaign_hash'],
            'write_hash'=>(string)$request['write_hash'],
            'status_json'=>LeigodOnlyActivationPlan::canonicalJson($status),
        ];
        $insertId = method_exists($this->db, 'insert') ? $this->db->insert('leigod_only_canary_campaign_status', $row) : 0;
        $updateCount = method_exists($this->db, 'execute') ? $this->db->execute("UPDATE leigod_only_canary_campaigns SET current_status_write_hash = :write_hash WHERE scope = :scope AND campaign_id = :campaign_id AND campaign_hash = :campaign_hash AND owner_token = :owner_token AND fencing_token = :fencing_token", ['scope'=>$scope,'campaign_id'=>$row['campaign_id'],'campaign_hash'=>$row['campaign_hash'],'owner_token'=>$owner,'fencing_token'=>$fence,'write_hash'=>$row['write_hash']]) : 0;
        return ['affected_rows'=>['insert'=>$insertId > 0 ? 1 : 0, 'update'=>$updateCount], 'row'=>$row];
    }

    /** @param list<string> $blockers @return array<string,mixed> */
    private function result(bool $ready, array $blockers, int $cohort, string $writeMode, ?array $gateResult): array
    {
        return ['promotion_ready'=>$ready,'ok'=>$ready,'blockers'=>$this->unique($blockers),'cohort'=>$cohort,'write_mode'=>$writeMode,'activation_gate'=>$gateResult];
    }

    /** @param array<string,mixed> $campaign @param list<string> $blockers */
    private function validatePredecessor(array $campaign, int $cohort, array &$blockers): void
    {
        $snapshot = $campaign['predecessor_snapshot'] ?? null;
        if (!is_array($snapshot)) { $blockers[] = 'predecessor_snapshot_not_immutable_success'; return; }
        $ok = (int)($snapshot['cohort'] ?? -1) === $cohort - 1
            && (string)($snapshot['scope'] ?? '') === (string)($campaign['scope'] ?? 'global')
            && (string)($snapshot['status'] ?? '') === 'success'
            && !((bool)($snapshot['observing'] ?? false))
            && (($snapshot['blockers'] ?? []) === []);
        foreach (['artifact_version_pins_json','fencing_token','leg_policy_hash'] as $field) {
            $expectedField = 'expected_predecessor_' . $field;
            $expected = $campaign[$expectedField] ?? ($campaign[$field] ?? null);
            if ($expected !== null && isset($snapshot[$field]) && (string)$expected !== (string)$snapshot[$field]) { $ok = false; }
        }
        if (!$ok) { $blockers[] = 'predecessor_snapshot_not_immutable_success'; }
    }

    /** @return list<array<string,mixed>> */
    private function usableSlaves(mixed $slaves): array
    {
        if (!is_array($slaves)) { return []; }
        $out = [];
        foreach ($slaves as $slave) {
            if (!is_array($slave)) { continue; }
            if (($slave['usable'] ?? true) === false || ($slave['status'] ?? 'active') === 'disabled') { continue; }
            $out[] = $slave;
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function decodeJsonMap(mixed $value): array
    {
        if (is_string($value)) { $value = json_decode($value, true); }
        return is_array($value) ? $value : [];
    }

    /** @param list<string> $items @return list<string> */
    private function unique(array $items): array
    {
        $items = array_values(array_unique(array_filter($items, static fn($v): bool => is_string($v) && $v !== '')));
        sort($items, SORT_STRING);
        return $items;
    }
}
