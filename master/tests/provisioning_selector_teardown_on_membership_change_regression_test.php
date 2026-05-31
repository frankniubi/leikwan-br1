<?php
declare(strict_types=1);

// Behavioral regression (Appendix C.2): when a member is removed from a forward/combination,
// reconcileForwardEndpoints queues a 'destroy' task for the dropped node — but the destroy
// payload omitted any selector-teardown signal, so the slave-side sidecar-uplink lane selector
// could linger (and surviving forwards were not refreshed), leaving a stale selector binding a
// loopback port and racing dead candidates.
//
// The fix attaches an explicit selector_teardown block to the destroy payload identifying the
// (service_id, endpoint_id) whose selector unit must be stopped, so no stale selector lingers.
//
// This drives the REAL ProvisioningService::reconcileForwardEndpoints() against a mock DB,
// removes a member (node leaves the reachable pool), and asserts the emitted destroy task carries
// selector_teardown.enabled = true for the dropped endpoint.

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<int, array{table:string,data:array<string,mixed>}> */
        public array $inserts = [];

        /** @var array<string, array<int, array<string, mixed>>> */
        private array $tables = [];

        public bool $nodeReachable = true;

        public function __construct(\Leikwan\Master\Config $config) {}

        public function seed(string $table, array $rows): void
        {
            $this->tables[$table] = array_values($rows);
        }

        public function fetchOne(string $sql, array $params = []): ?array
        {
            $n = $this->normalize($sql);
            if (str_contains($n, 'FROM forwards f INNER JOIN services s ON s.id = f.service_id WHERE f.id = :id LIMIT 1')) {
                foreach ($this->tables['forwards'] ?? [] as $f) {
                    if ((int) ($f['id'] ?? 0) === (int) ($params['id'] ?? 0)) {
                        $service = $this->findById('services', (int) ($f['service_id'] ?? 0));
                        return array_merge($f, ['service_status' => (string) ($service['status'] ?? '')]);
                    }
                }
                return null;
            }
            if (str_contains($n, 'FROM app_settings WHERE setting_key = :setting_key')) {
                foreach ($this->tables['app_settings'] ?? [] as $row) {
                    if ((string) ($row['setting_key'] ?? '') === (string) ($params['setting_key'] ?? '')) {
                        return $row;
                    }
                }
                return null;
            }
            if (str_contains($n, 'SELECT COUNT(*) AS total FROM nodes n')) {
                return ['total' => $this->nodeReachable ? count($this->tables['nodes'] ?? []) : 0];
            }
            return null;
        }

        public function fetchAll(string $sql, array $params = []): array
        {
            $n = $this->normalize($sql);
            if (str_contains($n, 'FROM forward_combination_helper_groups')) {
                return [];
            }
            if (str_contains($n, 'FROM nodes n LEFT JOIN node_groups g ON g.id = n.group_id')) {
                if (!$this->nodeReachable) {
                    return [];
                }
                $rows = [];
                foreach ($this->tables['nodes'] ?? [] as $node) {
                    if (!in_array((string) ($node['status'] ?? ''), ['active', 'degraded'], true)) {
                        continue;
                    }
                    $group = $this->findById('node_groups', (int) ($node['group_id'] ?? 0));
                    $rows[] = array_merge($node, ['node_group_key' => (string) ($group['group_key'] ?? '')]);
                }
                return $rows;
            }
            if (str_contains($n, 'FROM service_endpoints WHERE forward_id = :forward_id')) {
                $rows = [];
                foreach ($this->tables['service_endpoints'] ?? [] as $e) {
                    if ((int) ($e['forward_id'] ?? 0) !== (int) ($params['forward_id'] ?? 0)) {
                        continue;
                    }
                    if (in_array((string) ($e['health_status'] ?? ''), ['deleted', 'deleting'], true)) {
                        continue;
                    }
                    $rows[] = $e;
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
            if (str_contains($n, 'UPDATE app_settings SET setting_value')) {
                foreach ($this->tables['app_settings'] as &$row) {
                    if ((string) ($row['setting_key'] ?? '') === (string) ($params['setting_key'] ?? '')) {
                        $row['setting_value'] = $params['setting_value'] ?? null;
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

        /** @return array<int, array<string, mixed>> destroy-task payloads */
        public function destroyPayloads(): array
        {
            $out = [];
            foreach ($this->inserts as $row) {
                if ($row['table'] === 'provision_tasks' && (string) ($row['data']['action'] ?? '') === 'destroy') {
                    $out[] = json_decode((string) ($row['data']['payload_json'] ?? '{}'), true);
                }
            }
            return $out;
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

    $envPath = tempnam(sys_get_temp_dir(), 'lw-selector-teardown-env-');
    file_put_contents($envPath, "DEFAULT_TLS_CERT_REF=default-edge-cert\n");
    $config = new Config($envPath);

    $db = new Database($config);
    $db->seed('app_settings', []);
    $db->seed('services', [['id' => 1, 'status' => 'active', 'client_token' => 'svc-tok']]);
    $db->seed('node_groups', [['id' => 5, 'group_key' => 'hk-pool']]);
    $db->seed('nodes', [[
        'id' => 11,
        'group_id' => 5,
        'name' => 'edge-11',
        'status' => 'active',
        'public_ip' => '203.0.113.11',
        'current_load' => 0,
        'last_heartbeat_at' => '2026-05-30 00:00:00',
        'agent_config_json' => null,
    ]]);
    $db->seed('forwards', [[
        'id' => 9,
        'service_id' => 1,
        'enabled' => 1,
        'client_mode' => 'single',
        'endpoint_mode' => 'slave_group',
        'bond_ingress_mode' => 'wss_path_mux',
        'backend_mode' => 'current_mode',
        'node_group_key' => 'hk-pool',
        'forward_combination_id' => null,
        'public_port' => 443,
        'path_prefix' => 'route-9',
        'sni_host' => 'edge.example.com',
        'cdn_host' => '',
        'filter_profile' => 'off',
        'transport_mode' => 'tcp',
        'target_host' => 'origin.example.com',
        'target_port' => 80,
    ]]);
    // Endpoint already active on node 11.
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

    $service = new ProvisioningService($config, $db);

    // Membership change: the node leaves the reachable pool -> endpoint must be destroyed.
    $db->nodeReachable = false;
    call_private($service, 'reconcileForwardEndpoints', 9);

    $destroys = $db->destroyPayloads();
    if ($destroys === []) {
        fail('removing a member must queue a destroy task for the dropped endpoint');
    }
    $payload = $destroys[0];
    $teardown = $payload['selector_teardown'] ?? null;
    if (!is_array($teardown)) {
        fail('destroy payload must include a selector_teardown block (C.2 — no stale selector should linger)');
    }
    if (($teardown['enabled'] ?? false) !== true) {
        fail('selector_teardown.enabled must be true on a membership-change destroy');
    }
    if ((int) ($teardown['endpoint_id'] ?? 0) !== 70) {
        fail('selector_teardown must identify the dropped endpoint_id 70, got ' . var_export($teardown['endpoint_id'] ?? null, true));
    }
    if ((int) ($teardown['service_id'] ?? 0) !== 1) {
        fail('selector_teardown must identify the service_id 1, got ' . var_export($teardown['service_id'] ?? null, true));
    }

    echo "ok\n";
}
