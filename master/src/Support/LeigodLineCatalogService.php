<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;

final class LeigodLineCatalogService
{
    private const MAX_LINE_ID = 45535;
    private LeigodExecutionLeaseService $executionLeases;

    public function __construct(
        private Config $config,
        private Database $db,
        ?LeigodExecutionLeaseService $executionLeases = null,
    ) {
        $this->executionLeases = $executionLeases ?? new LeigodExecutionLeaseService($config, $db);
    }

    /** @return array<int, array<string, mixed>> */
    public function listLines(): array
    {
        $maxAge = max(15, min(3600, $this->config->getInt('LEIGOD_LINE_HEALTH_MAX_AGE_SECONDS', 180)));
        $now = time();
        $healthByLine = [];
        foreach ($this->db->fetchAll(
            'SELECT nllh.leigod_line_id,
                    SUM(CASE WHEN service_state = :tcp_active AND tcp_status = :tcp_healthy THEN 1 ELSE 0 END) AS tcp_healthy_nodes,
                    SUM(CASE WHEN service_state = :udp_active AND udp_status = :udp_healthy THEN 1 ELSE 0 END) AS udp_healthy_nodes,
                    MAX(checked_at) AS last_checked_at,
                    MAX(last_error) AS last_error
             FROM node_leigod_line_health nllh
             INNER JOIN leigod_lines ll ON ll.id = nllh.leigod_line_id
             WHERE nllh.config_sha256 = ll.config_sha256
               AND nllh.checked_at >= :health_cutoff
               AND nllh.checked_at <= :health_future_limit
             GROUP BY nllh.leigod_line_id',
            [
                'tcp_active' => 'active',
                'tcp_healthy' => 'healthy',
                'udp_active' => 'active',
                'udp_healthy' => 'healthy',
                'health_cutoff' => gmdate('Y-m-d H:i:s', $now - $maxAge),
                'health_future_limit' => gmdate('Y-m-d H:i:s', $now + 300),
            ]
        ) as $health) {
            $healthByLine[(int) ($health['leigod_line_id'] ?? 0)] = $health;
        }
        return array_map(
            function (array $row) use ($healthByLine): array {
                $health = $healthByLine[(int) ($row['id'] ?? 0)] ?? [];
                $row['tcp_healthy_nodes'] = (int) ($health['tcp_healthy_nodes'] ?? 0);
                $row['udp_healthy_nodes'] = (int) ($health['udp_healthy_nodes'] ?? 0);
                $row['last_checked_at'] = $health['last_checked_at'] ?? null;
                $row['last_error'] = $health['last_error'] ?? null;
                return $this->withConfigurationState($row);
            },
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
    public function desiredLinesForNode(int $nodeId): array
    {
        if ($nodeId <= 0) {
            return [];
        }
        $cap = max(1, min(256, $this->config->getInt('LEIGOD_LINE_MANAGEMENT_CAP', 64)));
        $desired = [];
        foreach ($this->readyDesiredRowsForNode($nodeId) as $row) {
            $configuration = $row['_configuration'];
            $line = [
                'line_id' => (int) ($row['line_id'] ?? 0),
                'label' => (string) ($row['label'] ?? ''),
                'config_sha256' => (string) ($row['config_sha256'] ?? ''),
                'toml' => (string) ($configuration['toml'] ?? ''),
                'socks_host' => '127.0.0.1',
                'socks_port' => 20000 + (int) ($row['line_id'] ?? 0),
                'tcp_probe_host' => (string) ($row['tcp_probe_host'] ?? ''),
                'tcp_probe_port' => (int) ($row['tcp_probe_port'] ?? 0),
                'udp_probe_host' => ($host = trim((string) ($row['udp_probe_host'] ?? ''))) !== '' ? $host : null,
                'udp_probe_port' => (int) ($row['udp_probe_port'] ?? 0) > 0 ? (int) $row['udp_probe_port'] : null,
                'udp_probe_method' => ($method = trim((string) ($row['udp_probe_method'] ?? ''))) !== '' ? $method : null,
            ];
            if ($this->leasesEnabled()) {
                $line += $this->leaseProjection($row);
            }
            $desired[] = $line;
            if (count($desired) >= $cap) {
                break;
            }
        }
        return $desired;
    }

    /** @return array<int, array<string, mixed>> */
    public function desiredLinesForClientDevice(int $clientId, string $deviceId): array
    {
        $deviceId = trim($deviceId);
        if (!$this->leasesEnabled() || $clientId <= 0 || $deviceId === '') {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT ll.id AS catalog_id,
                    ll.line_id,
                    ll.config_sha256,
                    ll.is_active,
                    ll.allow_client_execution,
                    lel.lease_id,
                    lel.generation AS lease_generation,
                    lel.state AS lease_state,
                    lel.owner_type AS lease_owner_type,
                    lel.owner_id AS lease_owner_id,
                    lel.owner_device_id AS lease_owner_device_id,
                    lel.max_parallel_lanes,
                    lel.ready_deadline_at AS lease_ready_deadline_at,
                    lel.expires_at AS lease_expires_at
             FROM leigod_execution_leases lel
             INNER JOIN leigod_lines ll ON ll.id = lel.leigod_line_id
             WHERE lel.owner_type = :owner_type
               AND lel.owner_id = :owner_id
               AND lel.owner_device_id = :owner_device_id
               AND lel.state IN (:pending, :active)
               AND lel.expires_at > UTC_TIMESTAMP()
               AND ll.is_active = 1
               AND ll.allow_client_execution = 1
             ORDER BY ll.sort_order ASC, ll.id ASC',
            [
                'owner_type' => 'client_device',
                'owner_id' => $clientId,
                'owner_device_id' => $deviceId,
                'pending' => 'pending',
                'active' => 'active',
            ]
        );
        $cap = max(1, min(64, $this->config->getInt('LEIGOD_CLIENT_LINE_LEASE_CAP', 8)));
        $desired = [];
        $seen = [];
        foreach ($rows as $row) {
            $lineId = (int) ($row['line_id'] ?? 0);
            if ($lineId <= 0 || isset($seen[$lineId]) || !$this->clientLeaseMatches($row, $clientId, $deviceId)) {
                continue;
            }
            $configuration = $this->configurationForLine($row);
            if ($configuration['state'] !== 'ready') {
                continue;
            }
            $desired[] = [
                'line_id' => $lineId,
                'config_sha256' => (string) ($row['config_sha256'] ?? ''),
                'toml' => (string) ($configuration['toml'] ?? ''),
                'socks_host' => '127.0.0.1',
                'socks_port' => 20000 + $lineId,
            ] + $this->leaseProjection($row);
            $seen[$lineId] = true;
            if (count($desired) >= $cap) {
                break;
            }
        }
        return $desired;
    }

    /**
     * @param array<int|string, mixed> $payload
     * @return array{accepted:int,rejected:int}
     */
    public function ingestNodeHealth(int $nodeId, array $payload): array
    {
        $leasesEnabled = $this->leasesEnabled();
        $rows = is_array($payload['leigod_line_health'] ?? null)
            ? $payload['leigod_line_health']
            : $payload;
        if (array_key_exists('line_id', $rows)) {
            $rows = [$rows];
        }
        $rows = array_values($rows);
        $cap = max(1, min(256, $this->config->getInt('LEIGOD_LINE_HEARTBEAT_CAP', 64)));
        $rejected = max(0, count($rows) - $cap);
        $rows = array_slice($rows, 0, $cap);

        $desiredByLineId = [];
        foreach ($this->readyDesiredRowsForNode($nodeId) as $desired) {
            $lineId = (int) ($desired['line_id'] ?? 0);
            if ($lineId > 0) {
                $desiredByLineId[$lineId] = $desired;
            }
        }

        $accepted = 0;
        $seen = [];
        $now = time();
        $maxFutureSeconds = max(0, min(3600, $this->config->getInt('LEIGOD_LINE_HEALTH_MAX_FUTURE_SECONDS', 300)));
        foreach ($rows as $row) {
            if (!is_array($row)) {
                $rejected++;
                continue;
            }
            $lineId = (int) ($row['line_id'] ?? 0);
            $desired = $desiredByLineId[$lineId] ?? null;
            if ($lineId <= 0 || !is_array($desired) || isset($seen[$lineId])) {
                $rejected++;
                continue;
            }

            $expectedHash = strtolower(trim((string) ($desired['config_sha256'] ?? '')));
            $reportedHash = strtolower(trim((string) ($row['config_sha256'] ?? '')));
            $serviceState = strtolower(trim((string) ($row['service_state'] ?? 'unknown')));
            $tcpStatus = strtolower(trim((string) ($row['tcp_status'] ?? 'unknown')));
            $udpStatus = strtolower(trim((string) ($row['udp_status'] ?? 'unknown')));
            if (!in_array($serviceState, ['starting', 'active', 'failed', 'revoking'], true)
                || !in_array($tcpStatus, ['unknown', 'healthy', 'unhealthy'], true)
                || !in_array($udpStatus, ['unknown', 'healthy', 'unhealthy'], true)) {
                $rejected++;
                continue;
            }

            $checkedAtRaw = trim((string) ($row['checked_at'] ?? ''));
            $checkedTimestamp = $checkedAtRaw !== '' ? strtotime($checkedAtRaw) : $now;
            if ($checkedTimestamp === false || $checkedTimestamp > ($now + $maxFutureSeconds)) {
                $rejected++;
                continue;
            }

            $catalogId = (int) ($desired['catalog_id'] ?? $desired['id'] ?? 0);
            if ($catalogId <= 0) {
                $rejected++;
                continue;
            }
            $reportedLeaseId = strtolower(trim((string) ($row['lease_id'] ?? '')));
            $reportedGeneration = (int) ($row['lease_generation'] ?? 0);
            if ($leasesEnabled) {
                $expectedLeaseId = strtolower(trim((string) ($desired['lease_id'] ?? '')));
                $expectedGeneration = (int) ($desired['lease_generation'] ?? 0);
                if ($reportedLeaseId === ''
                    || !hash_equals($expectedLeaseId, $reportedLeaseId)
                    || $reportedGeneration <= 0
                    || $reportedGeneration !== $expectedGeneration) {
                    $rejected++;
                    continue;
                }
                try {
                    $this->executionLeases->acceptHealth($catalogId, $reportedLeaseId, $reportedGeneration, [
                        'owner_type' => 'node',
                        'owner_id' => $nodeId,
                        'owner_device_id' => null,
                        'config_sha256' => $reportedHash,
                        'healthy' => $serviceState === 'active' && $tcpStatus === 'healthy',
                        'consecutive_failures' => (int) ($row['consecutive_failures'] ?? 0),
                    ]);
                } catch (ApiException) {
                    $rejected++;
                    continue;
                }
            }
            if (preg_match('/^[a-f0-9]{64}$/', $reportedHash) !== 1
                || !hash_equals($expectedHash, $reportedHash)) {
                $rejected++;
                continue;
            }
            $seen[$lineId] = true;
            $tcpConnectMs = ($row['tcp_connect_ms'] ?? null) === null || $row['tcp_connect_ms'] === ''
                ? null
                : max(0, min(600000, (int) $row['tcp_connect_ms']));
            $lastError = substr(trim((string) ($row['last_error'] ?? '')), 0, 500);
            $params = [
                'node_id' => $nodeId,
                'leigod_line_id' => $catalogId,
                'config_sha256' => $expectedHash,
                'service_state' => $serviceState,
                'tcp_status' => $tcpStatus,
                'tcp_connect_ms' => $tcpConnectMs,
                'udp_status' => $udpStatus,
                'consecutive_successes' => max(0, min(1000000, (int) ($row['consecutive_successes'] ?? 0))),
                'consecutive_failures' => max(0, min(1000000, (int) ($row['consecutive_failures'] ?? 0))),
                'last_error' => $lastError !== '' ? $lastError : null,
                'checked_at' => gmdate('Y-m-d H:i:s', $checkedTimestamp),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ];
            if ($leasesEnabled) {
                $params['lease_id'] = $reportedLeaseId;
                $params['lease_generation'] = $reportedGeneration;
            }
            $leaseColumns = $leasesEnabled ? ', lease_id, lease_generation' : '';
            $leaseValues = $leasesEnabled ? ', :lease_id, :lease_generation' : '';
            $leaseUpdate = $leasesEnabled
                ? ",\n                    lease_id = VALUES(lease_id),\n                    lease_generation = VALUES(lease_generation)"
                : '';
            $this->db->execute(
                'INSERT INTO node_leigod_line_health
                    (node_id, leigod_line_id' . $leaseColumns . ', config_sha256, service_state, tcp_status, tcp_connect_ms,
                     udp_status, consecutive_successes, consecutive_failures, last_error, checked_at, updated_at)
                 VALUES
                    (:node_id, :leigod_line_id' . $leaseValues . ', :config_sha256, :service_state, :tcp_status, :tcp_connect_ms,
                     :udp_status, :consecutive_successes, :consecutive_failures, :last_error, :checked_at, :updated_at)
                 ON DUPLICATE KEY UPDATE
                    config_sha256 = VALUES(config_sha256)' . $leaseUpdate . ',
                    service_state = VALUES(service_state),
                    tcp_status = VALUES(tcp_status),
                    tcp_connect_ms = VALUES(tcp_connect_ms),
                    udp_status = VALUES(udp_status),
                    consecutive_successes = VALUES(consecutive_successes),
                    consecutive_failures = VALUES(consecutive_failures),
                    last_error = VALUES(last_error),
                    checked_at = VALUES(checked_at),
                    updated_at = VALUES(updated_at)',
                $params
            );
            $accepted++;
        }

        return ['accepted' => $accepted, 'rejected' => $rejected];
    }

    /** @return array<string, mixed> */
    public function routeAvailability(int $routeId, string $transport): array
    {
        $route = $routeId > 0
            ? $this->db->fetchOne('SELECT * FROM forward_routes WHERE id = :id LIMIT 1', ['id' => $routeId])
            : null;
        if (!is_array($route)) {
            return [
                'route_id' => $routeId,
                'available' => false,
                'healthy_node_count' => 0,
                'unavailable_reason' => 'forward_route_not_found',
                'nodes' => [],
            ];
        }
        if ((int) ($route['is_active'] ?? 0) !== 1) {
            return [
                'route_id' => $routeId,
                'race_enabled' => (int) ($route['race_enabled'] ?? 0),
                'available' => false,
                'healthy_node_count' => 0,
                'unavailable_reason' => 'forward_route_inactive',
                'nodes' => [],
            ];
        }
        $raceEnabled = (int) ($route['race_enabled'] ?? 0) === 1;
        $nodes = $this->eligibleNodesFromCandidateRows(
            $this->routeCandidateRows($routeId),
            $transport,
            $raceEnabled ? 2 : 1
        );
        return [
            'route_id' => $routeId,
            'race_enabled' => $raceEnabled ? 1 : 0,
            'required_combination_count' => $raceEnabled ? 2 : 1,
            'available' => $nodes !== [],
            'healthy_node_count' => count($nodes),
            'unavailable_reason' => $nodes === [] ? 'leigod_route_no_healthy_candidate' : null,
            'nodes' => $nodes,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function eligibleNodesForRoute(int $routeId, string $transport): array
    {
        $availability = $this->routeAvailability($routeId, $transport);
        return is_array($availability['nodes'] ?? null) ? $availability['nodes'] : [];
    }

    public function nodeSupportsRoute(int $nodeId, int $routeId, string $transport): bool
    {
        if ($nodeId <= 0) {
            return false;
        }
        foreach ($this->eligibleNodesForRoute($routeId, $transport) as $node) {
            if ((int) ($node['id'] ?? 0) === $nodeId) {
                return true;
            }
        }
        return false;
    }

    /** @return array<int, array<string, mixed>> */
    public function eligibleNodesForCombination(int $combinationId, string $transport): array
    {
        if ($combinationId <= 0) {
            return [];
        }
        return $this->eligibleNodesFromCandidateRows(
            $this->combinationCandidateRows($combinationId),
            $transport,
            1
        );
    }

    /**
     * @param array<string, mixed> $forward
     * @return array<int, array<string, mixed>>
     */
    public function candidatesForForwardOnNode(array $forward, int $nodeId): array
    {
        if ($nodeId <= 0) {
            return [];
        }
        $transport = $this->eligibilityTransport((string) ($forward['transport_mode'] ?? 'tcp'));
        $routeId = (int) ($forward['forward_route_id'] ?? 0);
        $combinationId = (int) ($forward['forward_combination_id'] ?? 0);
        $nodes = $routeId > 0
            ? $this->eligibleNodesForRoute($routeId, $transport)
            : $this->eligibleNodesForCombination($combinationId, $transport);
        foreach ($nodes as $node) {
            if ((int) ($node['id'] ?? 0) === $nodeId) {
                return is_array($node['candidates'] ?? null) ? $node['candidates'] : [];
            }
        }
        return [];
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

    private function leasesEnabled(): bool
    {
        return $this->config->getBool('LEIGOD_EXECUTION_LEASES_ENABLED', false);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{lease_id:string,lease_generation:int,lease_state:string,max_parallel_lanes:int,ready_deadline_at:string,expires_at:string}
     */
    private function leaseProjection(array $row): array
    {
        return [
            'lease_id' => strtolower(trim((string) ($row['lease_id'] ?? ''))),
            'lease_generation' => (int) ($row['lease_generation'] ?? 0),
            'lease_state' => strtolower(trim((string) ($row['lease_state'] ?? ''))),
            'max_parallel_lanes' => max(1, min(4, (int) ($row['max_parallel_lanes'] ?? 2))),
            'ready_deadline_at' => (string) ($row['lease_ready_deadline_at'] ?? ''),
            'expires_at' => (string) ($row['lease_expires_at'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $row */
    private function nodeLeaseMatches(array $row, int $nodeId, bool $allowPending): bool
    {
        $state = strtolower(trim((string) ($row['lease_state'] ?? '')));
        $allowedStates = $allowPending ? ['pending', 'active'] : ['active'];
        return (string) ($row['lease_owner_type'] ?? '') === 'node'
            && (int) ($row['lease_owner_id'] ?? 0) === $nodeId
            && in_array($state, $allowedStates, true)
            && $this->validLeaseId((string) ($row['lease_id'] ?? ''))
            && (int) ($row['lease_generation'] ?? 0) > 0
            && $this->futureLeaseTimestamp((string) ($row['lease_expires_at'] ?? ''))
            && ($state !== 'pending' || $this->futureLeaseTimestamp((string) ($row['lease_ready_deadline_at'] ?? $row['lease_expires_at'] ?? '')));
    }

    /** @param array<string, mixed> $row */
    private function clientLeaseMatches(array $row, int $clientId, string $deviceId): bool
    {
        return (int) ($row['is_active'] ?? 0) === 1
            && (int) ($row['allow_client_execution'] ?? 0) === 1
            && (string) ($row['lease_owner_type'] ?? '') === 'client_device'
            && (int) ($row['lease_owner_id'] ?? 0) === $clientId
            && hash_equals($deviceId, (string) ($row['lease_owner_device_id'] ?? ''))
            && $this->nodeLeaseShapeMatches($row, true);
    }

    /** @param array<string, mixed> $row */
    private function nodeLeaseShapeMatches(array $row, bool $allowPending): bool
    {
        $state = strtolower(trim((string) ($row['lease_state'] ?? '')));
        return in_array($state, $allowPending ? ['pending', 'active'] : ['active'], true)
            && $this->validLeaseId((string) ($row['lease_id'] ?? ''))
            && (int) ($row['lease_generation'] ?? 0) > 0
            && $this->futureLeaseTimestamp((string) ($row['lease_expires_at'] ?? ''))
            && ($state !== 'pending' || $this->futureLeaseTimestamp((string) ($row['lease_ready_deadline_at'] ?? $row['lease_expires_at'] ?? '')));
    }

    /** @param array<string, mixed> $row */
    private function candidateLeaseMatches(array $row, int $nodeId): bool
    {
        $leaseId = strtolower(trim((string) ($row['lease_id'] ?? '')));
        $healthLeaseId = strtolower(trim((string) ($row['health_lease_id'] ?? '')));
        return $this->nodeLeaseMatches($row, $nodeId, false)
            && $healthLeaseId !== ''
            && hash_equals($leaseId, $healthLeaseId)
            && (int) ($row['health_lease_generation'] ?? 0) === (int) ($row['lease_generation'] ?? 0);
    }

    private function validLeaseId(string $leaseId): bool
    {
        return preg_match(
            '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/',
            strtolower(trim($leaseId))
        ) === 1;
    }

    private function futureLeaseTimestamp(string $value): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', trim($value), new \DateTimeZone('UTC'));
        return $parsed instanceof \DateTimeImmutable
            && $parsed->format('Y-m-d H:i:s') === trim($value)
            && $parsed->getTimestamp() > time();
    }

    /** @return array{0:string,1:string,2:string,3:array<string,string>} */
    private function candidateLeaseSql(): array
    {
        if (!$this->leasesEnabled()) {
            return ['', '', '', []];
        }
        return [
            ', lel.lease_id,
               lel.generation AS lease_generation,
               lel.state AS lease_state,
               lel.owner_type AS lease_owner_type,
               lel.owner_id AS lease_owner_id,
               lel.max_parallel_lanes,
               lel.ready_deadline_at AS lease_ready_deadline_at,
               lel.expires_at AS lease_expires_at,
               nllh.lease_id AS health_lease_id,
               nllh.lease_generation AS health_lease_generation',
            ' INNER JOIN leigod_execution_leases lel
                ON lel.leigod_line_id = ll.id
               AND lel.owner_type = :lease_owner_type
               AND lel.owner_id = n.id
               AND lel.owner_device_id IS NULL',
            ' AND lel.state = :lease_active
                AND lel.expires_at > UTC_TIMESTAMP()',
            [
                'lease_owner_type' => 'node',
                'lease_active' => 'active',
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function readyDesiredRowsForNode(int $nodeId): array
    {
        if ($nodeId <= 0) {
            return [];
        }
        $leasesEnabled = $this->leasesEnabled();
        $leaseSelect = $leasesEnabled
            ? ', lel.lease_id,
                 lel.generation AS lease_generation,
                 lel.state AS lease_state,
                 lel.owner_type AS lease_owner_type,
                 lel.owner_id AS lease_owner_id,
                 lel.max_parallel_lanes,
                 lel.ready_deadline_at AS lease_ready_deadline_at,
                 lel.expires_at AS lease_expires_at'
            : '';
        $leaseJoin = $leasesEnabled
            ? ' INNER JOIN leigod_execution_leases lel ON lel.leigod_line_id = ll.id'
            : '';
        $leaseWhere = $leasesEnabled
            ? ' AND lel.owner_type = :lease_owner_type
                AND lel.owner_id = :lease_owner_id
                AND lel.owner_device_id IS NULL
                AND lel.state IN (:lease_pending, :lease_active)
                AND lel.expires_at > UTC_TIMESTAMP()'
            : '';
        $params = [
            'node_id' => $nodeId,
            'backend_mode' => 'leigod_mode',
            'client_mode' => 'single',
        ];
        if ($leasesEnabled) {
            $params += [
                'lease_owner_type' => 'node',
                'lease_owner_id' => $nodeId,
                'lease_pending' => 'pending',
                'lease_active' => 'active',
            ];
        }
        $rows = $this->db->fetchAll(
            'SELECT DISTINCT ll.*, ll.id AS catalog_id, fcll.sort_order AS membership_sort_order' . $leaseSelect . '
             FROM nodes n
             INNER JOIN node_groups ng ON ng.id = n.group_id
             INNER JOIN forward_combinations fc ON fc.node_group_key = ng.group_key
             INNER JOIN forward_combination_leigod_lines fcll ON fcll.forward_combination_id = fc.id
             INNER JOIN leigod_lines ll ON ll.id = fcll.leigod_line_id' . $leaseJoin . '
             WHERE n.id = :node_id
               AND fc.is_active = 1
               AND fc.backend_mode = :backend_mode
               AND fc.client_mode = :client_mode
               AND fc.sidecar_point_id IS NULL
               AND fcll.enabled = 1
               AND ll.is_active = 1' . $leaseWhere . '
             ORDER BY fcll.sort_order ASC, ll.sort_order ASC, ll.id ASC',
            $params
        );
        $ready = [];
        $seen = [];
        foreach ($rows as $row) {
            $lineId = (int) ($row['line_id'] ?? 0);
            if ($lineId <= 0 || isset($seen[$lineId])) {
                continue;
            }
            if ($leasesEnabled && !$this->nodeLeaseMatches($row, $nodeId, true)) {
                continue;
            }
            $configuration = $this->configurationForLine($row);
            if ($configuration['state'] !== 'ready') {
                continue;
            }
            $row['_configuration'] = $configuration;
            $ready[] = $row;
            $seen[$lineId] = true;
        }
        return $ready;
    }

    /** @return array<int, array<string, mixed>> */
    private function routeCandidateRows(int $routeId): array
    {
        if ($routeId <= 0) {
            return [];
        }
        [$leaseSelect, $leaseJoin, $leaseWhere, $leaseParams] = $this->candidateLeaseSql();
        return $this->db->fetchAll(
            'SELECT fr.id AS route_id,
                    frc.sort_order AS combination_sort_order,
                    fc.id AS combination_id,
                    fcll.sort_order AS membership_sort_order,
                    ll.id AS catalog_id,
                    ll.line_id,
                    ll.label,
                    ll.config_sha256,
                    ll.udp_probe_method,
                    ll.is_active AS line_is_active,
                    n.id AS node_id,
                    n.name AS node_name,
                    n.public_ip AS node_public_ip,
                    n.private_ip AS node_private_ip,
                    n.status AS node_status,
                    n.agent_config_json AS node_agent_config_json,
                    ng.group_key AS node_group_key,
                    nllh.config_sha256 AS health_config_sha256,
                    nllh.service_state,
                    nllh.tcp_status,
                    nllh.tcp_connect_ms,
                    nllh.udp_status,
                    nllh.consecutive_successes,
                    nllh.consecutive_failures,
                    nllh.last_error,
                    nllh.checked_at' . $leaseSelect . '
             FROM forward_routes fr
             INNER JOIN forward_route_combinations frc ON frc.forward_route_id = fr.id AND frc.enabled = 1
             INNER JOIN forward_combinations fc ON fc.id = frc.forward_combination_id AND fc.is_active = 1
             INNER JOIN node_groups ng ON ng.group_key = fc.node_group_key
             INNER JOIN nodes n ON n.group_id = ng.id AND (n.status = :node_active OR n.status = :node_degraded)
             INNER JOIN forward_combination_leigod_lines fcll ON fcll.forward_combination_id = fc.id AND fcll.enabled = 1
             INNER JOIN leigod_lines ll ON ll.id = fcll.leigod_line_id AND ll.is_active = 1' . $leaseJoin . '
             INNER JOIN node_leigod_line_health nllh ON nllh.node_id = n.id AND nllh.leigod_line_id = ll.id
             WHERE fr.id = :route_id
               AND fr.is_active = 1
               AND fc.backend_mode = :backend_mode
               AND (
                    (fc.client_mode = :client_mode AND fc.sidecar_point_id IS NULL)
                    OR (fc.client_mode = :bond_client_mode AND fc.sidecar_point_id IS NOT NULL)
               )' . $leaseWhere . '
             ORDER BY n.id ASC, frc.sort_order ASC, fcll.sort_order ASC, ll.line_id ASC',
            [
                'route_id' => $routeId,
                'node_active' => 'active',
                'node_degraded' => 'degraded',
                'backend_mode' => 'leigod_mode',
                'client_mode' => 'single',
                'bond_client_mode' => 'bond',
            ] + $leaseParams
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function combinationCandidateRows(int $combinationId): array
    {
        if ($combinationId <= 0) {
            return [];
        }
        [$leaseSelect, $leaseJoin, $leaseWhere, $leaseParams] = $this->candidateLeaseSql();
        return $this->db->fetchAll(
            'SELECT 0 AS route_id,
                    fc.sort_order AS combination_sort_order,
                    fc.id AS combination_id,
                    fcll.sort_order AS membership_sort_order,
                    ll.id AS catalog_id,
                    ll.line_id,
                    ll.label,
                    ll.config_sha256,
                    ll.udp_probe_method,
                    ll.is_active AS line_is_active,
                    n.id AS node_id,
                    n.name AS node_name,
                    n.public_ip AS node_public_ip,
                    n.private_ip AS node_private_ip,
                    n.status AS node_status,
                    n.agent_config_json AS node_agent_config_json,
                    ng.group_key AS node_group_key,
                    nllh.config_sha256 AS health_config_sha256,
                    nllh.service_state,
                    nllh.tcp_status,
                    nllh.tcp_connect_ms,
                    nllh.udp_status,
                    nllh.consecutive_successes,
                    nllh.consecutive_failures,
                    nllh.last_error,
                    nllh.checked_at' . $leaseSelect . '
             FROM forward_combinations fc
             INNER JOIN node_groups ng ON ng.group_key = fc.node_group_key
             INNER JOIN nodes n ON n.group_id = ng.id AND (n.status = :node_active OR n.status = :node_degraded)
             INNER JOIN forward_combination_leigod_lines fcll ON fcll.forward_combination_id = fc.id AND fcll.enabled = 1
             INNER JOIN leigod_lines ll ON ll.id = fcll.leigod_line_id AND ll.is_active = 1' . $leaseJoin . '
             INNER JOIN node_leigod_line_health nllh ON nllh.node_id = n.id AND nllh.leigod_line_id = ll.id
             WHERE fc.id = :combination_id
               AND fc.is_active = 1
               AND fc.backend_mode = :backend_mode
               AND (
                    (fc.client_mode = :client_mode AND fc.sidecar_point_id IS NULL)
                    OR (fc.client_mode = :bond_client_mode AND fc.sidecar_point_id IS NOT NULL)
               )' . $leaseWhere . '
             ORDER BY n.id ASC, fcll.sort_order ASC, ll.line_id ASC',
            [
                'combination_id' => $combinationId,
                'node_active' => 'active',
                'node_degraded' => 'degraded',
                'backend_mode' => 'leigod_mode',
                'client_mode' => 'single',
                'bond_client_mode' => 'bond',
            ] + $leaseParams
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function eligibleNodesFromCandidateRows(array $rows, string $transport, int $requiredCombinationCount): array
    {
        $transport = $this->eligibilityTransport($transport);
        $maxAge = max(15, min(3600, $this->config->getInt('LEIGOD_LINE_HEALTH_MAX_AGE_SECONDS', 180)));
        $now = time();
        $nodes = [];
        foreach ($rows as $row) {
            $nodeId = (int) ($row['node_id'] ?? 0);
            $combinationId = (int) ($row['combination_id'] ?? 0);
            $lineId = (int) ($row['line_id'] ?? 0);
            if ($nodeId <= 0 || $combinationId <= 0 || $lineId < 1 || $lineId > self::MAX_LINE_ID) {
                continue;
            }
            if (array_key_exists('line_is_active', $row) && (int) $row['line_is_active'] !== 1) {
                continue;
            }
            $nodeStatus = strtolower(trim((string) ($row['node_status'] ?? 'active')));
            if (!in_array($nodeStatus, ['active', 'degraded'], true)) {
                continue;
            }
            $configuration = $this->configurationForLine($row);
            $expectedHash = strtolower(trim((string) ($row['config_sha256'] ?? '')));
            $healthHash = strtolower(trim((string) ($row['health_config_sha256'] ?? '')));
            // Health DATETIME values are persisted in UTC regardless of the PHP host timezone.
            $checkedDate = \DateTimeImmutable::createFromFormat(
                '!Y-m-d H:i:s',
                (string) ($row['checked_at'] ?? ''),
                new \DateTimeZone('UTC')
            );
            $checkedAt = $checkedDate !== false ? $checkedDate->getTimestamp() : false;
            if ($configuration['state'] !== 'ready'
                || preg_match('/^[a-f0-9]{64}$/', $healthHash) !== 1
                || !hash_equals($expectedHash, $healthHash)
                || strtolower(trim((string) ($row['service_state'] ?? ''))) !== 'active'
                || $checkedAt === false
                || $checkedAt < ($now - $maxAge)
                || $checkedAt > ($now + 300)
                || !$this->rowSupportsTransport($row, $transport)) {
                continue;
            }
            if ($this->leasesEnabled() && !$this->candidateLeaseMatches($row, $nodeId)) {
                continue;
            }

            if (!isset($nodes[$nodeId])) {
                $nodes[$nodeId] = [
                    'id' => $nodeId,
                    'node_id' => $nodeId,
                    'name' => (string) ($row['node_name'] ?? ''),
                    'public_ip' => (string) ($row['node_public_ip'] ?? ''),
                    'private_ip' => (string) ($row['node_private_ip'] ?? ''),
                    'status' => $nodeStatus,
                    'agent_config_json' => $row['node_agent_config_json'] ?? null,
                    'node_group_key' => (string) ($row['node_group_key'] ?? ''),
                    '_by_combination' => [],
                ];
            }
            $candidateKey = $combinationId . ':' . $lineId;
            $candidate = [
                'combination_id' => $combinationId,
                'line_id' => $lineId,
                'config_sha256' => $expectedHash,
                'socks_host' => '127.0.0.1',
                'socks_port' => 20000 + $lineId,
                'combination_sort_order' => (int) ($row['combination_sort_order'] ?? 100),
                'sort_order' => (int) ($row['membership_sort_order'] ?? 100),
                'service_state' => 'active',
                'tcp_status' => strtolower(trim((string) ($row['tcp_status'] ?? 'unknown'))),
                'tcp_connect_ms' => ($row['tcp_connect_ms'] ?? null) !== null ? (int) $row['tcp_connect_ms'] : null,
                'udp_status' => strtolower(trim((string) ($row['udp_status'] ?? 'unknown'))),
                'checked_at' => gmdate('c', $checkedAt),
            ];
            if ($this->leasesEnabled()) {
                $candidate += $this->leaseProjection($row);
            }
            $nodes[$nodeId]['_by_combination'][$combinationId][$candidateKey] = $candidate;
        }

        $eligible = [];
        foreach ($nodes as $node) {
            $byCombination = $node['_by_combination'];
            if (count($byCombination) < $requiredCombinationCount) {
                continue;
            }
            $combinationGroups = [];
            foreach ($byCombination as $combinationId => $candidates) {
                $candidates = array_values($candidates);
                usort($candidates, [$this, 'compareCandidates']);
                $combinationGroups[(int) $combinationId] = $candidates;
            }
            uasort($combinationGroups, static function (array $left, array $right): int {
                return [
                    (int) ($left[0]['combination_sort_order'] ?? 100),
                    (int) ($left[0]['combination_id'] ?? 0),
                ] <=> [
                    (int) ($right[0]['combination_sort_order'] ?? 100),
                    (int) ($right[0]['combination_id'] ?? 0),
                ];
            });
            $candidates = [];
            $selectedKeys = [];
            foreach ($combinationGroups as $group) {
                $candidate = $group[0];
                $key = (int) $candidate['combination_id'] . ':' . (int) $candidate['line_id'];
                $candidates[] = $candidate;
                $selectedKeys[$key] = true;
            }
            $remaining = [];
            foreach ($combinationGroups as $group) {
                foreach ($group as $candidate) {
                    $key = (int) $candidate['combination_id'] . ':' . (int) $candidate['line_id'];
                    if (!isset($selectedKeys[$key])) {
                        $remaining[] = $candidate;
                    }
                }
            }
            usort($remaining, [$this, 'compareCandidates']);
            $candidateCap = max(
                $requiredCombinationCount,
                min(8, max(1, $this->config->getInt('FORWARD_ROUTE_RACE_CANDIDATE_CAP', 8)))
            );
            $candidates = array_slice(
                array_merge($candidates, $remaining),
                0,
                $candidateCap
            );
            unset($node['_by_combination']);
            $node['healthy_combination_count'] = count($byCombination);
            $node['candidates'] = $candidates;
            $eligible[] = $node;
        }
        usort($eligible, static fn(array $left, array $right): int => (int) $left['id'] <=> (int) $right['id']);
        return $eligible;
    }

    /** @param array<string, mixed> $row */
    private function rowSupportsTransport(array $row, string $transport): bool
    {
        $tcpHealthy = strtolower(trim((string) ($row['tcp_status'] ?? 'unknown'))) === 'healthy';
        $udpStatus = strtolower(trim((string) ($row['udp_status'] ?? 'unknown')));
        $udpProbeConfigured = trim((string) ($row['udp_probe_method'] ?? '')) !== '';
        $udpHealthy = $udpStatus === 'healthy' || (!$udpProbeConfigured && $udpStatus === 'unknown');
        return match ($transport) {
            'udp' => $udpHealthy,
            'both' => $tcpHealthy && $udpHealthy,
            default => $tcpHealthy,
        };
    }

    private function eligibilityTransport(string $transport): string
    {
        $transport = strtolower(trim($transport));
        if ($transport === 'both' || str_contains($transport, '+')) {
            return 'both';
        }
        return $transport === 'udp' ? 'udp' : 'tcp';
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    private function compareCandidates(array $left, array $right): int
    {
        return [
            (int) ($left['combination_sort_order'] ?? 100),
            (int) ($left['sort_order'] ?? 100),
            (int) ($left['line_id'] ?? 0),
        ] <=> [
            (int) ($right['combination_sort_order'] ?? 100),
            (int) ($right['sort_order'] ?? 100),
            (int) ($right['line_id'] ?? 0),
        ];
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
