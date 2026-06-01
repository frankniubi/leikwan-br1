<?php
declare(strict_types=1);

// Behavioral regression (FIX B — collision-free selector ports):
// The slave-side sidecar-uplink lane selector binds 127.0.0.1:<selector_port> and runs as one
// systemd unit per (service_id, endpoint_id). The original stableSidecarSelectorPort was a naive
// hash 45000 + (point*1009 + combo*131 + node*17 + forward*257 + endpoint*65537) % 5000 with NO
// collision check. Two DIFFERENT (forward, endpoint) selectors on the SAME node can hash to the
// SAME port -> the agent crashes on EADDRINUSE -> systemd restart storm (14,972 restarts/30min).
//
// The fix: a DETERMINISTIC, per-node COLLISION-FREE allocation. For a given node, enumerate ALL
// selector-bearing endpoints in stable order (endpoint_id ASC) and assign each a port via
// probeFreeSlotInSubrange against the growing assigned-set, using the SAME hash bucket seed as
// before. Repeated calls with the same node endpoint-set reproduce the same port per endpoint.
//
// This test seeds two endpoints on node 19 that COLLIDE under the OLD naive formula
// (forward 50/endpoint 500 and forward 89/endpoint 621 both hashed to 45971) and asserts the
// resolved selector ports are DISTINCT, in-range, and STABLE across repeated calls.

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<string, array<int, array<string, mixed>>> */
        private array $tables = [];

        public function seed(string $table, array $rows): void
        {
            $this->tables[$table] = array_values($rows);
        }

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

        public function fetchAll(string $sql, array $params = []): array
        {
            $normalized = $this->normalizeSql($sql);
            // FIX B: per-node selector-bearing endpoint enumeration.
            if (str_contains($normalized, 'FROM service_endpoints') && str_contains($normalized, 'node_id = :node_id')) {
                $nodeId = (int) ($params['node_id'] ?? 0);
                $rows = [];
                foreach ($this->tables['service_endpoints'] ?? [] as $e) {
                    if ((int) ($e['node_id'] ?? 0) !== $nodeId) {
                        continue;
                    }
                    if (in_array((string) ($e['health_status'] ?? ''), ['deleted', 'deleting'], true)) {
                        continue;
                    }
                    $forward = $this->findById('forwards', (int) ($e['forward_id'] ?? 0));
                    $combinationId = (int) ($forward['forward_combination_id'] ?? 0);
                    if ($combinationId <= 0) {
                        continue; // only forward_combination-backed endpoints are selector-bearing
                    }
                    $rows[] = array_merge($e, [
                        'endpoint_id' => (int) ($e['id'] ?? 0),
                        'forward_id' => (int) ($e['forward_id'] ?? 0),
                        'forward_combination_id' => $combinationId,
                    ]);
                }
                usort($rows, static fn(array $l, array $r): int => ((int) $l['endpoint_id']) <=> ((int) $r['endpoint_id']));
                return $rows;
            }
            if (str_contains($normalized, 'FROM forward_combination_slaves fcs')) {
                $comboId = (int) ($params['forward_combination_id'] ?? 0);
                $rows = [];
                foreach ($this->tables['forward_combination_slaves'] ?? [] as $pin) {
                    if ($comboId > 0 && (int) ($pin['forward_combination_id'] ?? 0) !== $comboId) {
                        continue;
                    }
                    if ((int) ($pin['is_active'] ?? 0) !== 1) {
                        continue;
                    }
                    $node = $this->findById('nodes', (int) ($pin['node_id'] ?? 0));
                    if ($node === null) {
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

    $envPath = tempnam(sys_get_temp_dir(), 'lw-selector-collisionfree-env-');
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
    // Two forwards on combination 2, both pinned to node 19.
    $db->seed('forwards', [
        ['id' => 50, 'forward_combination_id' => 2],
        ['id' => 89, 'forward_combination_id' => 2],
    ]);
    // Two selector-bearing endpoints on node 19 that COLLIDE under the OLD naive formula:
    //   forward 50 / endpoint 500 -> 45971  and  forward 89 / endpoint 621 -> 45971.
    $db->seed('service_endpoints', [
        ['id' => 500, 'forward_id' => 50, 'node_id' => 19, 'health_status' => 'active'],
        ['id' => 621, 'forward_id' => 89, 'node_id' => 19, 'health_status' => 'pending'],
    ]);

    $service = new ProvisioningService(new Config($envPath), $db);

    // Sanity: confirm these two genuinely collide under the OLD naive formula (so the test is meaningful).
    $naive = static function (int $point, int $combo, int $node, int $fwd, int $ep): int {
        return 45000 + abs((($point * 1009) + ($combo * 131) + ($node * 17) + ($fwd * 257) + ($ep * 65537)) % 5000);
    };
    if ($naive(4, 2, 19, 50, 500) !== $naive(4, 2, 19, 89, 621)) {
        fail('test fixture invalid: chosen (forward,endpoint) pairs no longer collide under the naive formula');
    }

    $portA = selector_port_for($service, $db, 50, 500);
    $portB = selector_port_for($service, $db, 89, 621);

    foreach (['50/500' => $portA, '89/621' => $portB] as $label => $port) {
        if ($port < 45000 || $port > 49999) {
            fail("selector_port for $label out of expected 45000-49999 range: $port");
        }
    }
    if ($portA === $portB) {
        fail("REGRESSION: two endpoints on node 19 collided on selector_port $portA — the EADDRINUSE crash-storm bug");
    }

    // Determinism / idempotency: repeated calls with the same node endpoint-set reproduce the same
    // port for the same endpoint (a republish must NOT change ports — no gratuitous selector restarts).
    if (selector_port_for($service, $db, 50, 500) !== $portA) {
        fail("selector_port for 50/500 not deterministic across repeated calls");
    }
    if (selector_port_for($service, $db, 89, 621) !== $portB) {
        fail("selector_port for 89/621 not deterministic across repeated calls");
    }

    echo "ok\n";
}
