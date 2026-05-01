<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {

    final class Database
    {
        /** @var array<string, array<int, array<string, mixed>>> */
        private array $tables = [];

        /** @var array<string, int> */
        private array $autoIds = [
            'service_endpoints' => 1,
            'provision_tasks' => 1,
        ];

        /**
         * @param array<int, array<string, mixed>> $rows
         */
        public function seed(string $table, array $rows): void
        {
            $this->tables[$table] = array_values($rows);
            $maxId = 0;
            foreach ($rows as $row) {
                $maxId = max($maxId, (int) ($row['id'] ?? 0));
            }
            if ($maxId > 0) {
                $this->autoIds[$table] = $maxId + 1;
            }
        }

        /**
         * @param array<string, mixed> $params
         * @return array<int, array<string, mixed>>
         */
        public function fetchAll(string $sql, array $params = []): array
        {
            $normalized = $this->normalizeSql($sql);

            if (str_contains($normalized, 'FROM provision_tasks')) {
                $rows = $this->rows('provision_tasks');
                usort($rows, static fn(array $left, array $right): int => ((int) $left['id']) <=> ((int) $right['id']));
                return array_map(static fn(array $row): array => [
                    'action' => $row['action'],
                    'payload_json' => $row['payload_json'],
                ], $rows);
            }

            if (str_contains($normalized, 'SELECT cidr, priority, is_active FROM cf_location_group_cidrs WHERE group_id = :group_id AND is_active = 1 ORDER BY priority ASC, id ASC')) {
                $groupId = (int) ($params['group_id'] ?? 0);
                $rows = array_values(array_filter($this->rows('cf_location_group_cidrs'), static function (array $row) use ($groupId): bool {
                    return (int) ($row['group_id'] ?? 0) === $groupId && (int) ($row['is_active'] ?? 0) === 1;
                }));
                usort($rows, static fn(array $left, array $right): int => [((int) ($left['priority'] ?? 0)), ((int) ($left['id'] ?? 0))] <=> [((int) ($right['priority'] ?? 0)), ((int) ($right['id'] ?? 0))]);
                return array_map(static fn(array $row): array => [
                    'cidr' => (string) ($row['cidr'] ?? ''),
                    'priority' => (int) ($row['priority'] ?? 0),
                    'is_active' => (int) ($row['is_active'] ?? 0),
                ], $rows);
            }

            if (str_contains($normalized, 'FROM forwards f INNER JOIN services s ON s.id = f.service_id')) {
                $rows = [];
                $serviceStatus = (string) ($params['service_status'] ?? '');
                $pointId = (int) ($params['point_id'] ?? 0);
                $serviceId = (int) ($params['service_id'] ?? 0);
                $allowedModes = [];
                foreach ($params as $key => $value) {
                    if ($key === 'client_mode' || str_starts_with($key, 'client_mode_')) {
                        $allowedModes[] = (string) $value;
                    }
                }
                foreach ($this->rows('forwards') as $forward) {
                    if ((int) ($forward['enabled'] ?? 0) !== 1) {
                        continue;
                    }
                    if ($serviceId > 0 && (int) ($forward['service_id'] ?? 0) !== $serviceId) {
                        continue;
                    }
                    if ($pointId > 0 && (int) ($forward['bond_sidecar_point_id'] ?? 0) !== $pointId) {
                        continue;
                    }
                    if ($allowedModes !== [] && !in_array((string) ($forward['client_mode'] ?? ''), $allowedModes, true)) {
                        continue;
                    }
                    $service = $this->findById('services', (int) ($forward['service_id'] ?? 0));
                    if ($service === null || ($serviceStatus !== '' && (string) ($service['status'] ?? '') !== $serviceStatus)) {
                        continue;
                    }
                    $rows[] = ['forward_id' => (int) $forward['id']];
                }
                return $rows;
            }

            if (str_contains($normalized, 'SELECT * FROM service_endpoints')) {
                $forwardId = (int) ($params['forward_id'] ?? 0);
                $deleted = (string) ($params['deleted'] ?? '');
                $deleting = (string) ($params['deleting'] ?? '');
                $rows = array_values(array_filter($this->rows('service_endpoints'), static function (array $row) use ($forwardId, $deleted, $deleting): bool {
                    if ((int) ($row['forward_id'] ?? 0) !== $forwardId) {
                        return false;
                    }
                    $health = (string) ($row['health_status'] ?? '');
                    return $health !== $deleted && $health !== $deleting;
                }));
                usort($rows, static fn(array $left, array $right): int => [((int) $left['priority']), ((int) $left['id'])] <=> [((int) $right['priority']), ((int) $right['id'])]);
                return $rows;
            }

            if (str_contains($normalized, 'SELECT n.* FROM nodes n LEFT JOIN node_groups g ON g.id = n.group_id')) {
                $nodes = $this->matchingNodes($params, $normalized);
                usort($nodes, static function (array $left, array $right): int {
                    $loadCmp = ((int) $left['current_load']) <=> ((int) $right['current_load']);
                    if ($loadCmp !== 0) {
                        return $loadCmp;
                    }
                    $heartbeatCmp = strcmp((string) ($right['last_heartbeat_at'] ?? ''), (string) ($left['last_heartbeat_at'] ?? ''));
                    if ($heartbeatCmp !== 0) {
                        return $heartbeatCmp;
                    }
                    return ((int) $left['id']) <=> ((int) $right['id']);
                });
                if (preg_match('/LIMIT (\d+)$/', $normalized, $matches) === 1) {
                    $nodes = array_slice($nodes, 0, (int) $matches[1]);
                }
                return $nodes;
            }

            return [];
        }

        /**
         * @param array<string, mixed> $params
         * @return array<string, mixed>|null
         */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            $normalized = $this->normalizeSql($sql);

            if (str_contains($normalized, 'SELECT f.*, s.client_token, s.status AS service_status, s.name AS service_name,')) {
                $forward = $this->findById('forwards', (int) ($params['id'] ?? 0));
                if ($forward === null) {
                    return null;
                }
                $service = $this->findById('services', (int) ($forward['service_id'] ?? 0));
                if ($service === null) {
                    return null;
                }
                $point = (int) ($forward['bond_sidecar_point_id'] ?? 0) > 0
                    ? $this->findById('sidecar_points', (int) $forward['bond_sidecar_point_id'])
                    : null;
                return array_merge($forward, [
                    'client_token' => (string) ($service['client_token'] ?? ''),
                    'service_status' => (string) ($service['status'] ?? ''),
                    'service_name' => (string) ($service['name'] ?? ''),
                    'service_bandwidth_limit_mbps' => (int) ($service['bandwidth_limit_mbps'] ?? 0),
                    'line_client_name' => null,
                    'line_client_host' => null,
                    'line_speed_limit_mbps' => 0,
                    'line_client_agent_config_json' => null,
                    'line_client_group_name' => null,
                    'forward_chain_name' => null,
                    'bond_sidecar_point_name' => (string) ($point['name'] ?? ''),
                    'bond_sidecar_host' => (string) ($point['sidecar_host'] ?? ''),
                    'bond_sidecar_port' => isset($point['listen_port']) ? (int) $point['listen_port'] : null,
                    'bond_sidecar_point_token_secret' => (string) ($point['token_secret'] ?? ''),
                    'bond_sidecar_point_agent_config_json' => $point['agent_config_json'] ?? null,
                ]);
            }

            if (str_contains($normalized, 'SELECT f.*, s.status AS service_status FROM forwards f INNER JOIN services s ON s.id = f.service_id WHERE f.id = :id LIMIT 1')) {
                $forward = $this->findById('forwards', (int) ($params['id'] ?? 0));
                if ($forward === null) {
                    return null;
                }
                $service = $this->findById('services', (int) ($forward['service_id'] ?? 0));
                if ($service === null) {
                    return null;
                }
                return array_merge($forward, [
                    'service_status' => (string) ($service['status'] ?? ''),
                ]);
            }

            if (str_contains($normalized, 'SELECT COUNT(*) AS total FROM nodes n LEFT JOIN node_groups g ON g.id = n.group_id')) {
                return [
                    'total' => count($this->matchingNodes($params, $normalized)),
                ];
            }

            if (str_contains($normalized, 'SELECT sidecar_host, listen_port FROM sidecar_points WHERE id = :id LIMIT 1')) {
                $point = $this->findById('sidecar_points', (int) ($params['id'] ?? 0));
                if ($point === null) {
                    return null;
                }
                return [
                    'sidecar_host' => $point['sidecar_host'],
                    'listen_port' => $point['listen_port'],
                ];
            }

            if (str_contains($normalized, 'SELECT sidecar_host, listen_port, bond_listen_port, mptcp_listen_port, cf_listen_port, agent_config_json FROM sidecar_points WHERE id = :id LIMIT 1')) {
                $point = $this->findById('sidecar_points', (int) ($params['id'] ?? 0));
                if ($point === null) {
                    return null;
                }
                return [
                    'sidecar_host' => $point['sidecar_host'],
                    'listen_port' => $point['listen_port'],
                    'bond_listen_port' => $point['bond_listen_port'] ?? null,
                    'mptcp_listen_port' => $point['mptcp_listen_port'] ?? null,
                    'cf_listen_port' => $point['cf_listen_port'] ?? null,
                    'agent_config_json' => $point['agent_config_json'] ?? null,
                ];
            }

            if (str_contains($normalized, 'SELECT * FROM cf_location_groups WHERE id = :id LIMIT 1')) {
                return $this->findById('cf_location_groups', (int) ($params['id'] ?? 0));
            }

            if (str_contains($normalized, 'SELECT * FROM node_groups WHERE group_key = :group_key LIMIT 1')) {
                foreach ($this->rows('node_groups') as $group) {
                    if ((string) ($group['group_key'] ?? '') === (string) ($params['group_key'] ?? '')) {
                        return $group;
                    }
                }
                return null;
            }

            return null;
        }

        /**
         * @param array<string, mixed> $data
         */
        public function insert(string $table, array $data): int
        {
            $id = isset($data['id']) ? (int) $data['id'] : ($this->autoIds[$table] ?? 1);
            $data['id'] = $id;
            $this->tables[$table][] = $data;
            $this->autoIds[$table] = $id + 1;
            return $id;
        }

        /**
         * @param array<string, mixed> $params
         */
        public function execute(string $sql, array $params = []): int
        {
            $normalized = $this->normalizeSql($sql);
            if (str_contains($normalized, 'UPDATE service_endpoints SET health_status = :status, updated_at = :updated_at WHERE id = :id')) {
                foreach ($this->tables['service_endpoints'] ?? [] as &$row) {
                    if ((int) ($row['id'] ?? 0) === (int) ($params['id'] ?? 0)) {
                        $row['health_status'] = $params['status'] ?? $row['health_status'] ?? '';
                        $row['updated_at'] = $params['updated_at'] ?? $row['updated_at'] ?? '';
                        return 1;
                    }
                }
            }
            return 0;
        }

        /**
         * @return mixed
         */
        public function transaction(callable $callback)
        {
            return $callback($this);
        }

        /**
         * @return array<int, array<string, mixed>>
         */
        private function rows(string $table): array
        {
            return array_values($this->tables[$table] ?? []);
        }

        /**
         * @return array<string, mixed>|null
         */
        private function findById(string $table, int $id): ?array
        {
            foreach ($this->rows($table) as $row) {
                if ((int) ($row['id'] ?? 0) === $id) {
                    return $row;
                }
            }
            return null;
        }

        private function normalizeSql(string $sql): string
        {
            return preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
        }

        /**
         * @param array<string, mixed> $params
         * @return array<int, array<string, mixed>>
         */
        private function matchingNodes(array $params, string $normalizedSql): array
        {
            $serviceId = isset($params['owner_service_id']) ? (int) $params['owner_service_id'] : (isset($params['svc_id_for_group']) ? (int) $params['svc_id_for_group'] : null);
            $groupKeys = [];
            if (isset($params['group_key'])) {
                $groupKeys[] = (string) $params['group_key'];
            }
            foreach ($params as $key => $value) {
                if (str_starts_with($key, 'group_key_')) {
                    $groupKeys[] = (string) $value;
                }
            }

            $serviceUserId = null;
            if ($serviceId !== null) {
                $service = $this->findById('services', $serviceId);
                $serviceUserId = $service !== null ? (int) ($service['user_id'] ?? 0) : null;
            }

            $groupIdsByKey = [];
            foreach ($this->rows('node_groups') as $group) {
                $groupIdsByKey[(string) ($group['group_key'] ?? '')] = (int) ($group['id'] ?? 0);
            }

            $sharedGroupIds = [];
            if ($serviceUserId !== null) {
                foreach ($this->rows('user_slave_group_members') as $membership) {
                    if ((int) ($membership['user_id'] ?? 0) === $serviceUserId) {
                        $sharedGroupIds[] = (int) ($membership['group_id'] ?? 0);
                    }
                }
            }

            $rows = [];
            foreach ($this->rows('nodes') as $node) {
                $status = (string) ($node['status'] ?? '');
                if (!in_array($status, [(string) ($params['active'] ?? 'active'), (string) ($params['degraded'] ?? 'degraded')], true)) {
                    continue;
                }

                if ($groupKeys !== [] && !in_array('default', $groupKeys, true)) {
                    $nodeGroupId = (int) ($node['group_id'] ?? 0);
                    $matched = false;
                    foreach ($groupKeys as $groupKey) {
                        if (($groupIdsByKey[$groupKey] ?? 0) === $nodeGroupId) {
                            $matched = true;
                            break;
                        }
                    }
                    if (!$matched) {
                        continue;
                    }
                }

                if ($serviceId !== null) {
                    $ownerServiceId = $node['owner_service_id'] ?? null;
                    $isVisible = $ownerServiceId === null || (int) $ownerServiceId === $serviceId;
                    if (!$isVisible) {
                        $isVisible = (string) ($node['sharing_mode'] ?? '') === (string) ($params['shared'] ?? '')
                            && in_array((int) ($node['user_slave_group_id'] ?? 0), $sharedGroupIds, true);
                    }
                    if (!$isVisible) {
                        continue;
                    }
                }

                $rows[] = $node;
            }

            return $rows;
        }
    }
}

namespace {

    spl_autoload_register(static function (string $class): void {
        $prefix = 'Leikwan\\Master\\';
        if (!str_starts_with($class, $prefix) || $class === 'Leikwan\\Master\\Database\\Database') {
            return;
        }

        $relative = substr($class, strlen($prefix));
        if ($relative === false) {
            return;
        }

        $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });

    if (!class_exists('Leikwan\\Master\\Exceptions\\ApiException') && class_exists('Leikwan\\Master\\Support\\ApiException')) {
        class_alias('Leikwan\\Master\\Support\\ApiException', 'Leikwan\\Master\\Exceptions\\ApiException');
    }

    use Leikwan\Master\Config;
    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Support\ProvisioningService;

    function fail(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    $envPath = tempnam(sys_get_temp_dir(), 'lw-prov-env-');
    if ($envPath === false) {
        fail('failed to create temp env file');
    }

    file_put_contents($envPath, "DEFAULT_TLS_CERT_REF=default-edge-cert\n");

    $config = new Config($envPath);
    $db = new Database();
    $db->seed('services', [[
        'id' => 1,
        'user_id' => 100,
        'status' => 'active',
        'name' => 'svc-1',
        'client_token' => 'svc-token',
        'bandwidth_limit_mbps' => 100,
    ]]);
    $db->seed('sidecar_points', [[
        'id' => 7,
        'name' => 'point-7',
        'sidecar_host' => 'sidecar.example.com',
        'listen_port' => 8443,
        'cf_listen_port' => 2053,
        'is_active' => 1,
        'token_secret' => 'point-secret',
        'agent_config_json' => null,
    ]]);
    $db->seed('cf_location_groups', [[
        'id' => 4,
        'name' => 'JP Premium',
        'region_code' => 'JP',
        'probe_mode' => 'tcp',
        'cache_ttl_seconds' => 120,
        'switch_improvement_ms' => 15,
        'max_candidates' => 32,
    ]]);
    $db->seed('cf_location_group_cidrs', [[
        'id' => 1,
        'group_id' => 4,
        'cidr' => '104.16.0.0/13',
        'priority' => 10,
        'is_active' => 1,
    ]]);
    $db->seed('node_groups', [[
        'id' => 1,
        'group_key' => 'default',
    ]]);
    $db->seed('nodes', [[
        'id' => 11,
        'group_id' => 1,
        'name' => 'edge-1.example.com',
        'public_ip' => '8.8.8.8',
        'status' => 'active',
        'current_load' => 0,
        'last_heartbeat_at' => '2026-04-15 00:00:00',
        'owner_service_id' => null,
        'sharing_mode' => null,
        'user_slave_group_id' => null,
        'agent_config_json' => null,
    ]]);
    $db->seed('user_slave_group_members', []);
    $db->seed('forwards', [[
        'id' => 101,
        'service_id' => 1,
        'enabled' => 1,
        'client_mode' => 'bond',
        'listen_port' => 11027,
        'node_group_key' => 'default',
        'endpoint_count' => 2,
        'public_port' => 443,
        'path_prefix' => '67bfc8797eec92a4',
        'sni_host' => 'edge.example.com',
        'cdn_host' => '',
        'filter_profile' => 'off',
        'transport_mode' => 'tcp+udp',
        'bond_sidecar_point_id' => 7,
        'bond_remote_host' => 'old-sidecar.example.com',
        'bond_remote_port' => 9000,
        'line_client_id' => null,
        'line_client_group_id' => null,
        'endpoint_mode' => 'slave_group',
        'forward_chain_id' => null,
        'forward_combination_id' => null,
        'line_group_mode' => 'selected',
        'line_client_group_ids_json' => null,
        'target_host' => '127.0.0.1',
        'target_port' => 8080,
        'backend_mode' => 'current_mode',
        'bond_ingress_mode' => 'wss_path_mux',
        'cf_location_group_id' => null,
    ], [
        'id' => 102,
        'service_id' => 1,
        'enabled' => 1,
        'client_mode' => 'bond',
        'listen_port' => 11035,
        'node_group_key' => 'default',
        'endpoint_count' => 5,
        'public_port' => 7000,
        'path_prefix' => '07289d1f5ed25399',
        'sni_host' => 'nrt1.nbwly.com',
        'cdn_host' => '',
        'filter_profile' => 'off',
        'transport_mode' => 'tcp',
        'bond_sidecar_point_id' => 7,
        'bond_remote_host' => 'sidecar.example.com',
        'bond_remote_port' => 8899,
        'line_client_id' => null,
        'line_client_group_id' => null,
        'endpoint_mode' => 'slave_group',
        'forward_chain_id' => null,
        'forward_combination_id' => null,
        'line_group_mode' => 'selected',
        'line_client_group_ids_json' => null,
        'target_host' => '127.0.0.1',
        'target_port' => 5201,
        'backend_mode' => 'cf_mode',
        'bond_ingress_mode' => 'plain_tcp_mux',
        'cf_location_group_id' => 4,
    ]]);
    $db->seed('service_endpoints', []);
    $db->seed('provision_tasks', []);

    $service = new ProvisioningService($config, $db);
    $service->republishActiveForwardsForService(1);

    $tasks = $db->fetchAll('SELECT action, payload_json FROM provision_tasks ORDER BY id ASC');
    if (count($tasks) !== 2) {
        fail('expected 2 publish tasks for bond sidecar republish, got ' . count($tasks));
    }

    $decodedTasks = array_map(static function (array $task): array {
        $payload = json_decode((string) ($task['payload_json'] ?? ''), true);
        if (!is_array($payload)) {
            fail('task payload is not valid JSON');
        }
        return [
            'action' => $task['action'] ?? null,
            'payload' => $payload,
        ];
    }, $tasks);

    foreach ($decodedTasks as $task) {
        if (($task['action'] ?? '') !== 'publish') {
            fail('expected publish task, got ' . var_export($task['action'] ?? null, true));
        }
    }

    $legacyPayload = null;
    $cfPayload = null;
    foreach ($decodedTasks as $task) {
        $payload = $task['payload'];
        if (($payload['backend_mode'] ?? null) === 'cf_mode') {
            $cfPayload = $payload;
            continue;
        }
        $legacyPayload = $payload;
    }

    if (!is_array($legacyPayload) || !is_array($cfPayload)) {
        fail('expected both current_mode and cf_mode publish payloads');
    }

    if (($cfPayload['target'] ?? null) !== '127.0.0.1:8899') {
        fail('expected cf publish target 127.0.0.1:8899, got ' . var_export($cfPayload['target'] ?? null, true));
    }
    if (($cfPayload['ingress_protocol'] ?? null) !== 'plain_tcp_mux') {
        fail('expected cf payload ingress_protocol plain_tcp_mux, got ' . var_export($cfPayload['ingress_protocol'] ?? null, true));
    }
    if (($cfPayload['backend_protocol'] ?? null) !== 'wss_backend') {
        fail('expected cf payload backend_protocol wss_backend, got ' . var_export($cfPayload['backend_protocol'] ?? null, true));
    }
    $cfMode = is_array($cfPayload['cf_mode'] ?? null) ? $cfPayload['cf_mode'] : [];
    if (($cfMode['dial_host'] ?? null) !== 'sidecar.example.com') {
        fail('expected cf payload dial_host sidecar.example.com, got ' . var_export($cfMode['dial_host'] ?? null, true));
    }
    if (($cfMode['dial_port'] ?? null) !== 2053) {
        fail('expected cf payload dial_port 2053, got ' . var_export($cfMode['dial_port'] ?? null, true));
    }

    echo "ok\n";
}
