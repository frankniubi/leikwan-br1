<?php
declare(strict_types=1);

// Behavioral regression (Appendix C.4a + C.4c): reconcileForwardEndpoints only re-published
// endpoints whose health_status was pending/error/degraded and SKIPPED active ones. So when an
// admin changed a forward's materially-published payload (target host/port, transport/routing
// mode, etc.) the already-active endpoints kept serving the STALE payload forever.
//
// The fix stores a per-endpoint desired-payload FINGERPRINT (sha1 over the fields that actually
// drift) and re-publishes an active endpoint EVEN IF status==active when its fingerprint changes,
// while leaving an unchanged active endpoint untouched.
//
// This drives the REAL ProvisioningService::reconcileForwardEndpoints() against a mock DB that
// records provision_task inserts and persists the fingerprint k/v, and asserts:
//   1. first reconcile of an active endpoint queues a publish (no stored fingerprint yet),
//   2. an unchanged second reconcile queues NOTHING (the active-skip / no-drift path),
//   3. changing the forward's target re-publishes the SAME active endpoint.

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

        public function setForwardTarget(int $forwardId, string $host, int $port): void
        {
            foreach ($this->tables['forwards'] as &$f) {
                if ((int) ($f['id'] ?? 0) === $forwardId) {
                    $f['target_host'] = $host;
                    $f['target_port'] = $port;
                }
            }
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
                return ['total' => count($this->tables['nodes'] ?? [])];
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

        public function publishTaskCount(): int
        {
            $c = 0;
            foreach ($this->inserts as $row) {
                if ($row['table'] === 'provision_tasks' && (string) ($row['data']['action'] ?? '') === 'publish') {
                    $c++;
                }
            }
            return $c;
        }

        /** @return array<int, string> publish-task target strings, in insert order */
        public function publishTargets(): array
        {
            $targets = [];
            foreach ($this->inserts as $row) {
                if ($row['table'] === 'provision_tasks' && (string) ($row['data']['action'] ?? '') === 'publish') {
                    $payload = json_decode((string) ($row['data']['payload_json'] ?? '{}'), true);
                    $targets[] = (string) ($payload['target'] ?? '');
                }
            }
            return $targets;
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

    $envPath = tempnam(sys_get_temp_dir(), 'lw-active-drift-env-');
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
        'agent_version' => '2026.05.20.1',
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
        'target_host' => 'origin-a.example.com',
        'target_port' => 80,
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

    $service = new ProvisioningService($config, $db);

    // (1) First reconcile: active endpoint, no stored fingerprint -> must publish to capture drift baseline.
    call_private($service, 'reconcileForwardEndpoints', 9);
    $afterFirst = $db->publishTaskCount();
    if ($afterFirst < 1) {
        fail('first reconcile of an active endpoint must publish to establish the desired-payload fingerprint baseline');
    }

    // (2) Unchanged reconcile -> no drift -> must NOT publish again.
    call_private($service, 'reconcileForwardEndpoints', 9);
    $afterSecond = $db->publishTaskCount();
    if ($afterSecond !== $afterFirst) {
        fail("REGRESSION: unchanged active endpoint was re-published ({$afterFirst} -> {$afterSecond})");
    }

    // (3) Change the forward target -> the materially-published payload drifts -> active endpoint must republish.
    $db->setForwardTarget(9, 'origin-b.example.com', 8443);
    call_private($service, 'reconcileForwardEndpoints', 9);
    $afterThird = $db->publishTaskCount();
    if ($afterThird <= $afterSecond) {
        fail('drifted active endpoint (changed target) must be re-published even though status==active');
    }

    $targets = $db->publishTargets();
    $lastTarget = end($targets);
    if ($lastTarget !== 'origin-b.example.com:8443') {
        fail('re-published payload must carry the NEW target, got ' . var_export($lastTarget, true));
    }

    echo "ok\n";
}
