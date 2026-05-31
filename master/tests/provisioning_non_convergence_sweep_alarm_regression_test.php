<?php
declare(strict_types=1);

// Behavioral regression (Appendix C.4b): a periodic sweep must re-push endpoints whose
// updated_at is STALE relative to the forward/combination updated_at (i.e. an edit landed but the
// endpoint never re-converged), and ESCALATE an alarm (log + persisted marker) after N consecutive
// non-convergent cycles (config NON_CONVERGENCE_ALARM_CYCLES, default 3).
//
// This drives the REAL ProvisioningService::sweepNonConvergentEndpoints() against a mock DB that
// keeps the endpoint perpetually stale and records provision_task inserts + app_settings, and
// asserts:
//   1. a stale endpoint is re-pushed (a publish task is queued),
//   2. after N still-non-convergent cycles an alarm marker is persisted,
//   3. a converged (fresh) endpoint is neither re-pushed nor alarmed.

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

        public function fetchOne(string $sql, array $params = []): ?array
        {
            $n = $this->normalize($sql);
            if (str_contains($n, 'FROM app_settings WHERE setting_key = :setting_key')) {
                foreach ($this->tables['app_settings'] ?? [] as $row) {
                    if ((string) ($row['setting_key'] ?? '') === (string) ($params['setting_key'] ?? '')) {
                        return $row;
                    }
                }
                return null;
            }
            if (str_contains($n, 'FROM forwards f INNER JOIN services s ON s.id = f.service_id WHERE f.id = :id LIMIT 1')) {
                foreach ($this->tables['forwards'] ?? [] as $f) {
                    if ((int) ($f['id'] ?? 0) === (int) ($params['id'] ?? 0)) {
                        return array_merge($f, ['service_status' => 'active']);
                    }
                }
                return null;
            }
            if (str_contains($n, 'SELECT COUNT(*) AS total FROM nodes n')) {
                return ['total' => count($this->tables['nodes'] ?? [])];
            }
            if (str_contains($n, 'SELECT * FROM nodes WHERE id = :id LIMIT 1')) {
                return $this->findById('nodes', (int) ($params['id'] ?? 0));
            }
            return null;
        }

        public function fetchAll(string $sql, array $params = []): array
        {
            $n = $this->normalize($sql);
            // The non-convergence sweep query.
            if (str_contains($n, 'FROM service_endpoints e INNER JOIN forwards f ON f.id = e.forward_id')
                && str_contains($n, 'LEFT JOIN forward_combinations fc')) {
                $rows = [];
                foreach ($this->tables['service_endpoints'] ?? [] as $e) {
                    if ((string) ($e['health_status'] ?? '') !== 'active') {
                        continue;
                    }
                    $forward = $this->findById('forwards', (int) ($e['forward_id'] ?? 0));
                    if ($forward === null || (int) ($forward['enabled'] ?? 0) !== 1) {
                        continue;
                    }
                    $combo = (int) ($forward['forward_combination_id'] ?? 0) > 0
                        ? $this->findById('forward_combinations', (int) $forward['forward_combination_id'])
                        : null;
                    $rows[] = array_merge($forward, [
                        'service_endpoint_id' => (int) ($e['id'] ?? 0),
                        'endpoint_node_id' => (int) ($e['node_id'] ?? 0),
                        'endpoint_updated_at' => (string) ($e['updated_at'] ?? ''),
                        'endpoint_health_status' => (string) ($e['health_status'] ?? ''),
                        'forward_updated_at' => (string) ($forward['updated_at'] ?? ''),
                        'combination_updated_at' => $combo !== null ? (string) ($combo['updated_at'] ?? '') : null,
                        'service_status' => 'active',
                    ]);
                }
                return $rows;
            }
            // republishActiveEndpoint join (single endpoint).
            if (str_contains($n, 'FROM service_endpoints e INNER JOIN forwards f ON f.id = e.forward_id')) {
                return [];
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

        public function appSetting(string $key): ?array
        {
            return $this->fetchOne('SELECT setting_value FROM app_settings WHERE setting_key = :setting_key LIMIT 1', ['setting_key' => $key]);
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

    function make_db(Config $config, string $endpointUpdatedAt): Database
    {
        $db = new Database($config);
        $db->seed('app_settings', []);
        $db->seed('services', [['id' => 1, 'status' => 'active']]);
        $db->seed('nodes', [['id' => 11, 'status' => 'active', 'public_ip' => '203.0.113.11']]);
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
            'updated_at' => '2026-05-31 12:00:00', // forward edited at noon
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
            'updated_at' => $endpointUpdatedAt,
        ]]);
        return $db;
    }

    $envPath = tempnam(sys_get_temp_dir(), 'lw-nonconv-env-');
    file_put_contents($envPath, "DEFAULT_TLS_CERT_REF=default-edge-cert\nNON_CONVERGENCE_ALARM_CYCLES=3\n");
    $config = new Config($envPath);

    // --- Stale endpoint (updated BEFORE the forward edit) stays non-convergent across sweeps. ---
    $db = make_db($config, '2026-05-31 09:00:00'); // endpoint last published at 9am, forward edited at noon
    $service = new ProvisioningService($config, $db);

    // (1) First sweep re-pushes the stale endpoint.
    $service->sweepNonConvergentEndpoints();
    if ($db->publishTaskCount() < 1) {
        fail('a stale endpoint (endpoint.updated_at < forward.updated_at) must be re-pushed by the sweep');
    }

    // No alarm yet after 1 cycle.
    if ($db->appSetting('non_convergence_alarm') !== null) {
        fail('alarm marker must not be set after only 1 non-convergent cycle (N=3)');
    }

    // (2) Run more sweeps; the mock never updates the endpoint, so it stays non-convergent.
    $service->sweepNonConvergentEndpoints(); // cycle 2
    if ($db->appSetting('non_convergence_alarm') !== null) {
        fail('alarm marker must not be set after only 2 non-convergent cycles (N=3)');
    }
    $service->sweepNonConvergentEndpoints(); // cycle 3 -> alarm
    $alarm = $db->appSetting('non_convergence_alarm');
    if ($alarm === null) {
        fail('alarm marker must be set after N=3 consecutive non-convergent cycles');
    }
    $alarmBody = json_decode((string) ($alarm['setting_value'] ?? ''), true);
    if (!is_array($alarmBody) || (int) ($alarmBody['consecutive_cycles'] ?? 0) < 3) {
        fail('alarm marker must record at least 3 consecutive non-convergent cycles');
    }

    // --- Converged endpoint (updated AFTER the forward edit) -> no re-push, no alarm. ---
    $db2 = make_db($config, '2026-05-31 12:05:00'); // endpoint re-published after the noon edit
    $service2 = new ProvisioningService($config, $db2);
    $service2->sweepNonConvergentEndpoints();
    if ($db2->publishTaskCount() !== 0) {
        fail('a converged endpoint (updated_at >= forward.updated_at) must NOT be re-pushed');
    }
    $service2->sweepNonConvergentEndpoints();
    $service2->sweepNonConvergentEndpoints();
    $service2->sweepNonConvergentEndpoints();
    if ($db2->appSetting('non_convergence_alarm') !== null) {
        fail('a converged endpoint must never raise the non-convergence alarm');
    }

    echo "ok\n";
}
