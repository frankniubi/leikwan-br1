<?php
declare(strict_types=1);

// Behavioral regression (Appendix C node-heartbeat republish gate): node heartbeats fire
// every ~30s. Re-publishing every active endpoint on every heartbeat saturates the panel
// uplink. The heartbeat must therefore only call republishActiveEndpointsForNode (which queues
// a per-endpoint 'publish' provision_task) when the node's identity/capability fingerprint
// actually changes — with a bounded force interval as a safety net.
//
// This drives the REAL NodeController::heartbeat() against a mock DB that PERSISTS the node's
// agent_config_json (so the stored fingerprint round-trips) and records every provision_task
// insert, and asserts:
//   1. the first heartbeat (no stored fingerprint) republishes -> a publish task is queued,
//   2. a second IDENTICAL heartbeat does NOT republish -> no new publish task,
//   3. a heartbeat with a CHANGED capability republishes again,
// i.e. behaviour (which commands are/are not issued), never source substrings.

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<int, array{table:string,data:array<string,mixed>}> */
        public array $inserts = [];

        /** @var array<string, array<int, array<string, mixed>>> */
        private array $tables = [];

        public function __construct(\Leikwan\Master\Config $config) {}

        public function seed(string $table, array $rows): void
        {
            $this->tables[$table] = array_values($rows);
        }

        /** @return array<int, array<string, mixed>> */
        public function rows(string $table): array
        {
            return $this->tables[$table] ?? [];
        }

        public function fetchOne(string $sql, array $params = []): ?array
        {
            $n = $this->normalize($sql);
            if (str_contains($n, 'FROM nodes WHERE api_token = :token')) {
                foreach ($this->tables['nodes'] ?? [] as $node) {
                    if ((string) ($node['api_token'] ?? '') === (string) ($params['token'] ?? '')) {
                        return $node;
                    }
                }
                return null;
            }
            if (str_contains($n, 'SELECT * FROM nodes WHERE id = :id LIMIT 1')) {
                foreach ($this->tables['nodes'] ?? [] as $node) {
                    if ((int) ($node['id'] ?? 0) === (int) ($params['id'] ?? 0)) {
                        return $node;
                    }
                }
                return null;
            }
            return null;
        }

        public function fetchAll(string $sql, array $params = []): array
        {
            $n = $this->normalize($sql);
            // republishActiveEndpointsForNode join query.
            if (str_contains($n, 'FROM service_endpoints e INNER JOIN forwards f ON f.id = e.forward_id')) {
                $nodeId = (int) ($params['node_id'] ?? 0);
                $rows = [];
                foreach ($this->tables['service_endpoints'] ?? [] as $e) {
                    if ((int) ($e['node_id'] ?? 0) !== $nodeId) {
                        continue;
                    }
                    $health = (string) ($e['health_status'] ?? '');
                    if (in_array($health, ['deleted', 'deleting'], true)) {
                        continue;
                    }
                    $forward = null;
                    foreach ($this->tables['forwards'] ?? [] as $f) {
                        if ((int) ($f['id'] ?? 0) === (int) ($e['forward_id'] ?? 0)) {
                            $forward = $f;
                            break;
                        }
                    }
                    if ($forward === null || (int) ($forward['enabled'] ?? 0) !== 1) {
                        continue;
                    }
                    $service = null;
                    foreach ($this->tables['services'] ?? [] as $s) {
                        if ((int) ($s['id'] ?? 0) === (int) ($forward['service_id'] ?? 0)) {
                            $service = $s;
                            break;
                        }
                    }
                    if ($service === null || (string) ($service['status'] ?? '') !== 'active') {
                        continue;
                    }
                    $rows[] = array_merge($e, $forward, [
                        'service_endpoint_id' => (int) ($e['id'] ?? 0),
                        'service_status' => (string) ($service['status'] ?? ''),
                    ]);
                }
                return $rows;
            }
            return [];
        }

        public function insert(string $table, array $data): int
        {
            $this->inserts[] = ['table' => $table, 'data' => $data];
            $id = count($this->tables[$table] ?? []) + 1;
            $data['id'] = $id;
            $this->tables[$table][] = $data;
            return $id;
        }

        public function execute(string $sql, array $params = []): int
        {
            $n = $this->normalize($sql);
            if (str_contains($n, 'UPDATE nodes SET')) {
                foreach ($this->tables['nodes'] as &$node) {
                    if ((int) ($node['id'] ?? 0) === (int) ($params['id'] ?? 0)) {
                        // Persist the merged agent_config_json so the stored fingerprint round-trips.
                        if (array_key_exists('agent_config_json', $params)) {
                            $node['agent_config_json'] = $params['agent_config_json'];
                        }
                        foreach (['public_ip', 'agent_version', 'status', 'supports_udp', 'supports_filtering'] as $k) {
                            if (array_key_exists($k, $params)) {
                                $node[$k] = $params[$k];
                            }
                        }
                        return 1;
                    }
                }
            }
            return 0;
        }

        public function transaction(callable $callback)
        {
            return $callback($this);
        }

        public function publishTaskCount(): int
        {
            $count = 0;
            foreach ($this->inserts as $row) {
                if ($row['table'] === 'provision_tasks' && (string) ($row['data']['action'] ?? '') === 'publish') {
                    $count++;
                }
            }
            return $count;
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
        $relative = substr($class, strlen($prefix));
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });

    use Leikwan\Master\Config;
    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Http\Request;
    use Leikwan\Master\Controllers\NodeController;
    use Leikwan\Master\Support\ProvisioningService;

    function fail(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    function heartbeat_request(string $token, array $body): Request
    {
        return new Request(
            'POST',
            '/api/node/heartbeat',
            [],
            $body,
            ['authorization' => 'Bearer ' . $token],
            (string) json_encode($body)
        );
    }

    $envPath = tempnam(sys_get_temp_dir(), 'lw-node-hb-env-');
    file_put_contents($envPath, "DEFAULT_TLS_CERT_REF=default-edge-cert\nNODE_REPUBLISH_FORCE_INTERVAL_SECONDS=600\n");
    $config = new Config($envPath);

    $db = new Database($config);
    $db->seed('services', [['id' => 1, 'status' => 'active', 'client_token' => 'svc-tok']]);
    $db->seed('nodes', [[
        'id' => 11,
        'api_token' => 'node-tok',
        'group_id' => 5,
        'name' => 'edge-11',
        'region' => 'hk',
        'status' => 'active',
        'public_ip' => '203.0.113.11',
        'private_ip' => '10.0.0.11',
        'machine_id' => 'mid-11',
        'agent_version' => '2026.05.20.1',
        'supports_udp' => 1,
        'supports_filtering' => 0,
        'agent_config_json' => null,
    ]]);
    $db->seed('forwards', [[
        'id' => 9,
        'service_id' => 1,
        'enabled' => 1,
        'client_mode' => 'single',
        'bond_ingress_mode' => 'wss_path_mux',
        'backend_mode' => 'current_mode',
        'public_port' => 443,
        'path_prefix' => 'route-9',
        'sni_host' => 'edge.example.com',
        'cdn_host' => '',
        'filter_profile' => 'off',
        'transport_mode' => 'tcp',
        'target_host' => 'origin.example.com',
        'target_port' => 80,
        'forward_combination_id' => null,
    ]]);
    $db->seed('service_endpoints', [[
        'id' => 70,
        'forward_id' => 9,
        'node_id' => 11,
        'public_host' => '203.0.113.11',
        'public_ip' => '203.0.113.11',
        'public_port' => 443,
        'cdn_host' => '',
        'priority' => 10,
        'weight' => 100,
        'health_status' => 'active',
        'created_at' => '2026-05-20 00:00:00',
        'updated_at' => '2026-05-20 00:00:00',
    ]]);

    $provisioning = new ProvisioningService($config, $db);
    $controller = new NodeController($config, $db, $provisioning);

    $body = [
        'status' => 'active',
        'agent_version' => '2026.05.20.1',
        'public_ip' => '203.0.113.11',
        'private_ip' => '10.0.0.11',
        'machine_id' => 'mid-11',
        'supports_udp' => true,
        'supports_filtering' => false,
        'supports_quic' => true,
        'bond_protocols' => ['quic'],
    ];

    // (1) First heartbeat: no stored fingerprint -> must republish (queue a publish task).
    $controller->heartbeat(heartbeat_request('node-tok', $body), []);
    $afterFirst = $db->publishTaskCount();
    if ($afterFirst < 1) {
        fail('first heartbeat must republish active endpoints (no publish task was queued)');
    }

    // (2) Identical heartbeat -> the gate must SKIP republish (no new publish task).
    $controller->heartbeat(heartbeat_request('node-tok', $body), []);
    $afterSecond = $db->publishTaskCount();
    if ($afterSecond !== $afterFirst) {
        fail("REGRESSION: identical heartbeat re-published despite unchanged fingerprint (publish tasks {$afterFirst} -> {$afterSecond})");
    }

    // Dynamic selector telemetry is persisted but excluded from the endpoint capability fingerprint.
    $telemetry = $body;
    $telemetry['leigod_forward_selections'] = [];
    for ($index = 0; $index < 140; $index++) {
        $telemetry['leigod_forward_selections'][] = [
            'service_name' => 'service-' . $index,
            'mode' => 'race',
            'selected_combination_id' => 100 + $index,
            'selected_line_id' => 451,
            'selected_at' => gmdate('c'),
        ];
    }
    $controller->heartbeat(heartbeat_request('node-tok', $telemetry), []);
    $afterTelemetry = $db->publishTaskCount();
    if ($afterTelemetry !== $afterSecond) {
        fail('dynamic LeiGod selection telemetry changed the endpoint republish fingerprint');
    }
    $storedNode = $db->rows('nodes')[0] ?? [];
    $storedConfig = json_decode((string) ($storedNode['agent_config_json'] ?? ''), true);
    if (!is_array($storedConfig) || count((array) ($storedConfig['leigod_forward_selections'] ?? [])) !== 128) {
        fail('LeiGod selection telemetry was not normalized to the 128-row cap');
    }

    // (3) Changed capability -> fingerprint differs -> must republish again.
    $changed = $body;
    $changed['agent_version'] = '2026.05.31.9';
    $controller->heartbeat(heartbeat_request('node-tok', $changed), []);
    $afterThird = $db->publishTaskCount();
    if ($afterThird <= $afterSecond) {
        fail("changed-capability heartbeat must republish (publish tasks {$afterSecond} -> {$afterThird})");
    }

    echo "ok\n";
}
