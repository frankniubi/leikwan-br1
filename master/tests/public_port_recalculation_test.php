<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {

    final class Database
    {
        /** @var array<string, array<int, array<string, mixed>>> */
        private array $tables = [];

        /** @var array<string, int> */
        private array $autoIds = [
            'service_endpoints' => 1000,
            'provision_tasks' => 2000,
        ];

        /**
         * @param array<int, array<string, mixed>> $rows
         */
        public function seed(string $table, array $rows): void
        {
            $this->tables[$table] = array_values($rows);
            $maxId = 0;
            foreach ($rows as $row) {
                $maxId = max($maxId, (int) ($row['id'] ?? 0));
            }
            if ($maxId > 0) {
                $this->autoIds[$table] = $maxId + 1;
            }
        }

        /**
         * @param array<string, mixed> $params
         * @return array<int, array<string, mixed>>
         */
        public function fetchAll(string $sql, array $params = []): array
        {
            $normalized = $this->normalizeSql($sql);

            if (str_contains($normalized, 'FROM provision_tasks')) {
                return array_values($this->rows('provision_tasks'));
            }

            if (str_contains($normalized, 'SELECT f.*, s.status AS service_status FROM forwards f INNER JOIN services s ON s.id = f.service_id WHERE f.enabled = 1')) {
                $rows = [];
                foreach ($this->rows('forwards') as $forward) {
                    if ((int) ($forward['enabled'] ?? 0) !== 1) {
                        continue;
                    }
                    $service = $this->findById('services', (int) ($forward['service_id'] ?? 0));
                    if ($service === null || (string) ($service['status'] ?? '') !== (string) ($params['service_status'] ?? '')) {
                        continue;
                    }
                    $rows[] = array_merge($forward, [
                        'service_status' => (string) ($service['status'] ?? ''),
                    ]);
                }
                usort($rows, static fn(array $left, array $right): int => ((int) $left['id']) <=> ((int) $right['id']));
                return $rows;
            }

            if (str_contains($normalized, 'SELECT * FROM service_endpoints')) {
                $forwardId = (int) ($params['forward_id'] ?? 0);
                $deleted = (string) ($params['deleted'] ?? '');
                $deleting = (string) ($params['deleting'] ?? '');
                $rows = array_values(array_filter($this->rows('service_endpoints'), static function (array $row) use ($forwardId, $deleted, $deleting): bool {
                    if ((int) ($row['forward_id'] ?? 0) !== $forwardId) {
                        return false;
                    }
                    $health = (string) ($row['health_status'] ?? '');
                    return $health !== $deleted && $health !== $deleting;
                }));
                usort($rows, static fn(array $left, array $right): int => [((int) $left['priority']), ((int) $left['id'])] <=> [((int) $right['priority']), ((int) $right['id'])]);
                return $rows;
            }

            if (str_contains($normalized, 'SELECT n.* FROM nodes n LEFT JOIN node_groups g ON g.id = n.group_id')) {
                $rows = [];
                foreach ($this->rows('nodes') as $node) {
                    $status = (string) ($node['status'] ?? '');
                    if (!in_array($status, [(string) ($params['active'] ?? 'active'), (string) ($params['degraded'] ?? 'degraded')], true)) {
                        continue;
                    }
                    $groupId = (int) ($node['group_id'] ?? 0);
                    foreach ($this->rows('node_groups') as $group) {
                        if ((int) ($group['id'] ?? 0) !== $groupId) {
                            continue;
                        }
                        if ((string) ($params['group_key'] ?? '') !== '' && (string) ($group['group_key'] ?? '') !== (string) $params['group_key']) {
                            continue 2;
                        }
                    }
                    $rows[] = $node;
                }
                usort($rows, static fn(array $left, array $right): int => ((int) $left['id']) <=> ((int) $right['id']));
                return $rows;
            }

            if (str_contains($normalized, 'SELECT c.*, g.name AS group_name, g.group_key, fc.name AS default_forward_chain_name, u.email AS owner_email, u.name AS owner_name FROM clients c')) {
                return array_values($this->rows('clients'));
            }

            if (str_contains($normalized, 'SELECT listen_port, public_port FROM forwards WHERE line_client_id = :client_id')) {
                $clientId = (int) ($params['client_id'] ?? 0);
                $excludeId = (int) ($params['exclude_id'] ?? 0);
                return array_values(array_filter($this->rows('forwards'), static function (array $row) use ($clientId, $excludeId): bool {
                    if ((int) ($row['line_client_id'] ?? 0) !== $clientId) {
                        return false;
                    }
                    if ((int) ($row['enabled'] ?? 0) !== 1) {
                        return false;
                    }
                    if ($excludeId > 0 && (int) ($row['id'] ?? 0) === $excludeId) {
                        return false;
                    }
                    return true;
                }));
            }

            return [];
        }

        /**
         * @param array<string, mixed> $params
         * @return array<string, mixed>|null
         */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            $normalized = $this->normalizeSql($sql);

            if (str_contains($normalized, 'SELECT * FROM node_groups WHERE id = :id LIMIT 1')) {
                return $this->findById('node_groups', (int) ($params['id'] ?? 0));
            }

            if (str_contains($normalized, 'SELECT * FROM node_groups WHERE group_key = :group_key LIMIT 1')) {
                foreach ($this->rows('node_groups') as $group) {
                    if ((string) ($group['group_key'] ?? '') === (string) ($params['group_key'] ?? '')) {
                        return $group;
                    }
                }
                return null;
            }

            if (str_contains($normalized, 'SELECT * FROM clients WHERE id = :id LIMIT 1')) {
                return $this->findById('clients', (int) ($params['id'] ?? 0));
            }

            if (str_contains($normalized, 'SELECT * FROM services WHERE id = :id LIMIT 1')) {
                return $this->findById('services', (int) ($params['id'] ?? 0));
            }

            if (str_contains($normalized, 'SELECT * FROM forwards WHERE id = :id LIMIT 1')) {
                return $this->findById('forwards', (int) ($params['id'] ?? 0));
            }

            if (str_contains($normalized, 'SELECT f.*, s.status AS service_status FROM forwards f INNER JOIN services s ON s.id = f.service_id WHERE f.id = :id LIMIT 1')) {
                $forward = $this->findById('forwards', (int) ($params['id'] ?? 0));
                if ($forward === null) {
                    return null;
                }
                $service = $this->findById('services', (int) ($forward['service_id'] ?? 0));
                if ($service === null) {
                    return null;
                }
                return array_merge($forward, [
                    'service_status' => (string) ($service['status'] ?? ''),
                ]);
            }

            if (str_contains($normalized, 'SELECT COUNT(*) AS total FROM nodes n LEFT JOIN node_groups g ON g.id = n.group_id')) {
                return ['total' => count($this->fetchAll($sql, $params))];
            }

            return null;
        }

        /**
         * @param array<string, mixed> $data
         */
        public function insert(string $table, array $data): int
        {
            $id = isset($data['id']) ? (int) $data['id'] : ($this->autoIds[$table] ?? 1);
            $data['id'] = $id;
            $this->tables[$table][] = $data;
            $this->autoIds[$table] = $id + 1;
            return $id;
        }

        /**
         * @param array<string, mixed> $params
         */
        public function execute(string $sql, array $params = []): int
        {
            $normalized = $this->normalizeSql($sql);

            if (str_contains($normalized, 'UPDATE service_endpoints SET health_status = :status, updated_at = :updated_at WHERE id = :id')) {
                if (!isset($this->tables['service_endpoints'])) {
                    return 0;
                }
                foreach ($this->tables['service_endpoints'] as &$row) {
                    if ((int) ($row['id'] ?? 0) === (int) ($params['id'] ?? 0)) {
                        $row['health_status'] = $params['status'] ?? $row['health_status'] ?? '';
                        $row['updated_at'] = $params['updated_at'] ?? $row['updated_at'] ?? '';
                        return 1;
                    }
                }
            }

            if (str_contains($normalized, 'UPDATE forwards SET public_port = :public_port, updated_at = :updated_at WHERE id = :id')) {
                if (!isset($this->tables['forwards'])) {
                    return 0;
                }
                foreach ($this->tables['forwards'] as &$row) {
                    if ((int) ($row['id'] ?? 0) === (int) ($params['id'] ?? 0)) {
                        $row['public_port'] = (int) ($params['public_port'] ?? $row['public_port'] ?? 0);
                        $row['updated_at'] = $params['updated_at'] ?? $row['updated_at'] ?? '';
                        return 1;
                    }
                }
            }

            return 0;
        }

        /**
         * @return mixed
         */
        public function transaction(callable $callback)
        {
            return $callback($this);
        }

        /**
         * @return array<int, array<string, mixed>>
         */
        private function rows(string $table): array
        {
            return array_values($this->tables[$table] ?? []);
        }

        /**
         * @return array<string, mixed>|null
         */
        private function findById(string $table, int $id): ?array
        {
            foreach ($this->rows($table) as $row) {
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
        if ($relative === false) {
            return;
        }

        $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });

    if (!class_exists('Leikwan\\Master\\Exceptions\\ApiException') && class_exists('Leikwan\\Master\\Support\\ApiException')) {
        class_alias('Leikwan\\Master\\Support\\ApiException', 'Leikwan\\Master\\Exceptions\\ApiException');
    }

    use Leikwan\Master\Config;
    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Support\ProvisioningService;

    function fail(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    $envPath = tempnam(sys_get_temp_dir(), 'lw-port-recalc-env-');
    if ($envPath === false) {
        fail('failed to create temp env file');
    }
    file_put_contents($envPath, implode("\n", [
        'DEFAULT_EDGE_PUBLIC_PORT=443',
        'EDGE_PORT_STRATEGY=client_range_then_target',
    ]) . "\n");

    $config = new Config($envPath);
    $db = new Database();
    $db->seed('services', [[
        'id' => 1,
        'user_id' => 100,
        'plan_id' => 0,
        'status' => 'active',
    ]]);
    $db->seed('node_groups', [[
        'id' => 3,
        'group_key' => 'canton-ali',
        'ingress_port_strategy' => 'shared_preferred_port',
        'ingress_preferred_public_port' => 7000,
    ]]);
    $db->seed('clients', [[
        'id' => 1,
        'owner_user_id' => 100,
        'group_id' => 5,
        'status' => 'active',
        'port_range_start' => 10000,
        'port_range_end' => 15000,
        'updated_at' => '2026-04-15 00:00:00',
    ]]);
    $db->seed('nodes', [[
        'id' => 6,
        'group_id' => 3,
        'name' => 'edge-1',
        'public_ip' => '8.8.8.8',
        'status' => 'active',
        'current_load' => 0,
        'last_heartbeat_at' => '2026-04-15 00:00:00',
    ]]);
    $db->seed('forwards', [[
        'id' => 22,
        'service_id' => 1,
        'enabled' => 1,
        'client_mode' => 'bond',
        'node_group_key' => 'canton-ali',
        'line_client_id' => 1,
        'line_client_group_id' => null,
        'line_client_group_ids_json' => null,
        'public_port' => 10000,
        'listen_port' => 11027,
        'target_host' => '193.227.109.82',
        'target_port' => 5201,
        'path_prefix' => '67bfc8797eec92a4',
        'sni_host' => 'br1.nbwly.com',
        'cdn_host' => 'br1.nbwly.com',
        'filter_profile' => 'off',
        'transport_mode' => 'tcp+udp',
        'endpoint_mode' => 'slave_group',
        'bond_remote_host' => 'sidecar.example.com',
        'bond_remote_port' => 8443,
        'bond_sidecar_point_id' => null,
        'updated_at' => '2026-04-15 00:00:00',
    ]]);
    $db->seed('service_endpoints', [[
        'id' => 129,
        'forward_id' => 22,
        'node_id' => 6,
        'public_host' => '8.8.8.8',
        'public_ip' => '8.8.8.8',
        'public_port' => 10000,
        'cdn_host' => 'br1.nbwly.com',
        'priority' => 10,
        'weight' => 100,
        'health_status' => 'active',
        'last_error' => null,
        'created_at' => '2026-04-15 00:00:00',
        'updated_at' => '2026-04-15 00:00:00',
    ]]);
    $db->seed('provision_tasks', []);

    $service = new ProvisioningService($config, $db);
    $changed = $service->recalculatePublicPortsForGroup('canton-ali');
    if ($changed !== 1) {
        fail('expected 1 recalculated forward, got ' . $changed);
    }

    $forward = $db->fetchOne('SELECT * FROM forwards WHERE id = :id LIMIT 1', ['id' => 22]);
    if (!is_array($forward) || (int) ($forward['public_port'] ?? 0) !== 7000) {
        fail('expected forward public_port to be recalculated to 7000, got ' . var_export($forward['public_port'] ?? null, true));
    }

    $endpointRows = $db->fetchAll('SELECT * FROM service_endpoints WHERE forward_id = :forward_id ORDER BY priority ASC, id ASC', [
        'forward_id' => 22,
        'deleted' => 'deleted',
        'deleting' => 'deleting',
    ]);
    if (count($endpointRows) !== 1 || (int) ($endpointRows[0]['public_port'] ?? 0) !== 7000) {
        fail('expected one newly published endpoint on public_port 7000');
    }

    $allTasks = $db->fetchAll('SELECT * FROM provision_tasks ORDER BY id ASC');
    if (count($allTasks) !== 2) {
        fail('expected destroy + publish tasks, got ' . count($allTasks));
    }

    $destroy = null;
    $publish = null;
    foreach ($allTasks as $task) {
        if (($task['action'] ?? '') === 'destroy') {
            $destroy = $task;
        }
        if (($task['action'] ?? '') === 'publish') {
            $publish = $task;
        }
    }
    if (!is_array($destroy) || !is_array($publish)) {
        fail('expected both destroy and publish tasks after recalculation');
    }
    $destroyPayload = json_decode((string) ($destroy['payload_json'] ?? ''), true);
    $publishPayload = json_decode((string) ($publish['payload_json'] ?? ''), true);
    if (($destroyPayload['listen_port'] ?? null) !== 10000) {
        fail('expected destroy task to clean old public_port 10000');
    }
    if (($publishPayload['listen_port'] ?? null) !== 7000) {
        fail('expected publish task to use new public_port 7000');
    }

    echo "ok\n";
}
