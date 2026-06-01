<?php
declare(strict_types=1);

// FIX H regression: flap-artifact 'deleting' endpoints accumulated a backlog (e.g. 1733 rows stuck
// deleting). TaskCleanup::finalizeStaleDeletingEndpoints() drains them conservatively:
//   (a) a 'deleting' endpoint that is a DUPLICATE for the same (forward_id,node_id) where a newer
//       active/pending endpoint exists -> finalize to 'deleted' (provably redundant), OR
//   (b) a 'deleting' endpoint whose most recent destroy provision_task is 'completed' -> 'deleted'.
// A lone 'deleting' endpoint whose destroy task is still 'pending' (or no sibling) must be LEFT.
// Dry-run aware. Modeled with an in-memory fake Database (no PDO driver in this PHP build),
// mirroring cleanup_dead_node_finalization_regression_test.php.

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

            // Sibling check: another active/pending endpoint for the same (forward_id,node_id).
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

            // Most recent destroy task for an endpoint.
            if (str_contains($normalized, 'FROM provision_tasks')
                && str_contains($normalized, "action = :action")
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
                    'status' => (string) ($matches[0]['status'] ?? ''),
                    'attempt_count' => (int) ($matches[0]['attempt_count'] ?? 0),
                    'result_json' => $matches[0]['result_json'] ?? null,
                ];
            }

            if (str_contains($normalized, 'FROM service_endpoints WHERE id = :id')) {
                return $this->findById('service_endpoints', (int) ($params['id'] ?? 0));
            }

            return null;
        }

        /** @param array<string, mixed> $params */
        public function fetchAll(string $sql, array $params = []): array
        {
            $normalized = $this->normalizeSql($sql);

            // All 'deleting' endpoints (candidate set).
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

    $db = new Database();
    $db->seed('service_endpoints', [
        // (a) Duplicate deleting endpoint (id=10) for forward 1 / node 1 with a sibling ACTIVE
        //     endpoint (id=11) -> provably redundant -> must finalize to 'deleted'.
        ['id' => 10, 'forward_id' => 1, 'node_id' => 1, 'health_status' => 'deleting'],
        ['id' => 11, 'forward_id' => 1, 'node_id' => 1, 'health_status' => 'active'],

        // (b) Lone deleting endpoint (id=20) whose most recent destroy task is COMPLETED -> finalize.
        ['id' => 20, 'forward_id' => 2, 'node_id' => 1, 'health_status' => 'deleting'],

        // Conservative: lone deleting endpoint (id=30) whose destroy task is still PENDING, no
        //     active/pending sibling -> must be LEFT 'deleting'.
        ['id' => 30, 'forward_id' => 3, 'node_id' => 1, 'health_status' => 'deleting'],

        // Conservative: lone deleting endpoint (id=40) with NO destroy task at all and no sibling
        //     -> must be LEFT 'deleting'.
        ['id' => 40, 'forward_id' => 4, 'node_id' => 1, 'health_status' => 'deleting'],

        // (c) lone deleting endpoint (id=60) whose latest destroy is a COLLAPSE SUPERSESSION
        //     ('completed' but attempt_count=0 + superseded note) -> the slave never ran teardown
        //     -> must be LEFT 'deleting' (hardened guard (b)).
        ['id' => 60, 'forward_id' => 6, 'node_id' => 1, 'health_status' => 'deleting'],
    ]);
    $db->seed('provision_tasks', [
        // genuine agent-executed destroy completion (attempt_count > 0, no supersession)
        ['id' => 200, 'endpoint_id' => 20, 'action' => 'destroy', 'status' => 'completed', 'attempt_count' => 2, 'result_json' => null],
        // older pending destroy that must be ignored in favor of the newer completed one
        ['id' => 100, 'endpoint_id' => 20, 'action' => 'destroy', 'status' => 'pending', 'attempt_count' => 0],
        ['id' => 300, 'endpoint_id' => 30, 'action' => 'destroy', 'status' => 'pending', 'attempt_count' => 0],
        // collapse tombstone: 'completed' but never actually run by the slave -> must NOT finalize
        ['id' => 600, 'endpoint_id' => 60, 'action' => 'destroy', 'status' => 'completed', 'attempt_count' => 0, 'result_json' => '{"note":"superseded_by_new_publish_task"}'],
    ]);

    if (!method_exists(TaskCleanup::class, 'finalizeStaleDeletingEndpoints')) {
        fail('TaskCleanup must expose finalizeStaleDeletingEndpoints()');
    }

    $result = TaskCleanup::finalizeStaleDeletingEndpoints($db, $now, false);

    $status = static function (Database $db, int $id): string {
        $row = $db->fetchOne('SELECT health_status FROM service_endpoints WHERE id = :id', ['id' => $id]);
        return (string) ($row['health_status'] ?? '');
    };

    // (a) duplicate finalized
    if ($status($db, 10) !== 'deleted') {
        fail("duplicate deleting endpoint with active sibling must be finalized to 'deleted', got '" . $status($db, 10) . "'");
    }
    // sibling untouched
    if ($status($db, 11) !== 'active') {
        fail("active sibling must remain 'active', got '" . $status($db, 11) . "'");
    }
    // (b) completed-destroy finalized
    if ($status($db, 20) !== 'deleted') {
        fail("deleting endpoint with completed destroy task must be finalized, got '" . $status($db, 20) . "'");
    }
    // conservative: pending destroy left alone
    if ($status($db, 30) !== 'deleting') {
        fail("lone deleting endpoint with pending destroy must NOT be finalized, got '" . $status($db, 30) . "'");
    }
    // conservative: no destroy task + no sibling left alone
    if ($status($db, 40) !== 'deleting') {
        fail("lone deleting endpoint with no destroy task must NOT be finalized, got '" . $status($db, 40) . "'");
    }
    // (c) collapse-superseded 'completed' destroy must NOT finalize (teardown never ran)
    if ($status($db, 60) !== 'deleting') {
        fail("deleting endpoint with a SUPERSEDED (collapse) destroy must NOT be finalized, got '" . $status($db, 60) . "'");
    }

    if (($result['deleted'] ?? -1) !== 2) {
        fail('expected exactly 2 finalized endpoints, got ' . json_encode($result));
    }

    // Dry run must not mutate.
    $db2 = new Database();
    $db2->seed('service_endpoints', [
        ['id' => 50, 'forward_id' => 9, 'node_id' => 2, 'health_status' => 'deleting'],
        ['id' => 51, 'forward_id' => 9, 'node_id' => 2, 'health_status' => 'pending'],
    ]);
    $db2->seed('provision_tasks', []);
    $dry = TaskCleanup::finalizeStaleDeletingEndpoints($db2, $now, true);
    if (($dry['deleted'] ?? -1) !== 0) {
        fail('dry run must report 0 finalized');
    }
    $dryStatus = $db2->fetchOne('SELECT health_status FROM service_endpoints WHERE id = :id', ['id' => 50]);
    if (($dryStatus['health_status'] ?? '') !== 'deleting') {
        fail('dry run must not mutate endpoints');
    }

    echo "ok\n";
}
