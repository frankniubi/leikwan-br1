<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

final class StandaloneBondLegPolicy
{
    /**
     * @param array<int, array<string, mixed>> $legRows
     * @return array<string, mixed>
     */
    public static function classify(int $forwardId, int $desiredGeneration, array $legRows): array
    {
        $canonicalKeys = [];
        $nodeIds = [];
        $seenKeys = [];

        foreach ($legRows as $row) {
            if (!self::isUsable($row)) {
                continue;
            }

            if (!array_key_exists('forward_id', $row)) {
                return self::invalid('missing_forward_id', $canonicalKeys, $nodeIds);
            }
            if (!array_key_exists('desired_generation', $row)) {
                return self::invalid('missing_desired_generation', $canonicalKeys, $nodeIds);
            }
            if (!array_key_exists('protocol', $row)) {
                return self::invalid('missing_protocol', $canonicalKeys, $nodeIds);
            }
            if (!array_key_exists('logical_leg_key', $row) && !array_key_exists('stable_logical_leg_key', $row) && !array_key_exists('leg_key', $row)) {
                return self::invalid('missing_logical_leg_key', $canonicalKeys, $nodeIds);
            }
            if (!array_key_exists('node_id', $row) || $row['node_id'] === null) {
                return self::invalid('missing_node_id', $canonicalKeys, $nodeIds);
            }
            if (!array_key_exists('endpoint_id', $row) || $row['endpoint_id'] === null) {
                return self::invalid('missing_endpoint_id', $canonicalKeys, $nodeIds);
            }

            $rowForwardId = self::positiveInt($row['forward_id']);
            $rowDesiredGeneration = self::positiveInt($row['desired_generation']);
            $nodeId = self::positiveInt($row['node_id']);
            $endpointId = self::positiveInt($row['endpoint_id']);
            $protocol = is_scalar($row['protocol']) ? strtolower(trim((string) $row['protocol'])) : '';
            $logicalKeyValue = $row['logical_leg_key'] ?? $row['stable_logical_leg_key'] ?? $row['leg_key'] ?? null;
            $logicalKey = is_scalar($logicalKeyValue) ? trim((string) $logicalKeyValue) : '';

            if ($rowForwardId !== $forwardId) {
                return self::invalid('wrong_forward_id', $canonicalKeys, $nodeIds);
            }
            if ($rowDesiredGeneration !== $desiredGeneration) {
                return self::invalid('wrong_desired_generation', $canonicalKeys, $nodeIds);
            }
            if ($nodeId <= 0 || $endpointId <= 0) {
                return self::invalid('malformed_leg_identity', $canonicalKeys, $nodeIds);
            }
            if ($protocol !== 'tcp') {
                return self::invalid('wrong_protocol', $canonicalKeys, $nodeIds);
            }

            $expectedKey = self::logicalKey($forwardId, $nodeId, $endpointId);
            if ($logicalKey === '' || $logicalKey !== $expectedKey) {
                return self::invalid('malformed_leg_key', $canonicalKeys, $nodeIds);
            }
            if (isset($seenKeys[$logicalKey])) {
                return self::invalid('duplicate_leg_key', $canonicalKeys, $nodeIds);
            }

            $seenKeys[$logicalKey] = true;
            $canonicalKeys[] = $logicalKey;
            $nodeIds[] = $nodeId;
        }

        sort($canonicalKeys, SORT_STRING);
        $distinctNodeIds = array_values(array_unique($nodeIds));
        sort($distinctNodeIds, SORT_NUMERIC);

        $usableLegCount = count($canonicalKeys);
        $usableSlaveCount = count($distinctNodeIds);

        if ($usableLegCount === 0) {
            return self::result('invalid_speed_plus_leg_set', 'no_usable_legs', $canonicalKeys, $distinctNodeIds);
        }
        if ($usableLegCount === 1) {
            return self::result('single_leg_only', 'single_usable_slave', $canonicalKeys, $distinctNodeIds);
        }
        if ($usableSlaveCount === 1) {
            return self::result('speed_plus_requires_distinct_slave_nodes', 'multiple_endpoints_one_slave_node', $canonicalKeys, $distinctNodeIds);
        }
        if ($usableSlaveCount >= 9) {
            return self::result('too_many_speed_plus_legs', 'too_many_speed_plus_legs', $canonicalKeys, $distinctNodeIds);
        }

        return self::result('speed_plus_eligible', 'speed_plus_eligible', $canonicalKeys, $distinctNodeIds);
    }

    /**
     * @param array<int, array<string, mixed>> $legRows
     */
    public static function canonicalLegSetHash(int $forwardId, int $desiredGeneration, array $legRows): string
    {
        $classification = self::classify($forwardId, $desiredGeneration, $legRows);
        $canonicalLegKeys = $classification['canonical_leg_keys'];
        if (($classification['outcome'] ?? '') === 'invalid_speed_plus_leg_set') {
            $canonicalLegKeys = self::canonicalKeysForHash($legRows);
        }
        $payload = [
            'forward_id' => $forwardId,
            'desired_generation' => $desiredGeneration,
            'outcome' => $classification['outcome'],
            'reason' => (($classification['outcome'] ?? '') === 'invalid_speed_plus_leg_set') ? 'invalid_speed_plus_leg_set' : $classification['reason'],
            'canonical_leg_keys' => $canonicalLegKeys,
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function isUsable(array $row): bool
    {
        if (array_key_exists('usable', $row) && self::isFalseLike($row['usable'])) {
            return false;
        }
        if (array_key_exists('enabled', $row) && self::isFalseLike($row['enabled'])) {
            return false;
        }
        if (array_key_exists('is_active', $row) && self::isFalseLike($row['is_active'])) {
            return false;
        }

        $status = strtolower(trim((string) ($row['status'] ?? $row['health_status'] ?? 'active')));
        if (in_array($status, ['disabled', 'inactive', 'deleted', 'deleting', 'failed', 'error', 'unusable'], true)) {
            return false;
        }

        return true;
    }

    private static function isFalseLike(mixed $value): bool
    {
        if ($value === false || $value === 0 || $value === '0') {
            return true;
        }
        if (is_string($value) && in_array(strtolower(trim($value)), ['false', 'no', 'off', 'disabled'], true)) {
            return true;
        }
        return false;
    }

    private static function positiveInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }
        return 0;
    }

    private static function logicalKey(int $forwardId, int $nodeId, int $endpointId): string
    {
        return 'bond:' . $forwardId . ':tcp:' . $nodeId . ':' . $endpointId;
    }

    /**
     * @param array<int, array<string, mixed>> $legRows
     * @return array<int, string>
     */
    private static function canonicalKeysForHash(array $legRows): array
    {
        $keys = [];
        foreach ($legRows as $row) {
            if (!self::isUsable($row)) {
                continue;
            }
            $keyValue = $row['logical_leg_key'] ?? $row['stable_logical_leg_key'] ?? $row['leg_key'] ?? null;
            if (is_scalar($keyValue) && trim((string) $keyValue) !== '') {
                $keys[] = trim((string) $keyValue);
                continue;
            }
            $keys[] = json_encode([
                'forward_id' => $row['forward_id'] ?? null,
                'desired_generation' => $row['desired_generation'] ?? null,
                'protocol' => $row['protocol'] ?? null,
                'node_id' => $row['node_id'] ?? null,
                'endpoint_id' => $row['endpoint_id'] ?? null,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '<unencodable-leg>';
        }
        sort($keys, SORT_STRING);
        return $keys;
    }

    /**
     * @param array<int, string> $canonicalKeys
     * @param array<int, int> $nodeIds
     * @return array<string, mixed>
     */
    private static function invalid(string $reason, array $canonicalKeys, array $nodeIds): array
    {
        sort($canonicalKeys, SORT_STRING);
        $distinctNodeIds = array_values(array_unique($nodeIds));
        sort($distinctNodeIds, SORT_NUMERIC);
        return self::result('invalid_speed_plus_leg_set', $reason, $canonicalKeys, $distinctNodeIds);
    }

    /**
     * @param array<int, string> $canonicalKeys
     * @param array<int, int> $distinctNodeIds
     * @return array<string, mixed>
     */
    private static function result(string $outcome, string $reason, array $canonicalKeys, array $distinctNodeIds): array
    {
        return [
            'outcome' => $outcome,
            'reason' => $reason,
            'usable_slave_count' => count($distinctNodeIds),
            'usable_leg_count' => count($canonicalKeys),
            'distinct_node_ids' => $distinctNodeIds,
            'canonical_leg_keys' => $canonicalKeys,
        ];
    }
}
