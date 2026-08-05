<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<string, array<int, array<string, mixed>>> */
        private array $tables = [];
        /** @var array<int, array<string, mixed>> */
        public array $availabilityRows = [];

        /** @param array<int, array<string, mixed>> $rows */
        public function seed(string $table, array $rows): void
        {
            $this->tables[$table] = array_values($rows);
        }

        /** @param array<string, mixed> $params */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            $normalized = $this->normalize($sql);
            if (str_contains($normalized, 'SELECT f.*, s.client_token, s.status AS service_status, s.name AS service_name,')) {
                $forward = $this->findById('forwards', (int) ($params['id'] ?? 0));
                if ($forward === null) {
                    return null;
                }
                $service = $this->findById('services', (int) ($forward['service_id'] ?? 0)) ?? [];
                $combination = $this->findById('forward_combinations', (int) ($forward['forward_combination_id'] ?? 0)) ?? [];
                $point = $this->findById('sidecar_points', (int) ($forward['bond_sidecar_point_id'] ?? 0)) ?? [];
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
                    'forward_combination_name' => (string) ($combination['name'] ?? ''),
                    'forward_combination_label' => (string) ($combination['tenant_label'] ?? ''),
                    'forward_combination_runtime_preference' => (string) ($combination['runtime_preference'] ?? 'single'),
                    'forward_combination_allow_private_self_leg' => 0,
                    'forward_combination_self_leg_scope' => 'none',
                    'forward_combination_self_leg_role' => 'none',
                    'forward_combination_helper_group_cap' => 0,
                    'forward_combination_helper_escalation_policy' => 'disabled',
                    'forward_combination_self_leg_cf_transport' => 'cf_direct',
                    'forward_combination_desired_latency_ms' => 0,
                    'forward_combination_allow_public_self_leg_donation' => 0,
                    'forward_combination_donation_qualify_window_seconds' => 60,
                    'forward_combination_donation_revoke_window_seconds' => 30,
                    'forward_combination_donation_min_open_ports' => 1,
                    'bond_sidecar_host' => (string) ($point['sidecar_host'] ?? ''),
                    'bond_sidecar_legacy_port' => (int) ($point['listen_port'] ?? 0),
                    'bond_sidecar_port' => (int) ($point['bond_listen_port'] ?? 0),
                    'mptcp_sidecar_port' => (int) ($point['mptcp_listen_port'] ?? 0),
                    'bond_sidecar_cf_listen_port' => (int) ($point['cf_listen_port'] ?? 0),
                    'bond_sidecar_point_token_secret' => (string) ($point['token_secret'] ?? ''),
                    'bond_sidecar_point_agent_config_json' => $point['agent_config_json'] ?? null,
                ]);
            }
            if (str_contains($normalized, 'FROM sidecar_points sp') && str_contains($normalized, 'WHERE sp.id = :id')) {
                return $this->findById('sidecar_points', (int) ($params['id'] ?? 0));
            }
            if (str_contains($normalized, 'SELECT * FROM node_groups WHERE group_key = :group_key LIMIT 1')) {
                foreach ($this->tables['node_groups'] ?? [] as $row) {
                    if ((string) ($row['group_key'] ?? '') === (string) ($params['group_key'] ?? '')) {
                        return $row;
                    }
                }
            }
            return null;
        }

        /** @param array<string, mixed> $params */
        public function fetchAll(string $sql, array $params = []): array
        {
            $normalized = $this->normalize($sql);
            if (str_contains($normalized, 'FROM service_endpoints e INNER JOIN nodes n ON n.id = e.node_id')) {
                $rows = [];
                foreach ($this->tables['service_endpoints'] ?? [] as $endpoint) {
                    if ((int) ($endpoint['forward_id'] ?? 0) !== (int) ($params['forward_id'] ?? 0)) {
                        continue;
                    }
                    $node = $this->findById('nodes', (int) ($endpoint['node_id'] ?? 0));
                    if ($node !== null) {
                        $rows[] = array_merge($endpoint, [
                            'node_name' => (string) ($node['name'] ?? ''),
                            'node_public_ip' => (string) ($node['public_ip'] ?? ''),
                            'node_agent_config_json' => $node['agent_config_json'] ?? null,
                            'node_agent_version' => $node['agent_version'] ?? null,
                        ]);
                    }
                }
                return $rows;
            }
            if (str_contains($normalized, 'node_leigod_line_health')) {
                return ($params['bond_client_mode'] ?? null) === 'bond'
                    ? $this->availabilityRows
                    : [];
            }
            return [];
        }

        public function execute(string $sql, array $params = []): int { return 0; }
        public function insert(string $table, array $row): int { return 1; }

        private function findById(string $table, int $id): ?array
        {
            foreach ($this->tables[$table] ?? [] as $row) {
                if ((int) ($row['id'] ?? 0) === $id) {
                    return $row;
                }
            }
            return null;
        }

        private function normalize(string $sql): string
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
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
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

    function assertSame(mixed $expected, mixed $actual, string $message): void
    {
        if ($actual !== $expected) {
            fail($message . ': got ' . var_export($actual, true) . ', expected ' . var_export($expected, true));
        }
    }

    function call_private(object $instance, string $method, mixed ...$args): mixed
    {
        $reflection = new ReflectionMethod($instance, $method);
        $reflection->setAccessible(true);
        return $reflection->invoke($instance, ...$args);
    }

    $lineIds = [447, 451, 453, 455, 456];
    $envPath = tempnam(sys_get_temp_dir(), 'lw-fixed-bond-env-');
    if ($envPath === false) {
        fail('failed to create temp env file');
    }
    $env = "DEFAULT_TLS_CERT_REF=default-edge-cert\nSIDECAR_DIRECT_TCP_POINT_IDS=44\n";
    foreach ($lineIds as $lineId) {
        $env .= 'LEIGOD_LINE_TOML_' . $lineId . '=' . base64_encode("line = {$lineId}\n") . "\n";
    }
    file_put_contents($envPath, $env);

    $db = new Database();
    $db->seed('services', [[
        'id' => 2,
        'client_token' => 'service-token',
        'status' => 'active',
        'name' => 'Fixed Endpoint Bond',
        'bandwidth_limit_mbps' => 0,
    ]]);
    $db->seed('sidecar_points', [[
        'id' => 44,
        'name' => 'Tencent HK Sidecar',
        'sidecar_host' => '198.51.100.25',
        'listen_port' => 18500,
        'bond_listen_port' => 18501,
        'mptcp_listen_port' => 18502,
        'cf_listen_port' => 443,
        'token_secret' => 'sidecar-token',
        'agent_config_json' => json_encode(['fixed_endpoint_leigod_bond' => true]),
    ]]);
    $db->seed('forward_combinations', [[
        'id' => 9,
        'name' => 'Shanghai to HK Bond',
        'tenant_label' => 'LeiGod Bond',
        'runtime_preference' => 'single',
    ]]);
    $db->seed('node_groups', [[
        'id' => 1,
        'group_key' => 'sha-tencent',
        'name' => 'Shanghai Tencent',
        'ingress_cdn_host' => 'edge.example.com',
        'ingress_sni_host' => 'edge.example.com',
    ]]);
    $db->seed('nodes', [
        [
            'id' => 15,
            'name' => 'sha-node-15',
            'public_ip' => '203.0.113.15',
            'agent_config_json' => null,
            'agent_version' => '2026.08.04.2',
        ],
        [
            'id' => 16,
            'name' => 'stale-node-16',
            'public_ip' => '203.0.113.16',
            'agent_config_json' => null,
            'agent_version' => '2026.08.04.2',
        ],
    ]);
    $forward = [
        'id' => 47,
        'service_id' => 2,
        'listen_port' => 18080,
        'target_host' => '192.0.2.80',
        'target_port' => 80,
        'bond_remote_host' => '198.51.100.25',
        'bond_remote_port' => 18501,
        'bond_ingress_mode' => 'plain_tcp_mux',
        'bond_sidecar_point_id' => 44,
        'forward_combination_id' => 9,
        'forward_route_id' => null,
        'cf_location_group_id' => null,
        'line_client_id' => null,
        'line_client_group_id' => null,
        'line_group_mode' => 'selected',
        'line_client_group_ids_json' => null,
        'endpoint_mode' => 'slave_group',
        'forward_chain_id' => null,
        'transport_mode' => 'tcp+udp',
        'client_mode' => 'bond',
        'backend_mode' => 'leigod_mode',
        'sni_host' => 'edge.example.com',
        'path_prefix' => 'fixed-bond-route',
        'node_group_key' => 'sha-tencent',
        'cdn_host' => 'edge.example.com',
        'filter_profile' => 'off',
        'public_port' => 7003,
        'custom_endpoint_ids_json' => json_encode(['specific_node_id' => 15]),
    ];
    $db->seed('forwards', [$forward]);
    $db->seed('service_endpoints', [
        [
            'id' => 316,
            'forward_id' => 47,
            'node_id' => 15,
            'public_host' => '203.0.113.15',
            'public_ip' => '203.0.113.15',
            'public_port' => 7003,
            'cdn_host' => 'edge.example.com',
            'priority' => 10,
            'weight' => 100,
            'health_status' => 'healthy',
        ],
        [
            'id' => 317,
            'forward_id' => 47,
            'node_id' => 16,
            'public_host' => '203.0.113.16',
            'public_ip' => '203.0.113.16',
            'public_port' => 7004,
            'cdn_host' => 'edge.example.com',
            'priority' => 20,
            'weight' => 100,
            'health_status' => 'healthy',
        ],
    ]);
    $db->availabilityRows = array_map(static function (int $lineId): array {
        $hash = hash('sha256', "line = {$lineId}\n");
        return [
            'route_id' => 0,
            'node_id' => 15,
            'node_name' => 'sha-node-15',
            'node_public_ip' => '203.0.113.15',
            'node_status' => 'active',
            'combination_id' => 9,
            'combination_sort_order' => 10,
            'membership_sort_order' => $lineId,
            'line_id' => $lineId,
            'line_is_active' => 1,
            'config_sha256' => $hash,
            'health_config_sha256' => $hash,
            'service_state' => 'active',
            'tcp_status' => 'healthy',
            'tcp_connect_ms' => 10,
            'udp_status' => 'healthy',
            'checked_at' => gmdate('Y-m-d H:i:s'),
        ];
    }, $lineIds);

    $service = new ProvisioningService(new Config($envPath), $db);
    $config = $service->buildClientConfig(47);

    assertSame(1, count($config['endpoints'] ?? []), 'one physical endpoint');
    assertSame(true, $config['bonding']['fixed_endpoint'] ?? null, 'fixed endpoint flag');
    assertSame($lineIds, array_column($config['bonding']['fixed_endpoint_legs'] ?? [], 'leg_route_id'), 'logical leg routes');
    assertSame(5, $config['bonding']['preferred_initial_legs'] ?? null, 'preferred legs');
    assertSame(2, $config['bonding']['minimum_initial_legs'] ?? null, 'minimum legs');
    assertSame(false, $config['bonding']['quic_enabled'] ?? null, 'fixed bond disables quic');
    assertSame('127.0.0.1:48316', $config['endpoints'][0]['runtime_target'] ?? null, 'fixed endpoint runtime target');

    $publishForward = array_merge($forward, [
        'client_token' => 'service-token',
        'forward_combination_runtime_preference' => 'single',
        'bond_sidecar_host' => '198.51.100.25',
        'bond_sidecar_port' => 18501,
        'bond_sidecar_point_token_secret' => 'sidecar-token',
        'bond_sidecar_point_agent_config_json' => json_encode(['fixed_endpoint_leigod_bond' => true]),
    ]);
    $node = ['id' => 15, 'node_group_key' => 'sha-tencent', 'agent_config_json' => null];
    $payload = call_private($service, 'publishTaskPayloadForForward', $publishForward, $node, 316);

    assertSame('127.0.0.1:48316', $payload['target'] ?? null, 'slave loopback target');
    assertSame('bond', $payload['leigod_forward']['mode'] ?? null, 'relay mode');
    assertSame('198.51.100.25:18501', $payload['leigod_forward']['target'] ?? null, 'real target');
    assertSame($lineIds, array_column($payload['leigod_forward']['candidates'] ?? [], 'leg_route_id'), 'relay leg routes');
    assertSame([], $payload['sidecar_uplink'] ?? null, 'no competing static uplink');
    assertSame([], $payload['sidecar_uplink_selector'] ?? null, 'no competing static selector');

    echo "ok\n";
}
