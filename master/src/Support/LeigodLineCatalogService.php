<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;

final class LeigodLineCatalogService
{
    private const MAX_LINE_ID = 45535;

    public function __construct(private Config $config, private Database $db)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function listLines(): array
    {
        return array_map(
            fn(array $row): array => $this->withConfigurationState($row),
            $this->db->fetchAll('SELECT * FROM leigod_lines ORDER BY sort_order ASC, id ASC')
        );
    }

    /** @return array<string, mixed>|null */
    public function lineById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $row = $this->db->fetchOne('SELECT * FROM leigod_lines WHERE id = :id LIMIT 1', ['id' => $id]);
        return is_array($row) ? $this->withConfigurationState($row) : null;
    }

    /** @return array<string, mixed> */
    public function saveLine(array $payload): array
    {
        $id = max(0, (int) ($payload['id'] ?? 0));
        $lineId = (int) ($payload['line_id'] ?? 0);
        if ($lineId < 1 || $lineId > self::MAX_LINE_ID) {
            throw new ApiException('invalid_leigod_line_id', 422, ['line_id' => $lineId]);
        }

        $label = trim((string) ($payload['label'] ?? ''));
        if ($label === '') {
            throw new ApiException('invalid_leigod_line_label', 422);
        }
        $tcpProbeHost = trim((string) ($payload['tcp_probe_host'] ?? ''));
        $tcpProbePort = (int) ($payload['tcp_probe_port'] ?? 0);
        if (!$this->validProbeHost($tcpProbeHost) || $tcpProbePort < 1 || $tcpProbePort > 65535) {
            throw new ApiException('invalid_leigod_line_tcp_probe', 422, [
                'tcp_probe_host' => $tcpProbeHost,
                'tcp_probe_port' => $tcpProbePort,
            ]);
        }

        $udpProbeHost = trim((string) ($payload['udp_probe_host'] ?? ''));
        $udpProbePort = (int) ($payload['udp_probe_port'] ?? 0);
        $udpProbeMethod = strtolower(trim((string) ($payload['udp_probe_method'] ?? '')));
        $hasUdpProbe = $udpProbeHost !== '' || $udpProbePort > 0 || $udpProbeMethod !== '';
        if ($hasUdpProbe) {
            if (!$this->validProbeHost($udpProbeHost) || $udpProbePort < 1 || $udpProbePort > 65535) {
                throw new ApiException('invalid_leigod_line_udp_probe', 422, [
                    'udp_probe_host' => $udpProbeHost,
                    'udp_probe_port' => $udpProbePort,
                ]);
            }
            if (!in_array($udpProbeMethod, ['dns', 'echo'], true)) {
                throw new ApiException('invalid_leigod_line_udp_probe_method', 422, [
                    'udp_probe_method' => $udpProbeMethod,
                ]);
            }
        } else {
            $udpProbeHost = '';
            $udpProbePort = 0;
            $udpProbeMethod = '';
        }

        $configSha256 = strtolower(trim((string) ($payload['config_sha256'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/', $configSha256) !== 1) {
            throw new ApiException('invalid_leigod_line_config_sha256', 422);
        }

        $configuration = $this->configurationForLine([
            'line_id' => $lineId,
            'config_sha256' => $configSha256,
        ]);
        $requestedActive = $this->truthy($payload['is_active'] ?? false);
        $normalized = [
            'line_id' => $lineId,
            'label' => $label,
            'notes' => ($notes = trim((string) ($payload['notes'] ?? ''))) !== '' ? $notes : null,
            'tcp_probe_host' => $tcpProbeHost,
            'tcp_probe_port' => $tcpProbePort,
            'udp_probe_host' => $udpProbeHost !== '' ? $udpProbeHost : null,
            'udp_probe_port' => $udpProbePort > 0 ? $udpProbePort : null,
            'udp_probe_method' => $udpProbeMethod !== '' ? $udpProbeMethod : null,
            'config_sha256' => $configSha256,
            'is_active' => $requestedActive && $configuration['state'] === 'ready' ? 1 : 0,
            'sort_order' => (int) ($payload['sort_order'] ?? 100),
            'updated_at' => $this->now(),
        ];

        if ($id > 0) {
            if ($this->db->fetchOne('SELECT * FROM leigod_lines WHERE id = :id LIMIT 1', ['id' => $id]) === null) {
                throw new ApiException('leigod_line_not_found', 404, ['id' => $id]);
            }
            $this->db->execute(
                'UPDATE leigod_lines
                 SET line_id = :line_id,
                     label = :label,
                     notes = :notes,
                     tcp_probe_host = :tcp_probe_host,
                     tcp_probe_port = :tcp_probe_port,
                     udp_probe_host = :udp_probe_host,
                     udp_probe_port = :udp_probe_port,
                     udp_probe_method = :udp_probe_method,
                     config_sha256 = :config_sha256,
                     is_active = :is_active,
                     sort_order = :sort_order,
                     updated_at = :updated_at
                 WHERE id = :id',
                $normalized + ['id' => $id]
            );
        } else {
            $existing = $this->db->fetchOne(
                'SELECT * FROM leigod_lines WHERE line_id = :line_id LIMIT 1',
                ['line_id' => $lineId]
            );
            if (is_array($existing)) {
                throw new ApiException('leigod_line_id_exists', 422, ['line_id' => $lineId]);
            }
            $id = $this->db->insert('leigod_lines', $normalized + ['created_at' => $this->now()]);
        }

        return $this->lineById($id) ?? [];
    }

    public function deleteLine(int $id): void
    {
        if ($id <= 0 || $this->db->fetchOne('SELECT * FROM leigod_lines WHERE id = :id LIMIT 1', ['id' => $id]) === null) {
            throw new ApiException('leigod_line_not_found', 404, ['id' => $id]);
        }
        $references = $this->db->fetchOne(
            'SELECT COUNT(*) AS membership_count
             FROM forward_combination_leigod_lines
             WHERE leigod_line_id = :id',
            ['id' => $id]
        );
        if ((int) ($references['membership_count'] ?? 0) > 0) {
            throw new ApiException('leigod_line_in_use', 409, ['id' => $id]);
        }
        $this->db->execute('DELETE FROM leigod_lines WHERE id = :id', ['id' => $id]);
    }

    /** @return array<int, array<string, mixed>> */
    public function membershipsForCombination(int $combinationId): array
    {
        if ($combinationId <= 0) {
            return [];
        }
        return $this->db->fetchAll(
            'SELECT fcll.*, ll.line_id, ll.label, ll.config_sha256, ll.is_active AS line_is_active
             FROM forward_combination_leigod_lines fcll
             INNER JOIN leigod_lines ll ON ll.id = fcll.leigod_line_id
             WHERE fcll.forward_combination_id = :forward_combination_id
             ORDER BY fcll.sort_order ASC, fcll.id ASC',
            ['forward_combination_id' => $combinationId]
        );
    }

    /**
     * @param array<int, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    public function normalizeMemberships(array $payload, bool $requireReady): array
    {
        if (count($payload) > 64) {
            throw new ApiException('too_many_leigod_line_memberships', 422, ['limit' => 64]);
        }
        $byCatalogId = [];
        foreach ($payload as $index => $item) {
            $item = is_array($item) ? $item : ['leigod_line_id' => $item];
            $catalogId = (int) ($item['leigod_line_id'] ?? 0);
            if ($catalogId <= 0) {
                throw new ApiException('invalid_leigod_line_membership', 422, ['index' => $index]);
            }
            $line = $this->db->fetchOne(
                'SELECT * FROM leigod_lines WHERE id = :id LIMIT 1',
                ['id' => $catalogId]
            );
            if (!is_array($line)) {
                throw new ApiException('leigod_line_not_found', 422, ['id' => $catalogId]);
            }
            $enabled = $this->truthy($item['enabled'] ?? true) ? 1 : 0;
            $configuration = $this->configurationForLine($line);
            if ($requireReady && $enabled === 1) {
                if ((int) ($line['is_active'] ?? 0) !== 1) {
                    throw new ApiException('leigod_line_not_active', 422, ['id' => $catalogId]);
                }
                if ($configuration['state'] !== 'ready') {
                    throw new ApiException('leigod_line_not_ready', 422, [
                        'id' => $catalogId,
                        'state' => $configuration['state'],
                    ]);
                }
            }
            $normalized = [
                'leigod_line_id' => $catalogId,
                'line_id' => (int) ($line['line_id'] ?? 0),
                'enabled' => $enabled,
                'sort_order' => (int) ($item['sort_order'] ?? (($index + 1) * 10)),
                'label' => (string) ($line['label'] ?? ''),
                'config_sha256' => (string) ($line['config_sha256'] ?? ''),
                'line_is_active' => (int) ($line['is_active'] ?? 0),
                'configuration_state' => $configuration['state'],
            ];
            if (!isset($byCatalogId[$catalogId]) || $normalized['sort_order'] < $byCatalogId[$catalogId]['sort_order']) {
                $byCatalogId[$catalogId] = $normalized;
            }
        }
        $normalized = array_values($byCatalogId);
        usort($normalized, static fn(array $left, array $right): int => [
            (int) $left['sort_order'],
            (int) $left['leigod_line_id'],
        ] <=> [
            (int) $right['sort_order'],
            (int) $right['leigod_line_id'],
        ]);
        return $normalized;
    }

    /**
     * @param array<int, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    public function saveCombinationMemberships(int $combinationId, array $payload, bool $requireReady): array
    {
        if ($combinationId <= 0) {
            throw new ApiException('invalid_forward_combination_id', 422);
        }
        $normalized = $this->normalizeMemberships($payload, $requireReady);
        $this->db->execute(
            'DELETE FROM forward_combination_leigod_lines WHERE forward_combination_id = :forward_combination_id',
            ['forward_combination_id' => $combinationId]
        );
        foreach ($normalized as $membership) {
            $this->db->insert('forward_combination_leigod_lines', [
                'forward_combination_id' => $combinationId,
                'leigod_line_id' => (int) $membership['leigod_line_id'],
                'enabled' => (int) $membership['enabled'],
                'sort_order' => (int) $membership['sort_order'],
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ]);
        }
        return $normalized;
    }

    /** @return array{state:string,toml:string,actual_sha256:string} */
    public function configurationForLine(array $line): array
    {
        $lineId = (int) ($line['line_id'] ?? 0);
        if ($lineId < 1 || $lineId > self::MAX_LINE_ID) {
            return ['state' => 'missing', 'toml' => '', 'actual_sha256' => ''];
        }
        $encoded = trim((string) ($this->config->get('LEIGOD_LINE_TOML_' . $lineId, '') ?? ''));
        if ($encoded === '') {
            return ['state' => 'missing', 'toml' => '', 'actual_sha256' => ''];
        }
        $toml = base64_decode($encoded, true);
        if (!is_string($toml) || $toml === '') {
            return ['state' => 'missing', 'toml' => '', 'actual_sha256' => ''];
        }
        $actualSha256 = hash('sha256', $toml);
        $expectedSha256 = strtolower(trim((string) ($line['config_sha256'] ?? '')));
        return [
            'state' => hash_equals($expectedSha256, $actualSha256) ? 'ready' : 'hash_mismatch',
            'toml' => $toml,
            'actual_sha256' => $actualSha256,
        ];
    }

    /** @return array<string, mixed> */
    private function withConfigurationState(array $row): array
    {
        $configuration = $this->configurationForLine($row);
        $row['configuration_state'] = $configuration['state'];
        $row['actual_config_sha256'] = $configuration['actual_sha256'] !== ''
            ? $configuration['actual_sha256']
            : null;
        return $row;
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return !in_array(strtolower(trim((string) $value)), ['', '0', 'false', 'no', 'off'], true);
    }

    private function validProbeHost(string $host): bool
    {
        if ($host === '' || strlen($host) > 255) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        $hostname = rtrim($host, '.');
        if ($hostname === '' || strlen($hostname) > 253) {
            return false;
        }
        foreach (explode('.', $hostname) as $label) {
            if (strlen($label) > 63 || preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?$/D', $label) !== 1) {
                return false;
            }
        }
        return true;
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
