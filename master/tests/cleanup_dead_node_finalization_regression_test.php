<?php
declare(strict_types=1);

// Regression: cleanup-tasks.php cancelled pending TASKS for offline nodes but left the
// nodes' service_endpoints stuck (a dead node like node3 offline 4 days never acks its
// destroy/publish tasks), and never demoted the node from "active" to "offline". The
// cleanup must now (a) finalize stranded endpoints (deleting->deleted, pending->error)
// and (b) demote stale nodes, while leaving a fresh node untouched.
//
// No PDO driver is available in this PHP build, so the test installs an in-memory fake
// Database (same pattern as provisioning_static_sidecar_selector_port_regression_test.php)
// that models the nodes + service_endpoints tables and applies the exact WHERE predicates
// the cleanup SQL uses. The REAL TaskCleanup logic (count gating, status transitions,
// last_error note, offline-node filter, dry-run no-op) is exercised against that model.

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

        /** @return array<int, array<string, mixed>> */
        public function rows(string $table): array
        {
            return $this->tables[$table] ?? [];
        }

        /** @param array<string, mixed> $params */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            $normalized = $this->normalizeSql($sql);

            if (str_contains($normalized, 'SELECT COUNT(*) AS cnt FROM service_endpoints')) {
                $status = (string) ($params['status'] ?? '');
                $cutoff = (string) ($params['cutoff'] ?? '');
                $count = 0;
                foreach ($this->tables['service_endpoints'] ?? [] as $endpoint) {
                    if ((string) ($endpoint['health_status'] ?? '') === $status
                        && $this->nodeIsOffline((int) ($endpoint['node_id'] ?? 0), $cutoff)) {
                        $count++;
                    }
                }
                return ['cnt' => $count];
            }

            if (str_contains($normalized, 'SELECT COUNT(*) AS cnt FROM nodes')) {
                $cutoff = (string) ($params['cutoff'] ?? '');
                $offline = (string) ($params['offline'] ?? 'offline');
                $count = 0;
                foreach ($this->tables['nodes'] ?? [] as $node) {
                    if ((string) ($node['status'] ?? '') !== $offline
                        && $this->heartbeatOlderThan($node, $cutoff)) {
                        $count++;
                    }
                }
                return ['cnt' => $count];
            }

            // Direct row lookups used by the test's assertions.
            if (str_contains($normalized, 'FROM service_endpoints WHERE id = :id')) {
                return $this->findById('service_endpoints', (int) ($params['id'] ?? 0));
            }
            if (str_contains($normalized, 'FROM nodes WHERE id = :id')) {
                return $this->findById('nodes', (int) ($params['id'] ?? 0));
            }

            return null;
        }

        /** @param array<string, mixed> $params */
        public function fetchAll(string $sql, array $params = []): array
        {
            return [];
        }

        /** @param array<string, mixed> $params */
        public function execute(string $sql, array $params = []): int
        {
            $normalized = $this->normalizeSql($sql);

            if (str_contains($normalized, 'UPDATE service_endpoints')) {
                $cutoff = (string) ($params['cutoff'] ?? '');
                $oldStatus = (string) ($params['old_status'] ?? '');
                $newStatus = (string) ($params['new_status'] ?? '');
                $affected = 0;
                foreach ($this->tables['service_endpoints'] as $index => $endpoint) {
                    if ((string) ($endpoint['health_status'] ?? '') !== $oldStatus) {
                        continue;
                    }
                    if (!$this->nodeIsOffline((int) ($endpoint['node_id'] ?? 0), $cutoff)) {
                        continue;
                    }
                    $this->tables['service_endpoints'][$index]['health_status'] = $newStatus;
                    $this->tables['service_endpoints'][$index]['updated_at'] = (string) ($params['now'] ?? '');
                    if (array_key_exists('msg', $params)) {
                        $this->tables['service_endpoints'][$index]['last_error'] = (string) $params['msg'];
                    }
                    $affected++;
                }
                return $affected;
            }

            if (str_contains($normalized, 'UPDATE nodes')) {
                $cutoff = (string) ($params['cutoff'] ?? '');
                $offline = (string) ($params['offline'] ?? 'offline');
                $affected = 0;
                foreach ($this->tables['nodes'] as $index => $node) {
                    if ((string) ($node['status'] ?? '') === $offline) {
                        continue;
                    }
                    if (!$this->heartbeatOlderThan($node, $cutoff)) {
                        continue;
                    }
                    $this->tables['nodes'][$index]['status'] = $offline;
                    $this->tables['nodes'][$index]['updated_at'] = (string) ($params['now'] ?? '');
                    $affected++;
                }
                return $affected;
            }

            return 0;
        }

        private function nodeIsOffline(int $nodeId, string $cutoff): bool
        {
            $node = $this->findById('nodes', $nodeId);
            if ($node === null) {
                return false;
            }
            return $this->heartbeatOlderThan($node, $cutoff);
        }

        /** @param array<string, mixed> $node */
        private function heartbeatOlderThan(array $node, string $cutoff): bool
        {
            $heartbeat = $node['last_heartbeat_at'] ?? null;
            if ($heartbeat === null || $heartbeat === '') {
                return true; // IS NULL matches the offline filter
            }
            return (string) $heartbeat < $cutoff;
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
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });

    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Support\TaskCleanup;

    function fail(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    $now = gmdate('Y-m-d H:i:s');
    $offlineMinutes = 60;
    $offlineCutoff = gmdate('Y-m-d H:i:s', time() - $offlineMinutes * 60);
    $staleHeartbeat = gmdate('Y-m-d H:i:s', time() - 4 * 86400); // node offline 4 days
    $freshHeartbeat = gmdate('Y-m-d H:i:s', time() - 30);        // node seen 30s ago

    $db = new Database();
    $db->seed('nodes', [
        // Dead node 3: offline 4 days, still flagged "active".
        ['id' => 3, 'status' => 'active', 'last_heartbeat_at' => $staleHeartbeat, 'updated_at' => $now],
        // Fresh node 5: heartbeat 30s ago.
        ['id' => 5, 'status' => 'active', 'last_heartbeat_at' => $freshHeartbeat, 'updated_at' => $now],
    ]);
    $db->seed('service_endpoints', [
        // Stranded endpoints on the dead node.
        ['id' => 10, 'node_id' => 3, 'health_status' => 'deleting', 'last_error' => null, 'updated_at' => $now],
        ['id' => 11, 'node_id' => 3, 'health_status' => 'pending', 'last_error' => null, 'updated_at' => $now],
        // Same states on the fresh node — must be left alone.
        ['id' => 20, 'node_id' => 5, 'health_status' => 'deleting', 'last_error' => null, 'updated_at' => $now],
        ['id' => 21, 'node_id' => 5, 'health_status' => 'pending', 'last_error' => null, 'updated_at' => $now],
    ]);

    // Run the extracted cleanup core (live mode).
    $endpointResult = TaskCleanup::finalizeOfflineNodeEndpoints($db, $offlineCutoff, $now, $offlineMinutes, false);
    $demoted = TaskCleanup::demoteStaleNodes($db, $offlineCutoff, $now, false);

    $endpointStatus = static function (Database $db, int $id): array {
        return $db->fetchOne('SELECT health_status, last_error FROM service_endpoints WHERE id = :id', ['id' => $id]) ?? [];
    };

    // --- Dead node 3: endpoints finalized.
    $ep10 = $endpointStatus($db, 10);
    if (($ep10['health_status'] ?? '') !== 'deleted') {
        fail("dead node deleting-endpoint should become 'deleted', got '" . ($ep10['health_status'] ?? '') . "'");
    }
    $ep11 = $endpointStatus($db, 11);
    if (($ep11['health_status'] ?? '') !== 'error') {
        fail("dead node pending-endpoint should become 'error', got '" . ($ep11['health_status'] ?? '') . "'");
    }
    if (trim((string) ($ep11['last_error'] ?? '')) === '') {
        fail('errored endpoint should record a last_error note');
    }
    if ($endpointResult['deleted'] !== 1 || $endpointResult['errored'] !== 1) {
        fail('expected exactly 1 deleted + 1 errored endpoint, got ' . json_encode($endpointResult));
    }

    // --- Dead node 3: demoted to offline.
    $node3 = $db->fetchOne('SELECT status FROM nodes WHERE id = :id', ['id' => 3]);
    if (($node3['status'] ?? '') !== 'offline') {
        fail("dead node should be demoted to 'offline', got '" . ($node3['status'] ?? '') . "'");
    }
    if ($demoted < 1) {
        fail('expected at least one node demoted');
    }

    // --- Fresh node 5: completely untouched.
    $ep20 = $endpointStatus($db, 20);
    if (($ep20['health_status'] ?? '') !== 'deleting') {
        fail("fresh node deleting-endpoint must be left as 'deleting', got '" . ($ep20['health_status'] ?? '') . "'");
    }
    $ep21 = $endpointStatus($db, 21);
    if (($ep21['health_status'] ?? '') !== 'pending') {
        fail("fresh node pending-endpoint must be left as 'pending', got '" . ($ep21['health_status'] ?? '') . "'");
    }
    $node5 = $db->fetchOne('SELECT status FROM nodes WHERE id = :id', ['id' => 5]);
    if (($node5['status'] ?? '') !== 'active') {
        fail("fresh node must stay 'active', got '" . ($node5['status'] ?? '') . "'");
    }

    // --- Dry run must not mutate anything.
    $db2 = new Database();
    $db2->seed('nodes', [['id' => 7, 'status' => 'active', 'last_heartbeat_at' => $staleHeartbeat, 'updated_at' => $now]]);
    $db2->seed('service_endpoints', [['id' => 30, 'node_id' => 7, 'health_status' => 'deleting', 'last_error' => null, 'updated_at' => $now]]);
    $dryEndpoints = TaskCleanup::finalizeOfflineNodeEndpoints($db2, $offlineCutoff, $now, $offlineMinutes, true);
    $dryDemoted = TaskCleanup::demoteStaleNodes($db2, $offlineCutoff, $now, true);
    if ($dryEndpoints['deleted'] !== 0 || $dryEndpoints['errored'] !== 0 || $dryDemoted !== 0) {
        fail('dry run must report 0 changes');
    }
    $ep30 = $endpointStatus($db2, 30);
    if (($ep30['health_status'] ?? '') !== 'deleting') {
        fail('dry run must not mutate endpoints');
    }
    $node7 = $db2->fetchOne('SELECT status FROM nodes WHERE id = :id', ['id' => 7]);
    if (($node7['status'] ?? '') !== 'active') {
        fail('dry run must not demote nodes');
    }

    echo "ok\n";
}
