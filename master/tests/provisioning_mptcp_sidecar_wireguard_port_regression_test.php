<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {

    final class Database
    {
        /** @var array<string, array<int, array<string, mixed>>> */
        private array $tables = [];

        /**
         * @param array<int, array<string, mixed>> $rows
         */
        public function seed(string $table, array $rows): void
        {
            $this->tables[$table] = array_values($rows);
        }

        /**
         * @param array<string, mixed> $params
         * @return array<int, array<string, mixed>>
         */
        public function fetchAll(string $sql, array $params = []): array
        {
            $normalized = $this->normalizeSql($sql);

            if (str_contains($normalized, 'FROM forwards f INNER JOIN services s ON s.id = f.service_id')) {
                $pointId = (int) ($params['point_id'] ?? 0);
                $clientMode = (string) ($params['client_mode'] ?? '');
                $serviceStatus = (string) ($params['service_status'] ?? '');
                $rows = [];
                foreach ($this->rows('forwards') as $forward) {
                    if ((int) ($forward['bond_sidecar_point_id'] ?? 0) !== $pointId) {
                        continue;
                    }
                    if ((int) ($forward['enabled'] ?? 0) !== 1) {
                        continue;
                    }
                    if ($clientMode !== '' && (string) ($forward['client_mode'] ?? '') !== $clientMode) {
                        continue;
                    }
                    $service = $this->findById('services', (int) ($forward['service_id'] ?? 0));
                    if ($service === null || ($serviceStatus !== '' && (string) ($service['status'] ?? '') !== $serviceStatus)) {
                        continue;
                    }
                    $rows[] = array_merge($forward, ['service_status' => (string) ($service['status'] ?? '')]);
                }
                usort($rows, static fn(array $left, array $right): int => ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0)));
                return $rows;
            }

            if (str_contains($normalized, 'FROM service_endpoints e INNER JOIN nodes n ON n.id = e.node_id')) {
                $forwardId = (int) ($params['forward_id'] ?? 0);
                $deleted = (string) ($params['deleted'] ?? '');
                $deleting = (string) ($params['deleting'] ?? '');
                $rows = [];
                foreach ($this->rows('service_endpoints') as $endpoint) {
                    if ((int) ($endpoint['forward_id'] ?? 0) !== $forwardId) {
                        continue;
                    }
                    $health = (string) ($endpoint['health_status'] ?? '');
                    if ($health === $deleted || $health === $deleting) {
                        continue;
                    }
                    $node = $this->findById('nodes', (int) ($endpoint['node_id'] ?? 0));
                    if ($node === null) {
                        continue;
                    }
                    $rows[] = array_merge($endpoint, [
                        'node_name' => (string) ($node['name'] ?? ''),
                        'node_public_ip' => (string) ($node['public_ip'] ?? ''),
                        'node_agent_config_json' => $node['agent_config_json'] ?? null,
                    ]);
                }
                usort($rows, static fn(array $left, array $right): int => [((int) ($left['priority'] ?? 0)), ((int) ($left['id'] ?? 0))] <=> [((int) ($right['priority'] ?? 0)), ((int) ($right['id'] ?? 0))]);
                return $rows;
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

            if (str_contains($normalized, 'FROM sidecar_points sp LEFT JOIN users u ON u.id = sp.owner_user_id')) {
                return $this->findById('sidecar_points', (int) ($params['id'] ?? 0));
            }

            if (str_contains($normalized, 'SELECT agent_config_json FROM clients WHERE id = :id LIMIT 1')) {
                $client = $this->findById('clients', (int) ($params['id'] ?? 0));
                return $client === null ? null : ['agent_config_json' => $client['agent_config_json'] ?? null];
            }

            if (str_contains($normalized, 'SELECT agent_config_json, sidecar_host, listen_port FROM sidecar_points WHERE id = :id LIMIT 1')) {
                $point = $this->findById('sidecar_points', (int) ($params['id'] ?? 0));
                if ($point === null) {
                    return null;
                }
                return [
                    'agent_config_json' => $point['agent_config_json'] ?? null,
                    'sidecar_host' => $point['sidecar_host'] ?? '',
                    'listen_port' => $point['listen_port'] ?? 0,
                ];
            }

            return null;
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

    use Leikwan\Master\Config;
    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Support\ProvisioningService;

    function fail(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    function call_private(object $instance, string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod($instance, $method);
        $ref->setAccessible(true);
        return $ref->invoke($instance, ...$args);
    }

    $envPath = tempnam(sys_get_temp_dir(), 'lw-mptcp-sidecar-port-env-');
    if ($envPath === false) {
        fail('failed to create temp env file');
    }
    file_put_contents($envPath, "DEFAULT_TLS_CERT_REF=default-edge-cert\n");

    $db = new Database();
    $db->seed('services', [
        ['id' => 1, 'status' => 'active'],
    ]);
    $db->seed('clients', [
        [
            'id' => 1,
            'agent_config_json' => json_encode([
                'wireguard_public_key' => 'client-public-key',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ],
    ]);
    $db->seed('sidecar_points', [
        [
            'id' => 4,
            'name' => 'szhk-ixp1',
            'sidecar_host' => '138.252.162.120',
            'listen_port' => 9000,
            'agent_config_json' => json_encode([
                'supports_mptcp' => true,
                'wireguard_public_key' => 'sidecar-public-key',
                'wireguard_listen_port' => 14969,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ],
    ]);
    $db->seed('nodes', [
        [
            'id' => 5,
            'name' => 'gz-ali-3',
            'public_ip' => '8.163.90.132',
            'agent_config_json' => json_encode([
                'wireguard_public_key' => 'slave-public-key',
                'wireguard_listen_port' => 9800,
                'wireguard_fallback_tcp_port' => 19800,
                'wireguard_outer_transport' => 'tcp',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ],
    ]);
    $db->seed('forwards', [
        [
            'id' => 25,
            'service_id' => 1,
            'line_client_id' => 1,
            'bond_sidecar_point_id' => 4,
            'client_mode' => 'mptcp',
            'enabled' => 1,
        ],
    ]);
    $db->seed('service_endpoints', [
        [
            'id' => 179,
            'forward_id' => 25,
            'node_id' => 5,
            'public_port' => 7000,
            'priority' => 10,
            'health_status' => 'active',
        ],
    ]);

    $service = new ProvisioningService(new Config($envPath), $db);

    $forward = [
        'id' => 25,
        'service_id' => 1,
        'line_client_id' => 1,
        'bond_sidecar_point_id' => 4,
    ];
    $node = [
        'id' => 5,
        'agent_config_json' => json_encode([
            'wireguard_public_key' => 'slave-public-key',
            'wireguard_listen_port' => 9800,
            'wireguard_fallback_tcp_port' => 19800,
            'wireguard_outer_transport' => 'tcp',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
    $endpoint = [
        'id' => 179,
        'public_port' => 7000,
    ];

    $underlay = call_private($service, 'buildMptcpSlaveUnderlayPayload', $forward, $node, $endpoint);
    if (!is_array($underlay)) {
        fail('expected buildMptcpSlaveUnderlayPayload to return an array');
    }
    if (($underlay['sidecar_endpoint_port'] ?? null) !== 14985) {
        fail('expected slave underlay to derive sidecar_endpoint_port 14985 from sidecar wireguard listen port, got ' . var_export($underlay['sidecar_endpoint_port'] ?? null, true));
    }

    $management = $service->mptcpSidecarManagementForPoint(4);
    $firstUnderlay = $management['underlays'][0] ?? null;
    if (!is_array($firstUnderlay)) {
        fail('expected mptcpSidecarManagementForPoint to emit an underlay');
    }
    if (($firstUnderlay['listen_port'] ?? null) !== 14985) {
        fail('expected sidecar management underlay listen_port 14985, got ' . var_export($firstUnderlay['listen_port'] ?? null, true));
    }

    echo "ok\n";
}
