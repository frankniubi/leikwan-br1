<?php
declare(strict_types=1);

// Dedicated LeiGod forwards are sidecar-less app contracts. Stale sidecar/bond remote columns must not
// leak into either the client pull config or the slave publish task; the target is the selected forward
// node target, and aggregation-only payload sections stay empty.

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
            $n = $this->normalize($sql);
            if (str_contains($n, 'SELECT f.*, s.client_token, s.status AS service_status, s.name AS service_name,')) {
                $forward = $this->findById('forwards', (int) ($params['id'] ?? 0));
                if ($forward === null) {
                    return null;
                }
                $service = $this->findById('services', (int) ($forward['service_id'] ?? 0)) ?? [];
                $combo = $this->findById('forward_combinations', (int) ($forward['forward_combination_id'] ?? 0)) ?? [];
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
                    'bond_sidecar_point_name' => 'stale-sidecar',
                    'forward_combination_name' => (string) ($combo['name'] ?? ''),
                    'forward_combination_label' => (string) ($combo['tenant_label'] ?? ''),
                    'forward_combination_runtime_preference' => (string) ($combo['runtime_preference'] ?? 'single'),
                    'forward_combination_allow_private_self_leg' => (int) ($combo['allow_private_self_leg'] ?? 0),
                    'forward_combination_self_leg_scope' => (string) ($combo['self_leg_scope'] ?? 'none'),
                    'forward_combination_self_leg_role' => (string) ($combo['self_leg_role'] ?? 'none'),
                    'forward_combination_helper_group_cap' => (int) ($combo['helper_group_cap'] ?? 0),
                    'forward_combination_helper_escalation_policy' => (string) ($combo['helper_escalation_policy'] ?? 'disabled'),
                    'forward_combination_self_leg_cf_transport' => (string) ($combo['self_leg_cf_transport'] ?? 'cf_direct'),
                    'forward_combination_desired_latency_ms' => (int) ($combo['desired_latency_ms'] ?? 0),
                    'forward_combination_allow_public_self_leg_donation' => (int) ($combo['allow_public_self_leg_donation'] ?? 0),
                    'forward_combination_donation_qualify_window_seconds' => (int) ($combo['donation_qualify_window_seconds'] ?? 60),
                    'forward_combination_donation_revoke_window_seconds' => (int) ($combo['donation_revoke_window_seconds'] ?? 30),
                    'forward_combination_donation_min_open_ports' => (int) ($combo['donation_min_open_ports'] ?? 1),
                    'bond_sidecar_host' => 'stale-sidecar.invalid',
                    'bond_sidecar_legacy_port' => 9443,
                    'bond_sidecar_port' => 9443,
                    'mptcp_sidecar_port' => 9444,
                    'bond_sidecar_cf_listen_port' => 443,
                    'bond_sidecar_point_token_secret' => 'stale-token',
                    'bond_sidecar_point_agent_config_json' => json_encode([
                        'cf_sni_host' => 'stale-cf.invalid',
                        'approved_upstream_realizations' => [[
                            'dial_host' => '203.0.113.44',
                            'dial_port' => 443,
                            'cf_sni_host' => 'stale-cf.invalid',
                        ]],
                    ], JSON_UNESCAPED_SLASHES),
                ]);
            }
            if (str_contains($n, 'SELECT * FROM node_groups WHERE group_key = :group_key LIMIT 1')) {
                foreach ($this->tables['node_groups'] ?? [] as $row) {
                    if ((string) ($row['group_key'] ?? '') === (string) ($params['group_key'] ?? '')) {
                        return $row;
                    }
                }
                return null;
            }
            return null;
        }

        /** @param array<string, mixed> $params */
        public function fetchAll(string $sql, array $params = []): array
        {
            $n = $this->normalize($sql);
            if (str_contains($n, 'FROM service_endpoints e INNER JOIN nodes n ON n.id = e.node_id')) {
                $rows = [];
                foreach ($this->tables['service_endpoints'] ?? [] as $endpoint) {
                    if ((int) ($endpoint['forward_id'] ?? 0) !== (int) ($params['forward_id'] ?? 0)) {
                        continue;
                    }
                    if (in_array((string) ($endpoint['health_status'] ?? ''), [(string) ($params['deleted'] ?? 'deleted'), (string) ($params['deleting'] ?? 'deleting')], true)) {
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
                        'node_agent_version' => $node['agent_version'] ?? null,
                    ]);
                }
                return $rows;
            }
            if (str_contains($n, 'FROM forward_combination_helper_groups')) {
                return [];
            }
            if (str_contains($n, 'FROM forward_combinations fc') && str_contains($n, 'node_leigod_line_health')) {
                return $this->availabilityRows;
            }
            return [];
        }

        /** @param array<string, mixed> $params */
        public function execute(string $sql, array $params = []): int { return 0; }

        /** @param array<string, mixed> $row */
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
        $ref = new ReflectionMethod($instance, $method);
        $ref->setAccessible(true);
        return $ref->invoke($instance, ...$args);
    }

    $envPath = tempnam(sys_get_temp_dir(), 'lw-leigod-dedicated-contract-env-');
    if ($envPath === false) {
        fail('failed to create temp env file');
    }
    $toml451 = "line = 451\n";
    $hash451 = hash('sha256', $toml451);
    file_put_contents($envPath, "DEFAULT_TLS_CERT_REF=default-edge-cert\nBOND_AGGREGATOR_HOST=legacy-sidecar.invalid\nBOND_AGGREGATOR_PORT=9999\nLEIGOD_LINE_TOML_451=" . base64_encode($toml451) . "\n");

    $db = new Database();
    $db->seed('services', [[
        'id' => 2,
        'client_token' => 'service-token',
        'status' => 'active',
        'name' => 'Dedicated LeiGod',
        'bandwidth_limit_mbps' => 0,
    ]]);
    $db->seed('forward_combinations', [[
        'id' => 9,
        'name' => 'Shanghai to Japan',
        'tenant_label' => 'LeiGod JP',
        'runtime_preference' => 'single',
        'helper_group_cap' => 0,
    ]]);
    $db->seed('node_groups', [[
        'id' => 1,
        'group_key' => 'sha-tencent',
        'name' => 'Shanghai Tencent',
        'ingress_cdn_host' => 'edge.example.com',
        'ingress_sni_host' => 'edge.example.com',
    ]]);
    $db->seed('nodes', [[
        'id' => 12,
        'name' => 'sha-node-12',
        'public_ip' => '203.0.113.12',
        'agent_config_json' => null,
        'agent_version' => '2026.07.22.1',
    ]]);
    $db->seed('forwards', [[
        'id' => 47,
        'service_id' => 2,
        'listen_port' => 18080,
        'target_host' => '162.245.221.78',
        'target_port' => 80,
        'bond_remote_host' => 'stale-sidecar.invalid',
        'bond_remote_port' => 9443,
        'bond_ingress_mode' => 'plain_tcp_mux',
        'bond_sidecar_point_id' => 44,
        'forward_combination_id' => 9,
        'cf_location_group_id' => null,
        'line_client_id' => null,
        'line_client_group_id' => null,
        'line_group_mode' => 'selected',
        'line_client_group_ids_json' => null,
        'endpoint_mode' => 'slave_group',
        'forward_chain_id' => null,
        'transport_mode' => 'tcp+udp',
        'client_mode' => 'single',
        'backend_mode' => 'leigod_mode',
        'sni_host' => 'edge.example.com',
        'path_prefix' => 'dedicated-route',
        'node_group_key' => 'sha-tencent',
        'cdn_host' => 'edge.example.com',
        'filter_profile' => 'off',
    ]]);
    $db->seed('service_endpoints', [[
        'id' => 316,
        'forward_id' => 47,
        'node_id' => 12,
        'public_host' => '203.0.113.12',
        'public_ip' => '203.0.113.12',
        'public_port' => 443,
        'cdn_host' => 'edge.example.com',
        'priority' => 10,
        'weight' => 100,
        'health_status' => 'healthy',
    ]]);
    $db->availabilityRows = [[
        'route_id' => 0,
        'node_id' => 12,
        'node_name' => 'sha-node-12',
        'node_public_ip' => '203.0.113.12',
        'node_status' => 'active',
        'combination_id' => 9,
        'combination_sort_order' => 10,
        'membership_sort_order' => 10,
        'line_id' => 451,
        'line_is_active' => 1,
        'config_sha256' => $hash451,
        'health_config_sha256' => $hash451,
        'service_state' => 'active',
        'tcp_status' => 'healthy',
        'tcp_connect_ms' => 12,
        'udp_status' => 'healthy',
        'checked_at' => gmdate('Y-m-d H:i:s'),
    ]];

    $service = new ProvisioningService(new Config($envPath), $db);
    $config = $service->buildClientConfig(47);

    assertSame(1, $config['contract_version'] ?? null, 'client config must publish contract_version=1');
    assertSame('leigod_mode', $config['backend_mode'] ?? null, 'client config backend_mode');
    assertSame('leigod_only', $config['active_family'] ?? null, 'client config active_family');
    assertSame(false, $config['aggregation_enabled'] ?? null, 'dedicated config must not enable aggregation');
    assertSame('dedicated_forward_node', $config['target_contract'] ?? null, 'dedicated config target_contract');
    assertSame(false, $config['sidecar_required'] ?? null, 'dedicated config sidecar_required');
    assertSame('162.245.221.78:80', $config['target'] ?? null, 'dedicated config target');
    assertSame('127.0.0.1:48316', $config['endpoints'][0]['runtime_target'] ?? null, 'dedicated endpoint runtime target');
    assertSame(null, array_key_exists('bond_sidecar_point_id', $config) ? $config['bond_sidecar_point_id'] : 'MISSING', 'dedicated config sidecar id');
    assertSame('', $config['bond_sidecar_point_name'] ?? null, 'dedicated config must blank sidecar name');
    assertSame('', $config['bond_sidecar_host'] ?? null, 'dedicated config must blank sidecar host');
    assertSame(null, array_key_exists('bond_sidecar_port', $config) ? $config['bond_sidecar_port'] : 'MISSING', 'dedicated config must blank bond sidecar port');
    assertSame(null, array_key_exists('mptcp_sidecar_port', $config) ? $config['mptcp_sidecar_port'] : 'MISSING', 'dedicated config must blank mptcp sidecar port');
    assertSame(null, array_key_exists('bond_sidecar_cf_listen_port', $config) ? $config['bond_sidecar_cf_listen_port'] : 'MISSING', 'dedicated config must blank sidecar CF listen port');
    assertSame('', $config['bond_sidecar_cf_sni_host'] ?? null, 'dedicated config must blank sidecar CF SNI');
    assertSame([], $config['bond_sidecar_approved_upstream_realizations'] ?? null, 'dedicated config must blank sidecar CF upstream realizations');
    assertSame('', $config['bond_sidecar_point_agent_config_json'] ?? null, 'dedicated config must blank sidecar agent config');
    assertSame('', $config['bond_remote_host'] ?? null, 'dedicated config must ignore stale/env remote host');
    assertSame(null, array_key_exists('bond_remote_port', $config) ? $config['bond_remote_port'] : 'MISSING', 'dedicated config must ignore stale/env remote port');
    assertSame(null, array_key_exists('bonding', $config) ? $config['bonding'] : 'MISSING', 'dedicated config must not publish bonding');
    assertSame(null, array_key_exists('mptcp', $config) ? $config['mptcp'] : 'MISSING', 'dedicated config must not publish mptcp');

    $forward = [
        'id' => 47,
        'forward_combination_id' => 9,
        'client_mode' => 'single',
        'forward_combination_runtime_preference' => 'single',
        'bond_ingress_mode' => 'plain_tcp_mux',
        'backend_mode' => 'leigod_mode',
        'public_port' => 443,
        'path_prefix' => 'dedicated-route',
        'sni_host' => 'edge.example.com',
        'cdn_host' => 'edge.example.com',
        'filter_profile' => 'off',
        'transport_mode' => 'tcp+udp',
        'bond_remote_host' => 'stale-sidecar.invalid',
        'bond_remote_port' => 9443,
        'bond_sidecar_point_id' => null,
        'target_host' => '162.245.221.78',
        'target_port' => 80,
    ];
    $node = ['id' => 12, 'node_group_key' => 'sha-tencent', 'agent_config_json' => null];
    $payload = call_private($service, 'publishTaskPayloadForForward', $forward, $node, 316);

    assertSame('127.0.0.1:48316', $payload['target'] ?? null, 'dedicated publish target must be relay loopback');
    assertSame('162.245.221.78:80', $payload['leigod_forward']['target'] ?? null, 'dedicated relay preserves real target');
    assertSame([451], array_column($payload['leigod_forward']['candidates'] ?? [], 'line_id'), 'dedicated relay candidate');
    assertSame('leigod_mode', $payload['backend_mode'] ?? null, 'publish backend_mode');
    assertSame('single', $payload['client_mode'] ?? null, 'publish client_mode');
    assertSame('single', $payload['runtime_preference'] ?? null, 'publish runtime_preference');
    assertSame('leigod_only', $payload['active_family'] ?? null, 'publish active_family');
    assertSame(false, $payload['aggregation_enabled'] ?? null, 'publish aggregation_enabled');
    assertSame('dedicated_forward_node', $payload['target_contract'] ?? null, 'publish target_contract');
    assertSame(false, $payload['sidecar_required'] ?? null, 'publish sidecar_required');
    assertSame(false, $payload['publish_bond_runtime'] ?? null, 'publish_bond_runtime');
    assertSame([], $payload['sidecar_uplink'] ?? null, 'dedicated publish sidecar_uplink');
    assertSame([], $payload['sidecar_uplink_selector'] ?? null, 'dedicated publish sidecar_uplink_selector');
    assertSame(null, array_key_exists('cf_mode', $payload) ? $payload['cf_mode'] : 'MISSING', 'dedicated publish cf_mode');

    $ultraForward = $forward;
    $ultraForward['backend_mode'] = 'ultra';
    $ultraForward['client_mode'] = 'bond';
    $ultraForward['forward_combination_runtime_preference'] = 'mptcp_preferred_bond_fallback';
    $ultraPayload = call_private($service, 'publishTaskPayloadForForward', $ultraForward, $node, 316);
    assertSame('ultra', $ultraPayload['backend_mode'] ?? null, 'ultra publish backend_mode');
    assertSame('ultra', $ultraPayload['active_family'] ?? null, 'ultra publish active_family');
    assertSame('ultra', $ultraPayload['target_contract'] ?? null, 'ultra publish target_contract');
    assertSame(false, $ultraPayload['aggregation_enabled'] ?? null, 'ultra must suppress stale aggregation settings');
    assertSame(false, $ultraPayload['sidecar_required'] ?? null, 'ultra publish sidecar_required');
    assertSame(false, array_key_exists('leigod_forward', $ultraPayload), 'ultra publish must not emit legacy implementation metadata');
    assertSame('', $ultraPayload['sni_host'] ?? null, 'ultra publish must not emit SNI');
    assertSame('', $ultraPayload['cdn_host'] ?? null, 'ultra publish must not emit CDN host');
    assertSame('ultra_binary', $ultraPayload['backend_protocol'] ?? null, 'ultra publish protocol');
    assertSame('ultra_binary', $ultraPayload['ingress_protocol'] ?? null, 'ultra ingress protocol');
    assertSame('162.245.221.78:80', $ultraPayload['target'] ?? null, 'ultra publish target');

    echo "ok\n";
}
