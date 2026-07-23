<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

final class LeigodOnlyForwardRetirementCleanupLeaseAdapter
{
    /** @param array<string,mixed> $lease @return array<string,mixed> */
    public function issue(array $lease): array
    {
        foreach (['forward_id', 'resources', 'cleanup_lease_token', 'cleanup_result_token', 'max_result_bytes', 'protocol'] as $field) {
            if (!array_key_exists($field, $lease)) {
                throw new \InvalidArgumentException('cleanup_lease_' . $field . '_required');
            }
        }
        if ((string) $lease['protocol'] !== 'forward_retirement_v1') {
            throw new \InvalidArgumentException('unsupported_cleanup_protocol');
        }
        if (!is_array($lease['resources'])) {
            throw new \InvalidArgumentException('cleanup_lease_resources_required');
        }
        $maxBytes = is_numeric($lease['max_result_bytes']) ? (int) $lease['max_result_bytes'] : 0;
        if ($maxBytes <= 0 || $maxBytes > 65536) {
            throw new \InvalidArgumentException('oversized_result_bound');
        }
        return [
            'ok' => true,
            'schema' => 'retirement_cleanup_lease_v1',
            'forward_id' => (int) $lease['forward_id'],
            'lease_token' => (string) $lease['cleanup_lease_token'],
            'result_token' => (string) $lease['cleanup_result_token'],
            'resource_count' => count($lease['resources']),
            'max_result_bytes' => $maxBytes,
        ];
    }
}
