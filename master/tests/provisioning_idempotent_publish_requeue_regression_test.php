<?php
declare(strict_types=1);

// Behavioral regression (FIX A — idempotent publish re-queue):
// reconcileForwardEndpoints re-queued a publish for any pending|error|degraded ("unconverged")
// endpoint on EVERY heartbeat. queueTask -> collapsePendingProvisionTasksForNode supersedes the
// prior pending task, so a fresh publish task was created + immediately superseded every cycle and
// never got pulled by an oldest-first agent -> task churn livelock (514K churn rows in prod).
//
// The fix: for an UNCONVERGED endpoint, only queue a publish when there is NOT already a viable task
// for that (node, action='publish', forward, endpoint): a 'pending' row, OR a 'running' row whose
// lease_expires_at is still in the future. A staleness escape allows re-queue when the only viable
// task is a 'pending' row older than QUEUE_TASK_RESTALE_SECONDS (so a genuinely stuck task retries).
//
// This drives the REAL ProvisioningService::reconcileForwardEndpoints() against a mock DB that
// records provision_task inserts, lets provision_tasks be queried, and asserts:
//   1. first reconcile of a pending endpoint queues exactly one publish (none viable yet),
//   2. a viable pending task already exists -> a second reconcile queues NOTHING,
//   3. a viable RUNNING task (future lease) also suppresses re-queue,
//   4. a STALE pending task (older than the threshold) IS re-queued (stuck-task escape).

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

        /** Manually inject a provision_tasks row to simulate an already-queued task. */
        public function addProvisionTask(array $row): void
        {
            $row['id'] = count($this->tables['provision_tasks'] ?? []) + 1;
            $this->tables['provision_tasks'][] = $row;
        }

        /** Mutate the single pending publish task in place (simulate an agent lease or aging). */
        public function mutatePendingPublishTask(array $changes): void
        {
            if (!isset($this->tables['provision_tasks'])) {
                return;
            }
            foreach ($this->tables['provision_tasks'] as $i => $t) {
                if ((string) ($t['action'] ?? '') === 'publish' && (string) ($t['status'] ?? '') === 'pending') {
                    foreach ($changes as $k => $v) {
                        $this->tables['provision_tasks'][$i][$k] = $v;
                    }
                    return;
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
            // FIX A: hasViablePublishTask viability probe (COUNT or row lookup over provision_tasks).
            if (str_contains($n, 'FROM provision_tasks')) {
                $matches = $this->viableTasks($params);
                if (str_contains($n, 'COUNT(')) {
                    return ['viable' => count($matches), 'total' => count($matches)];
                }
                return $matches[0] ?? null;
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
            if (str_contains($n, 'FROM provision_tasks')) {
                return $this->viableTasks($params);
            }
            return [];
        }

        /**
         * Return provision_tasks that are "viable" for the queried (node, action, forward, endpoint):
         * a pending row, or a running row whose lease_expires_at is in the future.
         * @return array<int, array<string, mixed>>
         */
        private function viableTasks(array $params): array
        {
            $now = (string) ($params['now'] ?? gmdate('Y-m-d H:i:s'));
            $staleCutoff = (string) ($params['stale_cutoff'] ?? '');
            $out = [];
            foreach ($this->tables['provision_tasks'] ?? [] as $t) {
                if (isset($params['node_id']) && (int) ($t['node_id'] ?? 0) !== (int) $params['node_id']) {
                    continue;
                }
                if (isset($params['action']) && (string) ($t['action'] ?? '') !== (string) $params['action']) {
                    continue;
                }
                if (isset($params['forward_id']) && (int) ($t['forward_id'] ?? 0) !== (int) $params['forward_id']) {
                    continue;
                }
                if (isset($params['endpoint_id']) && (int) ($t['endpoint_id'] ?? 0) !== (int) $params['endpoint_id']) {
                    continue;
                }
                $status = (string) ($t['status'] ?? '');
                if ($status === 'pending') {
                    // Honor the staleness escape: a pending row older than the cutoff is NON-viable.
                    $createdAt = (string) ($t['created_at'] ?? '');
                    if ($staleCutoff !== '' && $createdAt !== '' && $createdAt <= $staleCutoff) {
                        continue;
                    }
                    $out[] = $t;
                    continue;
                }
                if ($status === 'running') {
                    $lease = (string) ($t['lease_expires_at'] ?? '');
                    if ($lease !== '' && $lease > $now) {
                        $out[] = $t;
                    }
                }
            }
            return $out;
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
                unset($row);
                $this->tables['app_settings'][] = [
                    'setting_key' => (string) ($params['setting_key'] ?? ''),
                    'setting_value' => $params['setting_value'] ?? null,
                ];
                return 1;
            }
            // collapsePendingProvisionTasksForNode: mark matching pending tasks completed.
            if (str_contains($n, 'UPDATE provision_tasks') && str_contains($n, "status = :completed_status")) {
                $count = 0;
                foreach (($this->tables['provision_tasks'] ?? []) as $i => $t) {
                    if ((int) ($t['node_id'] ?? 0) !== (int) ($params['node_id'] ?? 0)) {
                        continue;
                    }
                    if ((string) ($t['action'] ?? '') !== (string) ($params['action'] ?? '')) {
                        continue;
                    }
                    if ((int) ($t['forward_id'] ?? 0) !== (int) ($params['forward_id'] ?? 0)) {
                        continue;
                    }
                    if ((int) ($t['endpoint_id'] ?? 0) !== (int) ($params['endpoint_id'] ?? 0)) {
                        continue;
                    }
                    if ((string) ($t['status'] ?? '') !== 'pending') {
                        continue;
                    }
                    $this->tables['provision_tasks'][$i]['status'] = 'completed';
                    $count++;
                }
                return $count;
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

    $envPath = tempnam(sys_get_temp_dir(), 'lw-idempotent-requeue-env-');
    file_put_contents($envPath, "DEFAULT_TLS_CERT_REF=default-edge-cert\nQUEUE_TASK_RESTALE_SECONDS=300\n");
    $config = new Config($envPath);

    function makeDb(Config $config): Database
    {
        $db = new Database($config);
        $db->seed('app_settings', []);
        $db->seed('provision_tasks', []);
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
        // An UNCONVERGED (pending) endpoint already exists for node 11 -> reconcile would re-publish it.
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
            'health_status' => 'pending',
            'created_at' => '2026-05-20 00:00:00',
            'updated_at' => '2026-05-20 00:00:00',
        ]]);
        return $db;
    }

    // (1) No viable task yet -> first reconcile of a pending endpoint queues exactly one publish.
    $db = makeDb($config);
    $service = new ProvisioningService($config, $db);
    call_private($service, 'reconcileForwardEndpoints', 9);
    $afterFirst = $db->publishTaskCount();
    if ($afterFirst !== 1) {
        fail("first reconcile of an unconverged endpoint must queue exactly one publish, got {$afterFirst}");
    }

    // (2) A FRESH viable pending task now exists (it was just inserted) -> a second reconcile must NOT
    // queue another publish (this is the churn fix: no per-heartbeat supersede+recreate).
    call_private($service, 'reconcileForwardEndpoints', 9);
    $afterSecond = $db->publishTaskCount();
    if ($afterSecond !== $afterFirst) {
        fail("REGRESSION: unconverged endpoint with a viable pending task was re-queued ({$afterFirst} -> {$afterSecond}) — per-heartbeat churn");
    }

    // (3) A viable RUNNING task whose lease is in the future also suppresses a re-queue.
    // First reconcile establishes the fingerprint baseline + a pending publish task (so the active-drift
    // path is quiet on subsequent reconciles), then we promote that task to running with a future lease.
    $db3 = makeDb($config);
    $service3 = new ProvisioningService($config, $db3);
    call_private($service3, 'reconcileForwardEndpoints', 9);
    if ($db3->publishTaskCount() !== 1) {
        fail('case3 baseline reconcile expected exactly one publish, got ' . $db3->publishTaskCount());
    }
    $db3->mutatePendingPublishTask([
        'status' => 'running',
        'lease_expires_at' => gmdate('Y-m-d H:i:s', time() + 600),
    ]);
    call_private($service3, 'reconcileForwardEndpoints', 9);
    if ($db3->publishTaskCount() !== 1) {
        fail('a viable RUNNING task (future lease) must suppress a publish re-queue, got ' . $db3->publishTaskCount());
    }

    // (4) Staleness escape: the only viable task is a pending row older than QUEUE_TASK_RESTALE_SECONDS
    // -> a genuinely stuck/never-pulled task IS re-queued so it can be retried.
    // Baseline reconcile, then age the pending task past the threshold.
    $db4 = makeDb($config);
    $service4 = new ProvisioningService($config, $db4);
    call_private($service4, 'reconcileForwardEndpoints', 9);
    if ($db4->publishTaskCount() !== 1) {
        fail('case4 baseline reconcile expected exactly one publish, got ' . $db4->publishTaskCount());
    }
    $db4->mutatePendingPublishTask([
        'created_at' => gmdate('Y-m-d H:i:s', time() - 3600), // 1h old, well past 300s threshold
    ]);
    call_private($service4, 'reconcileForwardEndpoints', 9);
    if ($db4->publishTaskCount() !== 2) {
        fail('a STALE pending task (older than QUEUE_TASK_RESTALE_SECONDS) must be re-queued, got ' . $db4->publishTaskCount());
    }

    echo "ok\n";
}
