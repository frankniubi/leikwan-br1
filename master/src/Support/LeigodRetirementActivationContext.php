<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

require_once __DIR__ . '/LeigodNormalizationActivationContext.php';

final class LeigodRetirementActivationContext
{
    public readonly string $scope;
    public readonly string $plan_hash;
    public readonly string $current_plan_hash;
    public readonly string $batch_hash;
    public readonly string $current_batch_hash;
    public readonly string $owner_token;
    public readonly string $fencing_token;
    public readonly string $artifact_snapshot_hash;
    /** @var array<string,string> */ public readonly array $expected_artifact_pins;
    /** @var list<int> */ public readonly array $affected_forward_ids;
    /** @var list<int> */ public readonly array $retirement_forward_ids;
    public readonly string $cleanup_protocol;
    public readonly string $cleanup_lease_token;
    public readonly string $cleanup_result_token;
    public readonly int $max_result_bytes;
    /** @var array<int,array<string,mixed>> */ public readonly array $replacement_map;
    /** @var array<string,array<string,mixed>> */ public readonly array $role_capabilities;
    /** @var array<string,mixed> */ public readonly array $desired_state;
    /** @var array<string,mixed> */ public readonly array $applied_state;
    /** @var array<int,array<string,mixed>> */ public readonly array $runtime_retirements;
    /** @var array<string,mixed> */ public readonly array $active_keep_set;

    /** @param array<string,mixed> $data */
    private function __construct(array $data)
    {
        $this->scope = LeigodNormalizationActivationContext::scope($data['scope'] ?? null);
        $this->plan_hash = LeigodNormalizationActivationContext::hash($data['plan_hash'] ?? null, 'plan_hash');
        $this->current_plan_hash = LeigodNormalizationActivationContext::hash($data['current_plan_hash'] ?? $data['plan_hash'] ?? null, 'current_plan_hash');
        $this->batch_hash = LeigodNormalizationActivationContext::hash($data['batch_hash'] ?? null, 'batch_hash');
        $this->current_batch_hash = LeigodNormalizationActivationContext::hash($data['current_batch_hash'] ?? $data['batch_hash'] ?? null, 'current_batch_hash');
        $this->owner_token = LeigodNormalizationActivationContext::hash($data['owner_token'] ?? null, 'owner_token');
        $this->fencing_token = LeigodNormalizationActivationContext::hash($data['fencing_token'] ?? null, 'fencing_token');
        $this->artifact_snapshot_hash = LeigodNormalizationActivationContext::hash($data['artifact_snapshot_hash'] ?? null, 'artifact_snapshot_hash');
        $this->expected_artifact_pins = LeigodNormalizationActivationContext::hashMap($data['expected_artifact_pins'] ?? []);
        $this->affected_forward_ids = LeigodNormalizationActivationContext::idList($data['affected_forward_ids'] ?? null, 'affected_forward_ids');
        $this->retirement_forward_ids = LeigodNormalizationActivationContext::idList($data['retirement_forward_ids'] ?? null, 'retirement_forward_ids');
        $this->cleanup_protocol = self::protocol($data['cleanup_protocol'] ?? null);
        $this->cleanup_lease_token = LeigodNormalizationActivationContext::hash($data['cleanup_lease_token'] ?? null, 'cleanup_lease_token');
        $this->cleanup_result_token = LeigodNormalizationActivationContext::hash($data['cleanup_result_token'] ?? null, 'cleanup_result_token');
        $this->max_result_bytes = self::boundedInt($data['max_result_bytes'] ?? 0, 'max_result_bytes', 1, 65536);
        $this->replacement_map = self::replacementMap($data['replacement_map'] ?? null);
        $this->role_capabilities = self::roleCaps($data['role_capabilities'] ?? null);
        $this->desired_state = self::nonEmptyMap($data['desired_state'] ?? null, 'desired_state');
        $this->applied_state = self::nonEmptyMap($data['applied_state'] ?? null, 'applied_state');
        $this->runtime_retirements = self::retirements($data['runtime_retirements'] ?? null);
        $this->active_keep_set = is_array($data['active_keep_set'] ?? null) ? $data['active_keep_set'] : ['forward_ids'=>[], 'resources'=>[]];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self { return new self($data); }

    private static function protocol(mixed $value): string
    {
        if (!is_scalar($value) || (string)$value !== 'forward_retirement_v1') { throw new \InvalidArgumentException('unsupported_cleanup_protocol'); }
        return 'forward_retirement_v1';
    }

    private static function boundedInt(mixed $value, string $field, int $min, int $max): int
    {
        $int = is_numeric($value) ? (int)$value : 0;
        if ($int < $min || $int > $max) { throw new \InvalidArgumentException($field === 'max_result_bytes' ? 'oversized_result_bound' : 'malformed_' . $field); }
        return $int;
    }

    /** @return array<int,array<string,mixed>> */
    private static function replacementMap(mixed $value): array
    {
        if (!is_array($value) || $value === []) { throw new \InvalidArgumentException('missing_replacement_mapping'); }
        $out = [];
        foreach ($value as $key => $row) {
            if (!is_array($row)) { throw new \InvalidArgumentException('malformed_replacement_mapping'); }
            $rid = (int)($row['retirement_forward_id'] ?? $key);
            if ($rid <= 0) { throw new \InvalidArgumentException('malformed_replacement_mapping'); }
            $out[$rid] = $row + ['retirement_forward_id'=>$rid];
        }
        ksort($out, SORT_NUMERIC);
        return $out;
    }

    /** @return array<string,array<string,mixed>> */
    private static function roleCaps(mixed $value): array
    {
        if (!is_array($value) || $value === []) { throw new \InvalidArgumentException('missing_role_capabilities'); }
        $out = [];
        foreach ($value as $role => $row) {
            if (!is_string($role) || !in_array($role, ['client','slave','sidecar'], true) || !is_array($row)) { throw new \InvalidArgumentException('malformed_role_capabilities'); }
            $out[$role] = $row;
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function nonEmptyMap(mixed $value, string $field): array
    {
        if (!is_array($value) || $value === []) { throw new \InvalidArgumentException('missing_' . $field); }
        return $value;
    }

    /** @return array<int,array<string,mixed>> */
    private static function retirements(mixed $value): array
    {
        if (!is_array($value) || $value === []) { throw new \InvalidArgumentException('missing_runtime_retirements'); }
        $out = [];
        foreach ($value as $key => $row) {
            if (!is_array($row)) { throw new \InvalidArgumentException('malformed_runtime_retirements'); }
            $fid = (int)($row['forward_id'] ?? $key);
            if ($fid <= 0) { throw new \InvalidArgumentException('malformed_runtime_retirements'); }
            $out[$fid] = $row + ['forward_id'=>$fid];
        }
        ksort($out, SORT_NUMERIC);
        return $out;
    }
}
