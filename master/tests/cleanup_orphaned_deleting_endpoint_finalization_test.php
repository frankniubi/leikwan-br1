<?php
declare(strict_types=1);

// FIX J: TaskCleanup::finalizeOrphanedDeletingEndpoints() finalizes a 'deleting' endpoint to
// 'deleted' (and cancels its lingering destroy task) when the destroy can NEVER complete because
// the node is effectively gone -- the destroy is ORPHANED. This covers the aliyun fwd65 endpoints
// 3254(node12)/3255(node13)/3256(node14): they sit 'deleting' with a 'destroy' task queued to nodes
// that are UNREACHABLE (load 24-30, can't pull), so the destroy is unrunnable forever.
//
// CHOSEN VARIANT (the narrow, safe one): finalize a 'deleting' endpoint ONLY when ALL of:
//   - its latest destroy provision_task is still 'pending' (destroy queued but unrun), AND
//   - the endpoint's node last_heartbeat_at is older than the offline cutoff (or node row missing)
//     -- so the destroy is provably unrunnable, AND
//   - NO sibling endpoint exists for the same (forward_id, node_id) that is 'active' or 'pending'
//     -- so we are not tearing config out from under a live endpoint on that node.
// On finalize: endpoint -> 'deleted' AND its pending destroy task -> 'completed' (note
// 'orphaned_destroy_unrunnable', error_message=NULL, lease_expires_at=NULL).
//
// Why safe: the node is effectively gone (stale heartbeat), so any slave-side selector/listener it
// still holds is moot -- there is no agent to run the teardown and the node carries no live sibling
// endpoint. Conservative + dry-run aware. In-memory fake Database (no PDO driver in this build).

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

            // Sibling active/pending endpoint for the same (forward_id,node_id).
            if (str_contains($normalized, 'SELECT COUNT(*) AS cnt FROM service_endpoints')
                && str_contains($normalized, 'id <> :id')) {
                $fid = (int) ($params['forward_id'] ?? 0);
                $nid = (int) ($params['node_id'] ?? 0);
                $id = (int) ($params['id'] ?? 0);
                $count = 0;
                foreach ($this->tables['service_endpoints'] ?? [] as $row) {
                    if ((int) ($row['forward_id'] ?? 0) === $fid
                        && (int) ($row['node_id'] ?? 0) === $nid
                        && (int) ($row['id'] ?? 0) !== $id
                        && in_array((string) ($row['health_status'] ?? ''), ['active', 'pending'], true)) {
                        $count++;
                    }
                }
                return ['cnt' => $count];
            }

            // Node staleness check: node row + last_heartbeat_at.
            if (str_contains($normalized, 'FROM nodes WHERE id = :id')) {
                return $this->findById('nodes', (int) ($params['id'] ?? 0));
            }

            // Most recent destroy task for an endpoint.
            if (str_contains($normalized, 'FROM provision_tasks')
                && str_contains($normalized, 'action = :action')
                && str_contains($normalized, 'ORDER BY id DESC')) {
                $endpointId = (int) ($params['endpoint_id'] ?? 0);
                $action = (string) ($params['action'] ?? '');
                $matches = [];
                foreach ($this->tables['provision_tasks'] ?? [] as $row) {
                    if ((int) ($row['endpoint_id'] ?? 0) === $endpointId
                        && (string) ($row['action'] ?? '') === $action) {
                        $matches[] = $row;
                    }
                }
                if ($matches === []) {
                    return null;
                }
                usort($matches, static fn(array $a, array $b): int => ((int) $b['id']) <=> ((int) $a['id']));
                return [
                    'id' => (int) $matches[0]['id'],
                    'status' => (string) ($matches[0]['status'] ?? ''),
                ];
            }

            if (str_contains($normalized, 'FROM service_endpoints WHERE id = :id')) {
                return $this->findById('service_endpoints', (int) ($params['id'] ?? 0));
            }
            if (str_contains($normalized, 'FROM provision_tasks WHERE id = :id')) {
                return $this->findById('provision_tasks', (int) ($params['id'] ?? 0));
            }

            return null;
        }

        /** @param array<string, mixed> $params */
        public function fetchAll(string $sql, array $params = []): array
        {
            $normalized = $this->normalizeSql($sql);

            // Candidate set: all 'deleting' endpoints.
            if (str_contains($normalized, 'FROM service_endpoints')
                && str_contains($normalized, 'health_status = :status')) {
                $status = (string) ($params['status'] ?? '');
                $rows = [];
                foreach ($this->tables['service_endpoints'] ?? [] as $row) {
                    if ((string) ($row['health_status'] ?? '') === $status) {
                        $rows[] = [
                            'id' => (int) $row['id'],
                            'forward_id' => (int) ($row['forward_id'] ?? 0),
                            'node_id' => (int) ($row['node_id'] ?? 0),
                        ];
                    }
                }
                usort($rows, static fn(array $a, array $b): int => ((int) $a['id']) <=> ((int) $b['id']));
                return $rows;
            }

            return [];
        }

        /** @param array<string, mixed> $params */
        public function execute(string $sql, array $params = []): int
        {
            $normalized = $this->normalizeSql($sql);

            if (str_contains($normalized, 'UPDATE service_endpoints')
                && str_contains($normalized, 'WHERE id = :id')) {
                $id = (int) ($params['id'] ?? 0);
                $oldStatus = (string) ($params['old_status'] ?? '');
                foreach ($this->tables['service_endpoints'] as $index => $row) {
                    if ((int) ($row['id'] ?? 0) === $id
                        && (string) ($row['health_status'] ?? '') === $oldStatus) {
                        $this->tables['service_endpoints'][$index]['health_status'] = (string) ($params['new_status'] ?? '');
                        $this->tables['service_endpoints'][$index]['updated_at'] = (string) ($params['now'] ?? '');
                        return 1;
                    }
                }
                return 0;
            }

            if (str_contains($normalized, 'UPDATE provision_tasks')
                && str_contains($normalized, 'WHERE id = :id')) {
                $id = (int) ($params['id'] ?? 0);
                foreach ($this->tables['provision_tasks'] as $index => $row) {
                    if ((int) ($row['id'] ?? 0) === $id
                        && (string) ($row['status'] ?? '') === 'pending') {
                        $this->tables['provision_tasks'][$index]['status'] = (string) ($params['new_status'] ?? 'completed');
                        $this->tables['provision_tasks'][$index]['result_json'] = $params['result_json'] ?? null;
                        $this->tables['provision_tasks'][$index]['error_message'] = null;
                        $this->tables['provision_tasks'][$index]['lease_expires_at'] = null;
                        $this->tables['provision_tasks'][$index]['updated_at'] = (string) ($params['now'] ?? '');
                        return 1;
                    }
                }
                return 0;
            }

            return 0;
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
    $staleHeartbeat = gmdate('Y-m-d H:i:s', time() - 4 * 86400); // node unreachable 4 days
    $freshHeartbeat = gmdate('Y-m-d H:i:s', time() - 30);

    if (!method_exists(TaskCleanup::class, 'finalizeOrphanedDeletingEndpoints')) {
        fail('TaskCleanup must expose finalizeOrphanedDeletingEndpoints()');
    }

    $db = new Database();
    $db->seed('nodes', [
        ['id' => 12, 'last_heartbeat_at' => $staleHeartbeat], // aliyun, unreachable
        ['id' => 19, 'last_heartbeat_at' => $freshHeartbeat],  // live tencent node
        // node 14 row intentionally MISSING -> treated as gone.
    ]);
    $db->seed('service_endpoints', [
        // aliyun 3254 on stale node12, lone deleting, pending destroy -> ORPHAN -> finalize.
        ['id' => 3254, 'forward_id' => 65, 'node_id' => 12, 'health_status' => 'deleting'],
        // aliyun 3256 on MISSING node14, lone deleting, pending destroy -> ORPHAN -> finalize.
        ['id' => 3256, 'forward_id' => 65, 'node_id' => 14, 'health_status' => 'deleting'],
        // deleting on a FRESH node19 -> destroy may yet run -> must be LEFT.
        ['id' => 400, 'forward_id' => 65, 'node_id' => 19, 'health_status' => 'deleting'],
        // deleting on stale node12 but a LIVE sibling (id=501 active) on same (fwd,node) -> LEFT.
        ['id' => 500, 'forward_id' => 66, 'node_id' => 12, 'health_status' => 'deleting'],
        ['id' => 501, 'forward_id' => 66, 'node_id' => 12, 'health_status' => 'active'],
        // deleting on stale node12 but latest destroy is RUNNING (not pending) -> LEFT (in-flight).
        ['id' => 600, 'forward_id' => 67, 'node_id' => 12, 'health_status' => 'deleting'],
    ]);
    $db->seed('provision_tasks', [
        ['id' => 9001, 'action' => 'destroy', 'endpoint_id' => 3254, 'status' => 'pending', 'lease_expires_at' => null, 'error_message' => null],
        ['id' => 9003, 'action' => 'destroy', 'endpoint_id' => 3256, 'status' => 'pending', 'lease_expires_at' => null, 'error_message' => null],
        ['id' => 9004, 'action' => 'destroy', 'endpoint_id' => 400,  'status' => 'pending', 'lease_expires_at' => null, 'error_message' => null],
        ['id' => 9005, 'action' => 'destroy', 'endpoint_id' => 500,  'status' => 'pending', 'lease_expires_at' => null, 'error_message' => null],
        ['id' => 9006, 'action' => 'destroy', 'endpoint_id' => 600,  'status' => 'running', 'lease_expires_at' => null, 'error_message' => null],
    ]);

    $result = TaskCleanup::finalizeOrphanedDeletingEndpoints($db, $offlineCutoff, $now, $offlineMinutes, false);

    $epStatus = static fn(int $id): string => (string) (($db->fetchOne('SELECT health_status FROM service_endpoints WHERE id = :id', ['id' => $id]) ?? [])['health_status'] ?? '');
    $taskStatus = static fn(int $id): array => $db->fetchOne('SELECT * FROM provision_tasks WHERE id = :id', ['id' => $id]) ?? [];

    // 3254 + 3256 finalized.
    foreach ([3254, 3256] as $id) {
        if ($epStatus($id) !== 'deleted') {
            fail("orphaned deleting endpoint {$id} must be finalized to 'deleted', got '" . $epStatus($id) . "'");
        }
    }
    // their destroy tasks cancelled.
    foreach ([9001 => 3254, 9003 => 3256] as $taskId => $epId) {
        $t = $taskStatus($taskId);
        if (($t['status'] ?? '') !== 'completed') {
            fail("orphaned destroy task {$taskId} must be cancelled to 'completed', got '" . ($t['status'] ?? '') . "'");
        }
        if (!str_contains((string) ($t['result_json'] ?? ''), 'orphaned_destroy_unrunnable')) {
            fail("cancelled destroy {$taskId} must carry 'orphaned_destroy_unrunnable' note");
        }
        if (!array_key_exists('error_message', $t) || $t['error_message'] !== null) {
            fail("cancelled destroy {$taskId} must have error_message=NULL");
        }
    }

    // Conservative: 400 (fresh node), 500 (live sibling), 600 (running destroy) LEFT.
    foreach ([400, 500, 600] as $id) {
        if ($epStatus($id) !== 'deleting') {
            fail("endpoint {$id} must remain 'deleting' (conservative), got '" . $epStatus($id) . "'");
        }
    }
    // their tasks untouched.
    if (($taskStatus(9004)['status'] ?? '') !== 'pending') {
        fail('fresh-node destroy 9004 must remain pending');
    }
    if (($taskStatus(9005)['status'] ?? '') !== 'pending') {
        fail('live-sibling destroy 9005 must remain pending');
    }
    if (($taskStatus(9006)['status'] ?? '') !== 'running') {
        fail('in-flight destroy 9006 must remain running');
    }

    if (($result['deleted'] ?? -1) !== 2 || ($result['cancelled'] ?? -1) !== 2) {
        fail('expected 2 finalized + 2 cancelled, got ' . json_encode($result));
    }

    // Idempotent.
    $second = TaskCleanup::finalizeOrphanedDeletingEndpoints($db, $offlineCutoff, $now, $offlineMinutes, false);
    if (($second['deleted'] ?? -1) !== 0 || ($second['cancelled'] ?? -1) !== 0) {
        fail('second run must be idempotent, got ' . json_encode($second));
    }

    // Dry run must not mutate.
    $db2 = new Database();
    $db2->seed('nodes', [['id' => 12, 'last_heartbeat_at' => $staleHeartbeat]]);
    $db2->seed('service_endpoints', [['id' => 700, 'forward_id' => 65, 'node_id' => 12, 'health_status' => 'deleting']]);
    $db2->seed('provision_tasks', [['id' => 9100, 'action' => 'destroy', 'endpoint_id' => 700, 'status' => 'pending', 'lease_expires_at' => null, 'error_message' => null]]);
    $dry = TaskCleanup::finalizeOrphanedDeletingEndpoints($db2, $offlineCutoff, $now, $offlineMinutes, true);
    if (($dry['deleted'] ?? -1) !== 1 || ($dry['cancelled'] ?? -1) !== 1) {
        fail('dry run must report what it WOULD do (1/1), got ' . json_encode($dry));
    }
    $dryEp = $db2->fetchOne('SELECT health_status FROM service_endpoints WHERE id = :id', ['id' => 700]);
    if (($dryEp['health_status'] ?? '') !== 'deleting') {
        fail('dry run must not mutate endpoints');
    }

    echo "ok\n";
}
