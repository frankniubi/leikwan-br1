<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

final class LeigodNormalizationActivationContext
{
    public readonly string $scope;
    public readonly string $plan_hash;
    public readonly string $current_plan_hash;
    public readonly string $owner_token;
    public readonly string $fencing_token;
    public readonly string $artifact_snapshot_hash;
    /** @var list<int> */ public readonly array $affected_forward_ids;
    /** @var array<string,string> */ public readonly array $expected_artifact_pins;

    /** @param array<string,mixed> $data */
    private function __construct(array $data)
    {
        $this->scope = self::scope($data['scope'] ?? null);
        $this->plan_hash = self::hash($data['plan_hash'] ?? null, 'plan_hash');
        $this->current_plan_hash = self::hash($data['current_plan_hash'] ?? $data['plan_hash'] ?? null, 'current_plan_hash');
        $this->owner_token = self::hash($data['owner_token'] ?? null, 'owner_token');
        $this->fencing_token = self::hash($data['fencing_token'] ?? null, 'fencing_token');
        $this->artifact_snapshot_hash = self::hash($data['artifact_snapshot_hash'] ?? null, 'artifact_snapshot_hash');
        $this->affected_forward_ids = self::idList($data['affected_forward_ids'] ?? null, 'affected_forward_ids');
        $this->expected_artifact_pins = self::hashMap($data['expected_artifact_pins'] ?? []);
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self { return new self($data); }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'scope'=>$this->scope,
            'plan_hash'=>$this->plan_hash,
            'current_plan_hash'=>$this->current_plan_hash,
            'owner_token'=>$this->owner_token,
            'fencing_token'=>$this->fencing_token,
            'artifact_snapshot_hash'=>$this->artifact_snapshot_hash,
            'affected_forward_ids'=>$this->affected_forward_ids,
            'expected_artifact_pins'=>$this->expected_artifact_pins,
        ];
    }

    public static function scope(mixed $value): string
    {
        if (!is_scalar($value)) { throw new \InvalidArgumentException('malformed_scope'); }
        $scope = trim((string)$value);
        if (preg_match('/^(global|tenant:[1-9][0-9]*|forward:[1-9][0-9]*)$/', $scope) !== 1) {
            throw new \InvalidArgumentException('malformed_scope');
        }
        return $scope;
    }

    public static function hash(mixed $value, string $field): string
    {
        if (!is_scalar($value) || preg_match('/^[a-f0-9]{64}$/i', (string)$value) !== 1) {
            throw new \InvalidArgumentException('malformed_' . $field);
        }
        return strtolower((string)$value);
    }

    /** @return list<int> */
    public static function idList(mixed $value, string $field): array
    {
        if (!is_array($value) || $value === []) { throw new \InvalidArgumentException('malformed_' . $field); }
        $ids = [];
        foreach ($value as $item) {
            $id = is_numeric($item) ? (int)$item : 0;
            if ($id <= 0) { throw new \InvalidArgumentException('malformed_' . $field); }
            $ids[] = $id;
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /** @return array<string,string> */
    public static function hashMap(mixed $value): array
    {
        if (!is_array($value) || $value === []) { throw new \InvalidArgumentException('missing_artifact_snapshot_pins'); }
        $out = [];
        foreach ($value as $key => $hash) {
            if (!is_string($key) || trim($key) === '') { throw new \InvalidArgumentException('malformed_artifact_snapshot_pins'); }
            $out[$key] = self::hash($hash, 'artifact_snapshot_pin_' . $key);
        }
        ksort($out, SORT_STRING);
        return $out;
    }
}
