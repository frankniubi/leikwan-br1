<?php
declare(strict_types=1);

// Regression: the slave-side sidecar-uplink lane selector binds 127.0.0.1:<selector_port>
// and runs as one systemd unit per (service_id, endpoint_id). The port MUST be unique per
// published selector service on a node. The original formula keyed selector_port only on
// (sidecar_point_id, forward_combination_id, node_id), so every forward/endpoint sharing a
// lane on the same node collided on one port and the losing selector units crash-looped with
// EADDRINUSE. This test pins that two endpoints sharing the same (point, combination, node)
// receive DISTINCT, deterministic, in-range selector_port values.

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<string, array<int, array<string, mixed>>> */
        private array $tables = [];

        /** @param array<int, array<string, mixed>> $rows */
        public function seed(string $table, array $rows): void
        {
            $this->tables[$table] = array_values($rows);
        }

        /** @param array<string, mixed> $params */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            $normalized = $this->normalizeSql($sql);
            if (str_contains($normalized, 'FROM forward_combinations fc')) {
                foreach ($this->tables['forward_combinations'] ?? [] as $combo) {
                    if ((int) ($combo['id'] ?? 0) === (int) ($params['id'] ?? $params['forward_combination_id'] ?? 0)) {
                        return $this->withSidecar($combo);
                    }
                }
                return null;
            }
            if (str_contains($normalized, 'FROM sidecar_points')) {
                return $this->findById('sidecar_points', (int) ($params['id'] ?? 0));
            }
            if (str_contains($normalized, 'FROM cf_location_groups')) {
                return $this->findById('cf_location_groups', (int) ($params['id'] ?? 0));
            }
            if (str_contains($normalized, 'FROM nodes')) {
                return $this->findById('nodes', (int) ($params['id'] ?? $params['node_id'] ?? 0));
            }
            if (str_contains($normalized, 'SELECT client_token FROM services WHERE id = :id LIMIT 1')) {
                $row = $this->findById('services', (int) ($params['id'] ?? 0));
                return $row === null ? null : ['client_token' => $row['client_token'] ?? ''];
            }
            if (str_contains($normalized, 'SELECT agent_config_json FROM clients WHERE id = :id LIMIT 1')) {
                $row = $this->findById('clients', (int) ($params['id'] ?? 0));
                return $row === null ? null : ['agent_config_json' => $row['agent_config_json'] ?? null];
            }
            return null;
        }

        /** @param array<string, mixed> $params */
        public function fetchAll(string $sql, array $params = []): array
        {
            $normalized = $this->normalizeSql($sql);
            if (str_contains($normalized, 'FROM forward_combination_slaves fcs')) {
                $comboId = (int) ($params['forward_combination_id'] ?? 0);
                $nodeFilter = (int) ($params['node_id'] ?? 0);
                $rows = [];
                foreach ($this->tables['forward_combination_slaves'] ?? [] as $pin) {
                    if ($comboId > 0 && (int) ($pin['forward_combination_id'] ?? 0) !== $comboId) {
                        continue;
                    }
                    if ($nodeFilter > 0 && (int) ($pin['node_id'] ?? 0) !== $nodeFilter) {
                        continue;
                    }
                    if ((int) ($pin['is_active'] ?? 0) !== 1) {
                        continue;
                    }
                    $node = $this->findById('nodes', (int) ($pin['node_id'] ?? 0));
                    if ($node === null) {
                        continue;
                    }
                    if (str_contains($normalized, 'INNER JOIN forward_combinations fc')) {
                        $combo = $this->findById('forward_combinations', (int) ($pin['forward_combination_id'] ?? 0));
                        if ($combo !== null) {
                            $rows[] = $this->withSidecar($combo);
                        }
                        continue;
                    }
                    $group = $this->findById('node_groups', (int) ($node['group_id'] ?? 0));
                    $rows[] = array_merge($node, [
                        'node_group_key' => (string) ($group['group_key'] ?? ''),
                        'pin_sort_order' => (int) ($pin['sort_order'] ?? 0),
                        'forward_combination_id' => (int) ($pin['forward_combination_id'] ?? 0),
                    ]);
                }
                return $rows;
            }
            if (str_contains($normalized, 'FROM forward_combination_helper_groups')) {
                return [];
            }
            if (str_contains($normalized, 'FROM cf_location_group_cidrs')) {
                return $this->tables['cf_location_group_cidrs'] ?? [];
            }
            return [];
        }

        private function withSidecar(array $combo): array
        {
            $point = $this->findById('sidecar_points', (int) ($combo['sidecar_point_id'] ?? 0)) ?? [];
            return array_merge($combo, [
                'sidecar_point_name' => (string) ($point['name'] ?? ''),
                'sidecar_host' => (string) ($point['sidecar_host'] ?? ''),
                'sidecar_agent_config_json' => $point['agent_config_json'] ?? null,
                'sidecar_listen_port' => (int) ($point['listen_port'] ?? 0),
                'sidecar_bond_listen_port' => (int) ($point['bond_listen_port'] ?? 0),
                'sidecar_mptcp_listen_port' => (int) ($point['mptcp_listen_port'] ?? 0),
                'sidecar_cf_listen_port' => (int) ($point['cf_listen_port'] ?? 0),
            ]);
        }

        private function findById(string $table, int $id): ?array
        {
            foreach ($this->tables[$table] ?? [] as $row) {
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

    function selector_port_for(ProvisioningService $service, Database $db, int $forwardId, int $endpointId): int
    {
        $forward = [
            'id' => $forwardId,
            'service_id' => 6,
            'client_token' => 'service-token',
            'line_client_id' => 3,
            'client_mode' => 'bond',
            'forward_combination_id' => 2,
            'bond_ingress_mode' => 'plain_tcp_mux',
            'backend_mode' => 'current_mode',
            'public_port' => 7000,
            'path_prefix' => 'f' . $forwardId,
            'sni_host' => '',
            'cdn_host' => '',
            'filter_profile' => 'off',
            'transport_mode' => 'tcp+udp',
            'bond_sidecar_point_id' => 4,
            'bond_remote_host' => '138.252.162.120',
            'bond_remote_port' => 9000,
            'target_host' => '138.252.162.120',
            'target_port' => 9000,
        ];
        $node = $db->fetchOne('SELECT * FROM nodes WHERE id = :id LIMIT 1', ['id' => 19]);
        $payload = call_private($service, 'publishTaskPayloadForForward', $forward, $node, $endpointId);
        $selector = $payload['sidecar_uplink_selector'] ?? null;
        if (!is_array($selector) || !($selector['enabled'] ?? false)) {
            fail("forward $forwardId/endpoint $endpointId did not produce an enabled sidecar_uplink_selector");
        }
        return (int) ($selector['selector_port'] ?? 0);
    }

    $envPath = tempnam(sys_get_temp_dir(), 'lw-selector-port-env-');
    if ($envPath === false) {
        fail('failed to create temp env');
    }
    file_put_contents($envPath, "DEFAULT_TLS_CERT_REF=default-edge-cert\nBOND_TOKEN_SECRET=test-token-secret\nMPTCP_SLAVE_PRECONNECT=true\n");

    $db = new Database();
    $db->seed('services', [['id' => 6, 'status' => 'active', 'client_token' => 'service-token']]);
    $db->seed('clients', [['id' => 3, 'agent_config_json' => json_encode(['wireguard_public_key' => 'client-pub'], JSON_UNESCAPED_SLASHES)]]);
    $db->seed('sidecar_points', [[
        'id' => 4,
        'name' => 'szhk-ixp1',
        'sidecar_host' => '138.252.162.120',
        'listen_port' => 9000,
        'bond_listen_port' => 9000,
        'mptcp_listen_port' => 14969,
        'cf_listen_port' => 2053,
        'agent_config_json' => json_encode([
            'wireguard_public_key' => 'sidecar-pub',
            'wireguard_listen_port' => 14969,
            'wireguard_port_range_end' => 14999,
            'cf_sni_host' => 'hk.example.com',
        ], JSON_UNESCAPED_SLASHES),
    ]]);
    $db->seed('node_groups', [['id' => 10, 'group_key' => 'sha-tencent']]);
    $db->seed('nodes', [[
        'id' => 19,
        'group_id' => 10,
        'name' => 'sha-tencent-19',
        'status' => 'active',
        'public_ip' => '111.229.217.7',
        'agent_version' => '2026.05.28.1',
        'agent_config_json' => json_encode([
            'wireguard_public_key' => 'slave19-pub',
            'wireguard_listen_port' => 7000,
        ], JSON_UNESCAPED_SLASHES),
    ]]);
    $db->seed('forward_combinations', [[
        'id' => 2,
        'name' => 'SH-HK',
        'tenant_label' => 'SH-HK',
        'node_group_key' => 'sha-tencent',
        'backend_mode' => 'current_mode',
        'sidecar_point_id' => 4,
        'client_mode' => 'bond',
        'runtime_preference' => 'single',
        'helper_group_cap' => 2,
        'is_active' => 1,
        'sort_order' => 10,
    ]]);
    $db->seed('forward_combination_slaves', [[
        'id' => 1,
        'forward_combination_id' => 2,
        'node_id' => 19,
        'selection_kind' => 'admin_pin',
        'sort_order' => 10,
        'is_active' => 1,
    ]]);

    $service = new ProvisioningService(new Config($envPath), $db);

    // Two distinct forwards/endpoints sharing the SAME (sidecar_point_id=4, forward_combination_id=2, node_id=19).
    $portA = selector_port_for($service, $db, 59, 571);
    $portB = selector_port_for($service, $db, 60, 572);

    foreach (['59/571' => $portA, '60/572' => $portB] as $label => $port) {
        if ($port < 45000 || $port > 49999) {
            fail("selector_port for $label out of expected 45000-49999 range: $port");
        }
    }
    if ($portA === $portB) {
        fail("REGRESSION: two endpoints sharing (point,combination,node) collided on selector_port $portA — the EADDRINUSE crash-loop bug");
    }

    // Same forward, different endpoint id -> must also differ (selector unit is per endpoint_id).
    $portAOtherEndpoint = selector_port_for($service, $db, 59, 573);
    if ($portAOtherEndpoint === $portA) {
        fail("selector_port did not vary with endpoint_id (571 vs 573 collided on $portA)");
    }

    // Determinism / idempotency: same inputs reproduce the same port (republish must be stable).
    $portARepeat = selector_port_for($service, $db, 59, 571);
    if ($portARepeat !== $portA) {
        fail("selector_port not deterministic for forward 59/endpoint 571: $portA then $portARepeat");
    }

    echo "ok\n";
}
