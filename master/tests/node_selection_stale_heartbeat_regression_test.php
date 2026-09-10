<?php
declare(strict_types=1);

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
            return null;
        }

        /** @param array<string, mixed> $params */
        public function fetchAll(string $sql, array $params = []): array
        {
            $normalized = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
            if (!str_contains($normalized, 'FROM nodes n')) {
                return [];
            }

            $rows = [];
            foreach ($this->tables['nodes'] ?? [] as $node) {
                $status = (string) ($node['status'] ?? '');
                if (!in_array($status, ['active', 'degraded'], true)) {
                    continue;
                }
                $group = $this->findById('node_groups', (int) ($node['group_id'] ?? 0)) ?? [];
                if (isset($params['group_key']) && (string) ($group['group_key'] ?? '') !== (string) $params['group_key']) {
                    continue;
                }
                if (isset($params['node_heartbeat_cutoff'])) {
                    $heartbeat = (string) ($node['last_heartbeat_at'] ?? '');
                    if ($heartbeat === '' || strcmp($heartbeat, (string) $params['node_heartbeat_cutoff']) < 0) {
                        continue;
                    }
                }
                $rows[] = array_merge($node, ['node_group_key' => (string) ($group['group_key'] ?? '')]);
            }

            usort($rows, static function (array $left, array $right): int {
                $loadCmp = ((int) ($left['current_load'] ?? 0)) <=> ((int) ($right['current_load'] ?? 0));
                if ($loadCmp !== 0) {
                    return $loadCmp;
                }
                $heartbeatCmp = strcmp((string) ($right['last_heartbeat_at'] ?? ''), (string) ($left['last_heartbeat_at'] ?? ''));
                if ($heartbeatCmp !== 0) {
                    return $heartbeatCmp;
                }
                return ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
            });

            if (preg_match('/LIMIT\s+(\d+)/i', $normalized, $match) === 1) {
                return array_slice($rows, 0, (int) $match[1]);
            }
            return $rows;
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
    }
}

namespace {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Leikwan\\Master\\';
        if (!str_starts_with($class, $prefix) || $class === 'Leikwan\\Master\\Database\\Database') {
            return;
        }
        $relative = substr($class, strlen($prefix));
        if ($relative === false) {
            return;
        }
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

    $envPath = tempnam(sys_get_temp_dir(), 'lw-node-fresh-env-');
    if ($envPath === false) {
        fail('failed to create temp env');
    }
    file_put_contents($envPath, "NODE_SELECTION_MAX_HEARTBEAT_AGE_SECONDS=300\n");

    $db = new Database();
    $db->seed('node_groups', [['id' => 1, 'group_key' => 'sha-tencent']]);
    $db->seed('nodes', [
        [
            'id' => 3,
            'group_id' => 1,
            'name' => 'stale-low-load',
            'status' => 'active',
            'public_ip' => '49.235.160.246',
            'current_load' => 0,
            'last_heartbeat_at' => gmdate('Y-m-d H:i:s', time() - 3600),
        ],
        [
            'id' => 19,
            'group_id' => 1,
            'name' => 'fresh-low-load',
            'status' => 'active',
            'public_ip' => '111.229.217.7',
            'current_load' => 0,
            'last_heartbeat_at' => gmdate('Y-m-d H:i:s', time() - 30),
        ],
        [
            'id' => 20,
            'group_id' => 1,
            'name' => 'fresh-higher-load',
            'status' => 'active',
            'public_ip' => '150.158.103.182',
            'current_load' => 1,
            'last_heartbeat_at' => gmdate('Y-m-d H:i:s', time() - 30),
        ],
    ]);

    $service = new ProvisioningService(new Config($envPath), $db);
    $nodes = call_private($service, 'pickNodesForGroups', ['sha-tencent'], 2, null);
    $ids = array_values(array_map(static fn(array $node): int => (int) $node['id'], $nodes));
    if ($ids !== [19, 20]) {
        fail('expected stale heartbeat node to be excluded from slave selection, got ' . json_encode($ids));
    }

    echo "ok\n";
}
