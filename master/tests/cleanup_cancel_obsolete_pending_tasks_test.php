<?php
declare(strict_types=1);

// FIX I: TaskCleanup::cancelObsoletePendingTasks() drains the obsolete pending-task backlog that
// buries the real fwd65/66 publishes. node2/node19 each carry thousands of 'pending' provision
// tasks that reference 'deleted'/'deleting' (or missing) endpoints. Agents pull oldest-first
// (ORDER BY id ASC LIMIT 20), so they grind that 30h-old obsolete head-of-queue forever and never
// reach the newest real publishes. Worse: executing a 'publish' whose endpoint is 'deleted'/
// 'deleting' RESURRECTS it to 'active' (NodeController::taskResult), which reconcile then re-deletes
// -> flap/churn. So obsolete publishes are actively HARMFUL.
//
// The cleanup cancels (status='completed', result_json note 'obsolete_endpoint_terminal',
// error_message=NULL, lease_expires_at=NULL) PENDING provision_tasks that:
//   (1) action='publish' whose endpoint is 'deleted' or 'deleting'  (resurrection hazard)
//   (2) action='destroy' whose endpoint is 'deleted'                (already terminal)
//   (3) any pending task whose endpoint_id has NO service_endpoints row (orphan FK)
// It MUST NOT cancel:
//   - action='destroy' for a 'deleting' endpoint (that destroy is the finalizer)
//   - ANY task whose endpoint is 'active' or 'pending' (live work; e.g. the real fwd65/66 publishes)
//   - any non-pending task (already terminal)
// Idempotent + dry-run aware. In-memory fake Database (no PDO driver in this PHP build), mirroring
// cleanup_dead_node_finalization_regression_test.php.

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

            // Count of obsolete pending tasks (dry-run path). Mirrors the live UPDATE predicate.
            if (str_contains($normalized, 'SELECT COUNT(*) AS cnt FROM provision_tasks')) {
                $count = 0;
                foreach ($this->tables['provision_tasks'] ?? [] as $task) {
                    if ($this->taskIsObsolete($task)) {
                        $count++;
                    }
                }
                return ['cnt' => $count];
            }

            if (str_contains($normalized, 'FROM provision_tasks WHERE id = :id')) {
                return $this->findById('provision_tasks', (int) ($params['id'] ?? 0));
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

            if (str_contains($normalized, 'UPDATE provision_tasks')) {
                $newStatus = (string) ($params['new_status'] ?? 'completed');
                $affected = 0;
                foreach ($this->tables['provision_tasks'] as $index => $task) {
                    if (!$this->taskIsObsolete($task)) {
                        continue;
                    }
                    $this->tables['provision_tasks'][$index]['status'] = $newStatus;
                    $this->tables['provision_tasks'][$index]['result_json'] = $params['result_json'] ?? null;
                    $this->tables['provision_tasks'][$index]['error_message'] = null;
                    $this->tables['provision_tasks'][$index]['lease_expires_at'] = null;
                    $this->tables['provision_tasks'][$index]['updated_at'] = (string) ($params['now'] ?? '');
                    $affected++;
                }
                return $affected;
            }

            return 0;
        }

        /**
         * Single source of truth modelling the FIX I predicate so the count and the update agree.
         *
         * @param array<string, mixed> $task
         */
        private function taskIsObsolete(array $task): bool
        {
            if ((string) ($task['status'] ?? '') !== 'pending') {
                return false; // only PENDING tasks are candidates
            }
            $action = (string) ($task['action'] ?? '');
            $endpoint = $this->findById('service_endpoints', (int) ($task['endpoint_id'] ?? 0));

            // (3) orphan FK: endpoint row does not exist.
            if ($endpoint === null) {
                return true;
            }
            $health = (string) ($endpoint['health_status'] ?? '');

            // (1) publish onto a deleted/deleting endpoint -> resurrection hazard.
            if ($action === 'publish' && in_array($health, ['deleted', 'deleting'], true)) {
                return true;
            }
            // (2) destroy of an already-deleted endpoint -> nothing to tear down.
            if ($action === 'destroy' && $health === 'deleted') {
                return true;
            }
            // destroy of a 'deleting' endpoint is the FINALIZER -> never cancel.
            // active/pending endpoints are live -> never cancel.
            return false;
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

    if (!method_exists(TaskCleanup::class, 'cancelObsoletePendingTasks')) {
        fail('TaskCleanup must expose cancelObsoletePendingTasks()');
    }

    $db = new Database();
    $db->seed('service_endpoints', [
        ['id' => 1, 'forward_id' => 65, 'node_id' => 2,  'health_status' => 'deleted'],
        ['id' => 2, 'forward_id' => 65, 'node_id' => 2,  'health_status' => 'deleting'],
        ['id' => 3, 'forward_id' => 65, 'node_id' => 2,  'health_status' => 'active'],
        ['id' => 4, 'forward_id' => 65, 'node_id' => 19, 'health_status' => 'pending'],
        // node12 aliyun endpoint stuck deleting (FIX J territory, but its destroy must NOT be
        // cancelled here -- destroy of a 'deleting' endpoint is the finalizer).
        ['id' => 5, 'forward_id' => 65, 'node_id' => 12, 'health_status' => 'deleting'],
    ]);
    $db->seed('provision_tasks', [
        // (1) publish onto deleted endpoint -> RESURRECTION hazard -> cancel.
        ['id' => 100, 'action' => 'publish', 'endpoint_id' => 1, 'status' => 'pending', 'lease_expires_at' => null, 'error_message' => 'x'],
        // (1) publish onto deleting endpoint -> RESURRECTION hazard -> cancel.
        ['id' => 101, 'action' => 'publish', 'endpoint_id' => 2, 'status' => 'pending', 'lease_expires_at' => null, 'error_message' => null],
        // (2) destroy of already-deleted endpoint -> cancel (nothing to tear down).
        ['id' => 102, 'action' => 'destroy', 'endpoint_id' => 1, 'status' => 'pending', 'lease_expires_at' => null, 'error_message' => null],
        // KEEP: destroy of a 'deleting' endpoint is the FINALIZER -> must NOT cancel.
        ['id' => 103, 'action' => 'destroy', 'endpoint_id' => 2, 'status' => 'pending', 'lease_expires_at' => null, 'error_message' => null],
        // KEEP: destroy of the aliyun 'deleting' endpoint (finalizer) -> must NOT cancel.
        ['id' => 104, 'action' => 'destroy', 'endpoint_id' => 5, 'status' => 'pending', 'lease_expires_at' => null, 'error_message' => null],
        // KEEP: publish onto ACTIVE endpoint -> live work -> must NOT cancel.
        ['id' => 105, 'action' => 'publish', 'endpoint_id' => 3, 'status' => 'pending', 'lease_expires_at' => null, 'error_message' => null],
        // KEEP: the REAL fwd65 publish onto a PENDING endpoint -> live work -> must NOT cancel.
        ['id' => 106, 'action' => 'publish', 'endpoint_id' => 4, 'status' => 'pending', 'lease_expires_at' => null, 'error_message' => null],
        // (3) orphan FK: endpoint 9999 does not exist -> cancel.
        ['id' => 107, 'action' => 'publish', 'endpoint_id' => 9999, 'status' => 'pending', 'lease_expires_at' => null, 'error_message' => null],
        // KEEP: already-completed publish onto deleted endpoint -> not pending -> must NOT touch.
        ['id' => 108, 'action' => 'publish', 'endpoint_id' => 1, 'status' => 'completed', 'lease_expires_at' => null, 'error_message' => null],
    ]);

    $result = TaskCleanup::cancelObsoletePendingTasks($db, $now, false);

    $taskStatus = static function (Database $db, int $id): array {
        return $db->fetchOne('SELECT * FROM provision_tasks WHERE id = :id', ['id' => $id]) ?? [];
    };

    // Cancelled set: 100, 101, 102, 107.
    foreach ([100, 101, 102, 107] as $id) {
        $t = $taskStatus($db, $id);
        if (($t['status'] ?? '') !== 'completed') {
            fail("obsolete pending task {$id} must be cancelled to 'completed', got '" . ($t['status'] ?? '') . "'");
        }
        if (!str_contains((string) ($t['result_json'] ?? ''), 'obsolete_endpoint_terminal')) {
            fail("cancelled task {$id} must carry the 'obsolete_endpoint_terminal' note, got '" . ($t['result_json'] ?? '') . "'");
        }
        if (!array_key_exists('error_message', $t) || $t['error_message'] !== null) {
            fail("cancelled task {$id} must have error_message=NULL");
        }
        if (!array_key_exists('lease_expires_at', $t) || $t['lease_expires_at'] !== null) {
            fail("cancelled task {$id} must have lease_expires_at=NULL");
        }
    }

    // Kept set: 103, 104 (deleting-destroy finalizers), 105, 106 (live), 108 (terminal already).
    foreach ([103 => 'pending', 104 => 'pending', 105 => 'pending', 106 => 'pending', 108 => 'completed'] as $id => $expected) {
        $t = $taskStatus($db, $id);
        if (($t['status'] ?? '') !== $expected) {
            fail("task {$id} must remain '{$expected}', got '" . ($t['status'] ?? '') . "'");
        }
    }

    if (($result['cancelled'] ?? -1) !== 4) {
        fail('expected exactly 4 cancelled tasks, got ' . json_encode($result));
    }

    // Idempotent: a second run cancels nothing more.
    $second = TaskCleanup::cancelObsoletePendingTasks($db, $now, false);
    if (($second['cancelled'] ?? -1) !== 0) {
        fail('second run must be idempotent (0 cancellations), got ' . json_encode($second));
    }

    // Dry run must not mutate.
    $db2 = new Database();
    $db2->seed('service_endpoints', [
        ['id' => 1, 'forward_id' => 65, 'node_id' => 2, 'health_status' => 'deleted'],
    ]);
    $db2->seed('provision_tasks', [
        ['id' => 200, 'action' => 'publish', 'endpoint_id' => 1, 'status' => 'pending', 'lease_expires_at' => null, 'error_message' => null],
    ]);
    $dry = TaskCleanup::cancelObsoletePendingTasks($db2, $now, true);
    if (($dry['cancelled'] ?? -1) !== 1) {
        fail('dry run must REPORT the count it WOULD cancel (1), got ' . json_encode($dry));
    }
    $dryTask = $db2->fetchOne('SELECT status FROM provision_tasks WHERE id = :id', ['id' => 200]);
    if (($dryTask['status'] ?? '') !== 'pending') {
        fail('dry run must not mutate tasks');
    }

    echo "ok\n";
}
