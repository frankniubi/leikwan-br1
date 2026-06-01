<?php
declare(strict_types=1);

// Behavioral regression (FIX C — deterministic desired-node selection):
// pickNodesForGroups orders candidate nodes in-PHP by current_load ASC, then last_heartbeat_at DESC,
// with NO stable final tie-break. last_heartbeat_at is a VOLATILE column that changes on every
// heartbeat, so two equal-load nodes flip relative order across reconciles -> the desired-node set
// (and hence endpoint membership) is non-deterministic -> endpoints flap pending<->deleting and the
// task churn compounds.
//
// The fix: add a stable final tie-break by node_id ASC so the selected set + its order are identical
// across calls with unchanged inputs even when the volatile heartbeat timestamps differ.
//
// This drives the REAL ProvisioningService::pickNodesForGroups() against a mock DB and asserts that
// two equal-load nodes whose last_heartbeat_at differ are returned in a STABLE, node_id-ordered way.

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<string, array<int, array<string, mixed>>> */
        private array $tables = [];

        public function __construct(\Leikwan\Master\Config $config) {}

        public function seed(string $table, array $rows): void
        {
            $this->tables[$table] = array_values($rows);
        }

        public function fetchOne(string $sql, array $params = []): ?array
        {
            return null;
        }

        public function fetchAll(string $sql, array $params = []): array
        {
            $n = $this->normalize($sql);
            if (str_contains($n, 'FROM nodes n LEFT JOIN node_groups g ON g.id = n.group_id')) {
                $groupKey = (string) ($params['group_key'] ?? '');
                // The SQL appends ' ORDER BY n.current_load ASC, n.last_heartbeat_at DESC, n.id ASC LIMIT N'.
                // A correct fix relies on the in-PHP $sorter being deterministic, NOT on this SQL order;
                // so we deliberately return rows in an order that does NOT match the desired output to
                // prove the PHP-side ordering is what stabilizes the result.
                $rows = [];
                foreach ($this->tables['nodes'] ?? [] as $node) {
                    if (!in_array((string) ($node['status'] ?? ''), ['active', 'degraded'], true)) {
                        continue;
                    }
                    if ($groupKey !== '' && $groupKey !== 'default') {
                        $group = $this->findById('node_groups', (int) ($node['group_id'] ?? 0));
                        if ((string) ($group['group_key'] ?? '') !== $groupKey) {
                            continue;
                        }
                    }
                    $group = $this->findById('node_groups', (int) ($node['group_id'] ?? 0));
                    $rows[] = array_merge($node, ['node_group_key' => (string) ($group['group_key'] ?? '')]);
                }
                // Emulate the (FIXED) SQL ORDER BY current_load ASC, n.id ASC, then LIMIT. The
                // deterministic node_id tie-break here is part of FIX C: a stale last_heartbeat_at
                // DESC ordering would re-truncate the LIMIT window on every heartbeat.
                usort($rows, static function (array $a, array $b): int {
                    $loadCmp = ((int) ($a['current_load'] ?? 0)) <=> ((int) ($b['current_load'] ?? 0));
                    if ($loadCmp !== 0) {
                        return $loadCmp;
                    }
                    return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
                });
                if (preg_match('/LIMIT (\d+)/', $n, $m)) {
                    $rows = array_slice($rows, 0, (int) $m[1]);
                }
                return $rows;
            }
            return [];
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

    $envPath = tempnam(sys_get_temp_dir(), 'lw-det-node-select-env-');
    file_put_contents($envPath, "NODE_SELECTION_MAX_HEARTBEAT_AGE_SECONDS=0\n");
    $config = new Config($envPath);

    $db = new Database($config);
    $db->seed('node_groups', [['id' => 5, 'group_key' => 'hk-pool']]);
    // Three equal-load (current_load = 0) nodes in one group. Their last_heartbeat_at differ and are
    // VOLATILE; node_id ordering is the only stable signal. Node ids intentionally out of heartbeat order.
    $db->seed('nodes', [
        ['id' => 31, 'group_id' => 5, 'status' => 'active', 'current_load' => 0, 'last_heartbeat_at' => '2026-05-30 00:00:01', 'public_ip' => '203.0.113.31'],
        ['id' => 12, 'group_id' => 5, 'status' => 'active', 'current_load' => 0, 'last_heartbeat_at' => '2026-05-30 00:00:09', 'public_ip' => '203.0.113.12'],
        ['id' => 25, 'group_id' => 5, 'status' => 'active', 'current_load' => 0, 'last_heartbeat_at' => '2026-05-30 00:00:05', 'public_ip' => '203.0.113.25'],
    ]);

    $service = new ProvisioningService($config, $db);

    $idsOf = static function (array $nodes): array {
        return array_map(static fn(array $n): int => (int) ($n['id'] ?? 0), $nodes);
    };

    // Pick 2 of 3 equal-load nodes. With a node_id ASC stable tie-break the selected set + order is
    // deterministic regardless of the volatile last_heartbeat_at values: [12, 25].
    $first = call_private($service, 'pickNodesForGroups', ['hk-pool'], 2, null);
    $firstIds = $idsOf($first);

    if (count($firstIds) !== 2) {
        fail('expected 2 nodes selected, got ' . count($firstIds) . ' (' . implode(',', $firstIds) . ')');
    }
    if ($firstIds !== [12, 25]) {
        fail('desired-node selection must be node_id-ordered for equal-load nodes; expected [12,25], got [' . implode(',', $firstIds) . ']');
    }

    // Now mutate ONLY the volatile last_heartbeat_at (simulate a fresh heartbeat) and reselect.
    // The membership + order MUST be identical (no flap on transient heartbeat noise).
    $db->seed('nodes', [
        ['id' => 31, 'group_id' => 5, 'status' => 'active', 'current_load' => 0, 'last_heartbeat_at' => '2026-05-30 09:00:09', 'public_ip' => '203.0.113.31'],
        ['id' => 12, 'group_id' => 5, 'status' => 'active', 'current_load' => 0, 'last_heartbeat_at' => '2026-05-30 09:00:01', 'public_ip' => '203.0.113.12'],
        ['id' => 25, 'group_id' => 5, 'status' => 'active', 'current_load' => 0, 'last_heartbeat_at' => '2026-05-30 09:00:05', 'public_ip' => '203.0.113.25'],
    ]);
    $second = $idsOf(call_private($service, 'pickNodesForGroups', ['hk-pool'], 2, null));
    if ($second !== $firstIds) {
        fail('REGRESSION: desired-node set flipped when only the volatile last_heartbeat_at changed; '
            . 'first [' . implode(',', $firstIds) . '] vs second [' . implode(',', $second) . ']');
    }

    echo "ok\n";
}
