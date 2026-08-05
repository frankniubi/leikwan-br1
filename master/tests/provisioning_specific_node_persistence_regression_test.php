<?php
declare(strict_types=1);

// Behavioral regression: a forward created with node_group="_user_node_<id>" must persist
// exact-node intent, not just endpoint_count=1 plus a normal group. Later reconcile must keep
// the requested node even when another node in the same group has lower load.

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<string, array<int, array<string, mixed>>> */
        private array $tables = [];

        /** @var array<string, int> */
        private array $autoIds = [
            'forwards' => 500,
            'service_endpoints' => 700,
            'provision_tasks' => 900,
            'audit_logs' => 1100,
        ];

        /** @var array<int, array<string, mixed>> */
        public array $availabilityRows = [];

        public function __construct(\Leikwan\Master\Config $config) {}

        public function seed(string $table, array $rows): void
        {
            $this->tables[$table] = array_values($rows);
        }

        public function fetchOne(string $sql, array $params = []): ?array
        {
            $n = $this->normalize($sql);

            if (str_contains($n, 'SELECT f.*, s.client_token, s.status AS service_status, s.name AS service_name,')) {
                $forward = $this->fetchOne('SELECT * FROM forwards WHERE id = :id LIMIT 1', $params);
                if ($forward === null) {
                    return null;
                }
                $service = $this->findById('services', (int) ($forward['service_id'] ?? 0));
                return array_merge($forward, [
                    'client_token' => (string) ($service['client_token'] ?? ''),
                    'service_status' => (string) ($service['status'] ?? ''),
                    'service_name' => (string) ($service['name'] ?? ''),
                    'service_bandwidth_limit_mbps' => (int) ($service['bandwidth_limit_mbps'] ?? 0),
                    'line_client_name' => null,
                    'line_client_host' => null,
                    'line_speed_limit_mbps' => 0,
                    'line_client_agent_config_json' => null,
                    'line_client_group_name' => null,
                    'forward_chain_name' => null,
                    'bond_sidecar_point_name' => '',
                    'forward_combination_name' => '',
                    'forward_combination_label' => '',
                    'forward_combination_runtime_preference' => 'single',
                    'bond_sidecar_host' => null,
                    'bond_sidecar_legacy_port' => null,
                    'bond_sidecar_port' => null,
                    'mptcp_sidecar_port' => null,
                    'bond_sidecar_cf_listen_port' => null,
                    'bond_sidecar_point_token_secret' => '',
                    'bond_sidecar_point_agent_config_json' => null,
                ]);
            }

            if (str_contains($n, 'SELECT f.*, s.status AS service_status FROM forwards f INNER JOIN services s ON s.id = f.service_id WHERE f.id = :id LIMIT 1')) {
                $forward = $this->fetchOne('SELECT * FROM forwards WHERE id = :id LIMIT 1', $params);
                if ($forward === null) {
                    return null;
                }
                $service = $this->findById('services', (int) ($forward['service_id'] ?? 0));
                return array_merge($forward, ['service_status' => (string) ($service['status'] ?? '')]);
            }

            if (str_contains($n, 'SELECT * FROM forwards WHERE id = :id LIMIT 1')) {
                return $this->findById('forwards', (int) ($params['id'] ?? 0));
            }

            if (str_contains($n, 'SELECT * FROM services WHERE id = :id LIMIT 1')) {
                return $this->findById('services', (int) ($params['id'] ?? 0));
            }

            if (str_contains($n, 'FROM sidecar_points sp') && str_contains($n, 'WHERE sp.id = :id')) {
                return $this->findById('sidecar_points', (int) ($params['id'] ?? 0));
            }

            if (str_contains($n, 'SELECT id FROM service_plan_forward_combinations WHERE plan_id = :plan_id LIMIT 1')) {
                foreach ($this->tables['service_plan_forward_combinations'] ?? [] as $row) {
                    if ((int) ($row['plan_id'] ?? 0) === (int) ($params['plan_id'] ?? 0)) {
                        return ['id' => (int) ($row['id'] ?? $row['forward_combination_id'] ?? 1)];
                    }
                }
                return null;
            }

            if (str_contains($n, 'SELECT id FROM service_plan_forward_routes WHERE plan_id = :plan_id LIMIT 1')) {
                foreach ($this->tables['service_plan_forward_routes'] ?? [] as $row) {
                    if ((int) ($row['plan_id'] ?? 0) === (int) ($params['plan_id'] ?? 0)) {
                        return ['id' => (int) ($row['id'] ?? $row['forward_route_id'] ?? 1)];
                    }
                }
                return null;
            }

            if (str_contains($n, 'SELECT forward_combination_id FROM forward_route_combinations')) {
                $rows = array_values(array_filter($this->tables['forward_route_combinations'] ?? [], static function (array $row) use ($params): bool {
                    return (int) ($row['forward_route_id'] ?? 0) === (int) ($params['id'] ?? 0)
                        && (int) ($row['enabled'] ?? 0) === 1;
                }));
                usort($rows, static fn(array $left, array $right): int => [((int) ($left['sort_order'] ?? 0)), ((int) ($left['id'] ?? 0))] <=> [((int) ($right['sort_order'] ?? 0)), ((int) ($right['id'] ?? 0))]);
                return $rows[0] ?? null;
            }

            if (str_contains($n, 'SELECT id FROM forward_route_combinations')) {
                foreach ($this->tables['forward_route_combinations'] ?? [] as $row) {
                    if ((int) ($row['forward_route_id'] ?? 0) === (int) ($params['forward_route_id'] ?? 0)
                        && (int) ($row['forward_combination_id'] ?? 0) === (int) ($params['forward_combination_id'] ?? 0)
                        && (int) ($row['enabled'] ?? 0) === 1) {
                        return ['id' => (int) ($row['id'] ?? 1)];
                    }
                }
                return null;
            }

            if (str_contains($n, 'SELECT * FROM node_groups WHERE group_key = :group_key LIMIT 1')) {
                foreach ($this->tables['node_groups'] ?? [] as $row) {
                    if ((string) ($row['group_key'] ?? '') === (string) ($params['group_key'] ?? '')) {
                        return $row;
                    }
                }
                return null;
            }

            if (str_contains($n, 'FROM forward_combinations fc')) {
                foreach ($this->tables['forward_combinations'] ?? [] as $row) {
                    if ((int) ($row['id'] ?? 0) === (int) ($params['id'] ?? $params['forward_combination_id'] ?? 0)) {
                        return $this->withSidecar($row);
                    }
                }
                return null;
            }

            if (str_contains($n, 'SELECT n.*, g.group_key AS node_group_key FROM nodes n LEFT JOIN node_groups g ON g.id = n.group_id WHERE n.id = :id')) {
                $node = $this->findById('nodes', (int) ($params['id'] ?? 0));
                if ($node === null || !in_array((string) ($node['status'] ?? ''), [(string) ($params['active'] ?? 'active'), (string) ($params['degraded'] ?? 'degraded')], true)) {
                    return null;
                }
                if (array_key_exists('owner_service_id', $params) && !$this->nodeVisibleToService($node, (int) ($params['owner_service_id'] ?? 0))) {
                    return null;
                }
                $group = $this->findById('node_groups', (int) ($node['group_id'] ?? 0));
                return array_merge($node, ['node_group_key' => (string) ($group['group_key'] ?? '')]);
            }

            if (str_contains($n, 'SELECT COUNT(*) AS total FROM nodes n LEFT JOIN node_groups g ON g.id = n.group_id')) {
                return ['total' => count($this->matchingNodes($params, $n))];
            }

            if (str_contains($n, 'SELECT COUNT(*) AS viable FROM provision_tasks')) {
                return ['viable' => 0];
            }

            if (str_contains($n, 'FROM app_settings WHERE setting_key = :setting_key')) {
                return null;
            }

            return null;
        }

        public function fetchAll(string $sql, array $params = []): array
        {
            $n = $this->normalize($sql);

            if (str_contains($n, 'FROM service_endpoints e INNER JOIN nodes n ON n.id = e.node_id')) {
                $rows = [];
                foreach ($this->activeEndpoints((int) ($params['forward_id'] ?? 0), (string) ($params['deleted'] ?? 'deleted'), (string) ($params['deleting'] ?? 'deleting')) as $endpoint) {
                    $node = $this->findById('nodes', (int) ($endpoint['node_id'] ?? 0));
                    if ($node === null) {
                        continue;
                    }
                    $rows[] = array_merge($endpoint, [
                        'node_name' => (string) ($node['name'] ?? ''),
                        'node_public_ip' => (string) ($node['public_ip'] ?? ''),
                        'node_agent_config_json' => $node['agent_config_json'] ?? null,
                        'node_agent_version' => $node['agent_version'] ?? null,
                    ]);
                }
                return $rows;
            }

            if (str_contains($n, 'node_leigod_line_health')) {
                return $this->availabilityRows;
            }

            if (str_contains($n, 'FROM service_endpoints WHERE forward_id = :forward_id')) {
                return $this->activeEndpoints((int) ($params['forward_id'] ?? 0), (string) ($params['deleted'] ?? 'deleted'), (string) ($params['deleting'] ?? 'deleting'));
            }

            if (str_contains($n, 'FROM nodes n LEFT JOIN node_groups g ON g.id = n.group_id')) {
                return $this->matchingNodes($params, $n);
            }

            if (str_contains($n, 'FROM ( SELECT fc.node_group_key')) {
                $planId = (int) ($params['plan_id_primary'] ?? $params['plan_id_helper'] ?? $params['plan_id_pinned'] ?? $params['plan_id'] ?? 0);
                $keys = [];
                foreach ($this->tables['service_plan_forward_combinations'] ?? [] as $planCombo) {
                    if ((int) ($planCombo['plan_id'] ?? 0) !== $planId) {
                        continue;
                    }
                    $comboId = (int) ($planCombo['forward_combination_id'] ?? 0);
                    $combo = $this->findById('forward_combinations', $comboId);
                    if ($combo !== null) {
                        $key = trim((string) ($combo['node_group_key'] ?? ''));
                        if ($key !== '') {
                            $keys[$key] = true;
                        }
                    }
                    foreach ($this->tables['forward_combination_helper_groups'] ?? [] as $helper) {
                        if ((int) ($helper['forward_combination_id'] ?? 0) === $comboId && (int) ($helper['is_active'] ?? 0) === 1) {
                            $key = trim((string) ($helper['node_group_key'] ?? ''));
                            if ($key !== '') {
                                $keys[$key] = true;
                            }
                        }
                    }
                    foreach ($this->tables['forward_combination_slaves'] ?? [] as $slave) {
                        if ((int) ($slave['forward_combination_id'] ?? 0) !== $comboId || (int) ($slave['is_active'] ?? 0) !== 1) {
                            continue;
                        }
                        $node = $this->findById('nodes', (int) ($slave['node_id'] ?? 0));
                        $group = $node !== null ? $this->findById('node_groups', (int) ($node['group_id'] ?? 0)) : null;
                        $key = trim((string) ($group['group_key'] ?? ''));
                        if ($key !== '') {
                            $keys[$key] = true;
                        }
                    }
                }
                return $this->nodeGroupsByKeys(array_keys($keys));
            }

            if (str_contains($n, 'FROM forward_combinations fc')) {
                $rows = [];
                foreach ($this->tables['forward_combinations'] ?? [] as $row) {
                    if ((int) ($row['is_active'] ?? 0) === 1) {
                        $rows[] = $this->withSidecar($row);
                    }
                }
                return $rows;
            }

            if (str_contains($n, 'FROM forward_routes fr INNER JOIN service_plan_forward_routes')) {
                $planId = (int) ($params['plan_id'] ?? 0);
                $allowedRouteIds = [];
                foreach ($this->tables['service_plan_forward_routes'] ?? [] as $row) {
                    if ((int) ($row['plan_id'] ?? 0) === $planId) {
                        $allowedRouteIds[(int) ($row['forward_route_id'] ?? 0)] = true;
                    }
                }
                $rows = [];
                foreach ($this->tables['forward_routes'] ?? [] as $route) {
                    $routeId = (int) ($route['id'] ?? 0);
                    if (isset($allowedRouteIds[$routeId]) && (int) ($route['is_active'] ?? 0) === 1) {
                        $rows[] = $route;
                    }
                }
                usort($rows, static fn(array $left, array $right): int => [((int) ($left['sort_order'] ?? 0)), ((int) ($left['id'] ?? 0))] <=> [((int) ($right['sort_order'] ?? 0)), ((int) ($right['id'] ?? 0))]);
                return $rows;
            }

            if (str_contains($n, 'SELECT * FROM forward_routes WHERE is_active = 1')) {
                $rows = array_values(array_filter($this->tables['forward_routes'] ?? [], static fn(array $route): bool => (int) ($route['is_active'] ?? 0) === 1));
                usort($rows, static fn(array $left, array $right): int => [((int) ($left['sort_order'] ?? 0)), ((int) ($left['id'] ?? 0))] <=> [((int) ($right['sort_order'] ?? 0)), ((int) ($right['id'] ?? 0))]);
                return $rows;
            }

            if (str_contains($n, 'FROM forward_combination_helper_groups')) {
                return [];
            }

            if (str_contains($n, 'FROM clients c')) {
                return [];
            }

            if (str_contains($n, 'FROM client_groups g')) {
                return [];
            }

            if (str_contains($n, 'FROM node_groups g')) {
                $rows = [];
                foreach ($this->tables['node_groups'] ?? [] as $group) {
                    $count = 0;
                    foreach ($this->tables['nodes'] ?? [] as $node) {
                        if ((int) ($node['group_id'] ?? 0) === (int) ($group['id'] ?? 0) && in_array((string) ($node['status'] ?? ''), ['active', 'degraded'], true)) {
                            $count++;
                        }
                    }
                    $rows[] = array_merge($group, ['node_count' => $count]);
                }
                return $rows;
            }

            if (str_contains($n, 'FROM provision_tasks')) {
                return $this->tables['provision_tasks'] ?? [];
            }

            return [];
        }

        public function insert(string $table, array $data): int
        {
            $id = $this->autoIds[$table] ?? 1;
            $data['id'] = $id;
            $this->tables[$table][] = $data;
            $this->autoIds[$table] = $id + 1;
            return $id;
        }

        public function execute(string $sql, array $params = []): int
        {
            $n = $this->normalize($sql);
            if (str_contains($n, 'UPDATE service_endpoints SET health_status = :status, updated_at = :updated_at WHERE id = :id')) {
                foreach ($this->tables['service_endpoints'] as &$row) {
                    if ((int) ($row['id'] ?? 0) === (int) ($params['id'] ?? 0)) {
                        $row['health_status'] = (string) ($params['status'] ?? $row['health_status'] ?? '');
                        return 1;
                    }
                }
            }
            if (str_contains($n, 'UPDATE forwards SET')) {
                foreach ($this->tables['forwards'] as &$row) {
                    if ((int) ($row['id'] ?? 0) === (int) ($params['id'] ?? 0)) {
                        foreach ($params as $key => $value) {
                            if ($key === 'id') {
                                continue;
                            }
                            $row[$key] = $value;
                        }
                        return 1;
                    }
                }
                return 0;
            }
            if (str_contains($n, 'UPDATE provision_tasks')) {
                return 0;
            }
            if (str_contains($n, 'UPDATE app_settings')) {
                return 0;
            }
            return 0;
        }

        public function transaction(callable $callback)
        {
            $tables = $this->tables;
            $autoIds = $this->autoIds;
            try {
                return $callback($this);
            } catch (\Throwable $throwable) {
                $this->tables = $tables;
                $this->autoIds = $autoIds;
                throw $throwable;
            }
        }

        /** @return array<int, array<string, mixed>> */
        public function table(string $table): array
        {
            return $this->tables[$table] ?? [];
        }

        /** @return array<int, array<string, mixed>> */
        public function publishTasks(): array
        {
            return array_values(array_filter($this->tables['provision_tasks'] ?? [], static fn(array $row): bool => (string) ($row['action'] ?? '') === 'publish'));
        }

        /** @return array<int, array<string, mixed>> */
        public function destroyTasks(): array
        {
            return array_values(array_filter($this->tables['provision_tasks'] ?? [], static fn(array $row): bool => (string) ($row['action'] ?? '') === 'destroy'));
        }

        private function withSidecar(array $combo): array
        {
            $point = $this->findById('sidecar_points', (int) ($combo['sidecar_point_id'] ?? 0)) ?? [];
            return array_merge($combo, [
                'sidecar_point_name' => (string) ($point['name'] ?? ''),
                'sidecar_host' => (string) ($point['sidecar_host'] ?? ''),
                'sidecar_listen_port' => (int) ($point['listen_port'] ?? 0),
                'sidecar_bond_listen_port' => (int) ($point['bond_listen_port'] ?? 0),
                'sidecar_mptcp_listen_port' => (int) ($point['mptcp_listen_port'] ?? 0),
                'sidecar_cf_listen_port' => (int) ($point['cf_listen_port'] ?? 0),
            ]);
        }

        private function activeEndpoints(int $forwardId, string $deleted, string $deleting): array
        {
            $rows = array_values(array_filter($this->tables['service_endpoints'] ?? [], static function (array $row) use ($forwardId, $deleted, $deleting): bool {
                return (int) ($row['forward_id'] ?? 0) === $forwardId
                    && !in_array((string) ($row['health_status'] ?? ''), [$deleted, $deleting], true);
            }));
            usort($rows, static fn(array $a, array $b): int => [((int) ($a['priority'] ?? 0)), ((int) ($a['id'] ?? 0))] <=> [((int) ($b['priority'] ?? 0)), ((int) ($b['id'] ?? 0))]);
            return $rows;
        }

        private function matchingNodes(array $params, string $sql = ''): array
        {
            $groupKeys = [];
            if (array_key_exists('group_key', $params)) {
                $groupKeys[] = (string) $params['group_key'];
            }
            foreach ($params as $key => $value) {
                if (str_starts_with((string) $key, 'group_key_')) {
                    $groupKeys[] = (string) $value;
                }
            }
            $groupIds = [];
            foreach ($this->tables['node_groups'] ?? [] as $group) {
                if ($groupKeys === [] || in_array((string) ($group['group_key'] ?? ''), $groupKeys, true)) {
                    $groupIds[] = (int) ($group['id'] ?? 0);
                }
            }

            $rows = [];
            foreach ($this->tables['nodes'] ?? [] as $node) {
                if (!in_array((string) ($node['status'] ?? ''), ['active', 'degraded'], true)) {
                    continue;
                }
                if ($groupIds !== [] && !in_array((int) ($node['group_id'] ?? 0), $groupIds, true)) {
                    continue;
                }
                if (array_key_exists('owner_service_id', $params) && !$this->nodeVisibleToService($node, (int) ($params['owner_service_id'] ?? 0))) {
                    continue;
                }
                $group = $this->findById('node_groups', (int) ($node['group_id'] ?? 0));
                $rows[] = array_merge($node, ['node_group_key' => (string) ($group['group_key'] ?? '')]);
            }
            usort($rows, static function (array $left, array $right): int {
                $loadCmp = ((int) ($left['current_load'] ?? 0)) <=> ((int) ($right['current_load'] ?? 0));
                if ($loadCmp !== 0) {
                    return $loadCmp;
                }
                return ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
            });
            if (preg_match('/LIMIT (\d+)$/', $sql, $matches) === 1) {
                $rows = array_slice($rows, 0, (int) $matches[1]);
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

        /** @param array<int, string> $keys */
        private function nodeGroupsByKeys(array $keys): array
        {
            $keySet = array_fill_keys($keys, true);
            $rows = [];
            foreach ($this->tables['node_groups'] ?? [] as $group) {
                $key = (string) ($group['group_key'] ?? '');
                if (!isset($keySet[$key])) {
                    continue;
                }
                $count = 0;
                foreach ($this->tables['nodes'] ?? [] as $node) {
                    if ((int) ($node['group_id'] ?? 0) === (int) ($group['id'] ?? 0) && in_array((string) ($node['status'] ?? ''), ['active', 'degraded'], true)) {
                        $count++;
                    }
                }
                $rows[] = array_merge($group, ['node_count' => $count]);
            }
            usort($rows, static fn(array $left, array $right): int => [((string) ($left['name'] ?? '')), ((int) ($left['id'] ?? 0))] <=> [((string) ($right['name'] ?? '')), ((int) ($right['id'] ?? 0))]);
            return $rows;
        }

        private function nodeVisibleToService(array $node, int $serviceId): bool
        {
            $owner = $node['owner_service_id'] ?? null;
            if ($owner === null || (int) $owner === $serviceId) {
                return true;
            }
            if ((string) ($node['sharing_mode'] ?? '') !== 'group_shared') {
                return false;
            }
            $service = $this->findById('services', $serviceId);
            $userId = (int) ($service['user_id'] ?? 0);
            foreach ($this->tables['user_slave_group_members'] ?? [] as $member) {
                if ((int) ($member['group_id'] ?? 0) === (int) ($node['user_slave_group_id'] ?? 0)
                    && (int) ($member['user_id'] ?? 0) === $userId) {
                    return true;
                }
            }
            return false;
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

    /** @return array<int, array<string, mixed>> */
    function rows_for_forward(Database $db, string $table, int $forwardId): array
    {
        return array_values(array_filter($db->table($table), static fn(array $row): bool => (int) ($row['forward_id'] ?? 0) === $forwardId));
    }

    function row_by_id(Database $db, string $table, int $id): array
    {
        foreach ($db->table($table) as $row) {
            if ((int) ($row['id'] ?? 0) === $id) {
                return $row;
            }
        }
        fail('missing row ' . $table . '#' . $id);
    }

    $envPath = tempnam(sys_get_temp_dir(), 'lw-specific-node-env-');
    $line451 = "line = 451\n";
    $line453 = "line = 453\n";
    file_put_contents(
        $envPath,
        "NODE_SELECTION_MAX_HEARTBEAT_AGE_SECONDS=0\n"
        . "DEFAULT_TLS_CERT_REF=default-edge-cert\n"
        . "DEFAULT_CDN_HOST=edge.example.com\n"
        . 'LEIGOD_LINE_TOML_451=' . base64_encode($line451) . "\n"
        . 'LEIGOD_LINE_TOML_453=' . base64_encode($line453) . "\n"
    );

    $db = new Database(new Config($envPath));
    $db->seed('services', [[
        'id' => 2,
        'user_id' => 7,
        'plan_id' => 0,
        'status' => 'active',
        'client_token' => 'svc-token',
        'name' => 'svc',
    ]]);
    $db->seed('node_groups', [
        [
            'id' => 1,
            'group_key' => 'shanghai-tencent',
            'name' => 'Shanghai Tencent',
            'ingress_preferred_public_port' => 443,
            'ingress_port_strategy' => 'shared_preferred_port',
            'ingress_path_strategy' => 'random',
            'ingress_cdn_host' => '',
            'ingress_sni_host' => 'edge.example.com',
        ],
        [
            'id' => 2,
            'group_key' => 'seoul-edge',
            'name' => 'Seoul Edge',
            'ingress_preferred_public_port' => 8443,
            'ingress_port_strategy' => 'shared_preferred_port',
            'ingress_path_strategy' => 'date-random',
            'ingress_cdn_host' => 'cdn.seoul.example.com',
            'ingress_sni_host' => 'sni.seoul.example.com',
        ],
    ]);
    $db->seed('nodes', [
        [
            'id' => 2,
            'group_id' => 1,
            'name' => 'less-loaded-same-group',
            'public_ip' => '203.0.113.2',
            'status' => 'active',
            'current_load' => 0,
            'last_heartbeat_at' => '2026-07-20 00:00:00',
            'owner_service_id' => null,
            'sharing_mode' => 'private',
            'user_slave_group_id' => null,
            'agent_config_json' => null,
        ],
        [
            'id' => 19,
            'group_id' => 1,
            'name' => 'requested-node',
            'public_ip' => '203.0.113.19',
            'status' => 'active',
            'current_load' => 99,
            'last_heartbeat_at' => '2026-07-20 00:00:00',
            'owner_service_id' => null,
            'sharing_mode' => 'private',
            'user_slave_group_id' => null,
            'agent_config_json' => null,
        ],
        [
            'id' => 30,
            'group_id' => 2,
            'name' => 'seoul-requested-node',
            'public_ip' => '203.0.113.30',
            'status' => 'active',
            'current_load' => 10,
            'last_heartbeat_at' => '2026-07-20 00:00:00',
            'owner_service_id' => null,
            'sharing_mode' => 'private',
            'user_slave_group_id' => null,
            'agent_config_json' => null,
        ],
    ]);
    $db->seed('sidecar_points', []);
    $db->seed('forward_combinations', []);
    $db->seed('forward_combination_helper_groups', []);
    $db->seed('forward_combination_slaves', []);
    $db->seed('service_plan_forward_combinations', []);
    $db->seed('forward_routes', []);
    $db->seed('service_plan_forward_routes', []);
    $db->seed('forward_route_combinations', []);
    $db->seed('service_endpoints', []);
    $db->seed('provision_tasks', []);
    $db->seed('audit_logs', []);

    $service = new ProvisioningService(new Config($envPath), $db);
    $service->createForward(2, [
        'listen_port' => 12181,
        'target_host' => '162.245.221.78',
        'target_port' => 80,
        'transport_mode' => 'tcp',
        'client_mode' => 'single',
        'node_group' => '_user_node_19',
        'public_port' => 443,
    ], 'test');

    $forwards = $db->table('forwards');
    if (count($forwards) !== 1) {
        fail('expected exactly one forward row');
    }
    $forward = $forwards[0];
    if ((string) ($forward['node_group_key'] ?? '') !== 'shanghai-tencent') {
        fail('specific-node forward must persist the requested node real group, got ' . var_export($forward['node_group_key'] ?? null, true));
    }
    if ((int) ($forward['endpoint_count'] ?? 0) !== 1) {
        fail('specific-node forward must persist endpoint_count=1');
    }
    $custom = json_decode((string) ($forward['custom_endpoint_ids_json'] ?? ''), true);
    if (!is_array($custom) || (int) ($custom['specific_node_id'] ?? 0) !== 19) {
        fail('specific-node forward must persist custom_endpoint_ids_json.specific_node_id=19, got ' . var_export($forward['custom_endpoint_ids_json'] ?? null, true));
    }

    $endpoints = $db->table('service_endpoints');
    if (count($endpoints) !== 1 || (int) ($endpoints[0]['node_id'] ?? 0) !== 19) {
        fail('createForward must initially publish only to requested node 19');
    }

    // Simulate the initially published endpoint becoming active, plus a stale wrong endpoint that a
    // previous group-based reconcile drift would have created on the lower-load same-group node.
    $db->seed('service_endpoints', [
        array_merge($endpoints[0], ['health_status' => 'active']),
        [
            'id' => 701,
            'forward_id' => (int) ($forward['id'] ?? 0),
            'node_id' => 2,
            'public_host' => '203.0.113.2',
            'public_ip' => '203.0.113.2',
            'public_port' => 443,
            'cdn_host' => '',
            'priority' => 20,
            'weight' => 100,
            'health_status' => 'active',
            'last_error' => null,
            'created_at' => '2026-07-20 00:00:00',
            'updated_at' => '2026-07-20 00:00:00',
        ],
    ]);

    call_private($service, 'reconcileForwardEndpoints', (int) ($forward['id'] ?? 0));

    $after = $db->table('service_endpoints');
    $byNode = [];
    foreach ($after as $endpoint) {
        $byNode[(int) ($endpoint['node_id'] ?? 0)] = $endpoint;
    }
    if ((string) ($byNode[19]['health_status'] ?? '') !== 'active') {
        fail('reconcile must keep requested node 19 active');
    }
    if ((string) ($byNode[2]['health_status'] ?? '') !== 'deleting') {
        fail('reconcile must delete stale lower-load node 2 instead of drifting to it');
    }
    $destroyNodeIds = array_map(static fn(array $row): int => (int) ($row['node_id'] ?? 0), $db->destroyTasks());
    if (!in_array(2, $destroyNodeIds, true)) {
        fail('reconcile must queue destroy for stale node 2 endpoint');
    }

    foreach (['{"specific_node_id":"19abc"}', '{"specific_node_id":19.5}', '{"specific_node_id":true}', 'true'] as $badCustomEndpointIdsJson) {
        try {
            call_private($service, 'specificNodeIdFromCustomEndpointIds', $badCustomEndpointIdsJson);
            fail('custom specific_node_id parser must reject malformed metadata: ' . $badCustomEndpointIdsJson);
        } catch (\Leikwan\Master\Support\ApiException) {
        }
    }
    if (call_private($service, 'specificNodeIdFromCustomEndpointIds', '{"specific_node_id":"19"}') !== 19) {
        fail('custom specific_node_id parser must accept digit-only strings');
    }

    $db->seed('sidecar_points', [
        [
            'id' => 1,
            'name' => 'sidecar',
            'sidecar_host' => 'sidecar.example.com',
            'listen_port' => 1443,
            'bond_listen_port' => 2443,
            'mptcp_listen_port' => 3443,
            'cf_listen_port' => 4443,
            'is_active' => 1,
        ],
        [
            'id' => 2,
            'name' => 'fixed-bond-sidecar',
            'sidecar_host' => '198.51.100.25',
            'listen_port' => 18500,
            'bond_listen_port' => 18501,
            'mptcp_listen_port' => 18502,
            'cf_listen_port' => 443,
            'is_active' => 1,
            'agent_config_json' => json_encode(['fixed_endpoint_leigod_bond' => true]),
        ],
    ]);
    $db->seed('forward_combinations', [
        [
            'id' => 77,
            'name' => 'combo',
            'is_active' => 1,
            'backend_mode' => 'current_mode',
            'client_mode' => 'single',
            'node_group_key' => 'shanghai-tencent',
            'sidecar_point_id' => 1,
            'bond_ingress_mode' => 'plain_tcp_mux',
            'cf_location_group_id' => null,
            'sort_order' => 1,
        ],
        [
            'id' => 78,
            'name' => 'fixed bond combo',
            'is_active' => 1,
            'backend_mode' => 'leigod_mode',
            'client_mode' => 'bond',
            'node_group_key' => 'shanghai-tencent',
            'sidecar_point_id' => 2,
            'bond_ingress_mode' => 'plain_tcp_mux',
            'cf_location_group_id' => null,
            'sort_order' => 2,
        ],
    ]);
    $service->createForward(2, [
        'listen_port' => 12182,
        'target_host' => '162.245.221.78',
        'target_port' => 80,
        'transport_mode' => 'tcp',
        'client_mode' => 'single',
        'forward_combination_id' => 77,
        'node_group' => '_user_node_bad',
        'public_port' => 443,
    ], 'test');
    $comboForward = $db->table('forwards')[1] ?? [];
    if ((int) ($comboForward['forward_combination_id'] ?? 0) !== 77) {
        fail('combination-owned placement must ignore stale malformed _user_node selector');
    }
    if (str_contains((string) ($comboForward['custom_endpoint_ids_json'] ?? ''), 'specific_node_id')) {
        fail('combination-owned placement must clear specific_node_id metadata');
    }

    $hash451 = hash('sha256', $line451);
    $hash453 = hash('sha256', $line453);
    $db->availabilityRows = array_map(static fn(array $row): array => array_merge([
        'route_id' => 0,
        'node_id' => 19,
        'node_name' => 'requested-node',
        'node_public_ip' => '203.0.113.19',
        'node_status' => 'active',
        'combination_id' => 78,
        'combination_sort_order' => 20,
        'line_is_active' => 1,
        'service_state' => 'active',
        'tcp_status' => 'healthy',
        'tcp_connect_ms' => 10,
        'udp_status' => 'healthy',
        'checked_at' => gmdate('Y-m-d H:i:s'),
    ], $row), [
        ['line_id' => 451, 'membership_sort_order' => 10, 'config_sha256' => $hash451, 'health_config_sha256' => $hash451],
        ['line_id' => 453, 'membership_sort_order' => 20, 'config_sha256' => $hash453, 'health_config_sha256' => $hash453],
    ]);
    $service->createForward(2, [
        'listen_port' => 12185,
        'target_host' => '162.245.221.78',
        'target_port' => 80,
        'transport_mode' => 'tcp',
        'client_mode' => 'bond',
        'forward_combination_id' => 78,
        'custom_endpoint_ids_json' => ['specific_node_id' => 19],
        'public_port' => 7003,
    ], 'test');
    $fixedBondForward = $db->table('forwards')[2] ?? [];
    $fixedBondCustom = json_decode((string) ($fixedBondForward['custom_endpoint_ids_json'] ?? ''), true);
    if ((int) ($fixedBondForward['forward_combination_id'] ?? 0) !== 78 || (int) ($fixedBondCustom['specific_node_id'] ?? 0) !== 19) {
        fail('gated fixed-endpoint LeiGod bond must retain specific_node_id metadata');
    }
    $fixedBondEndpoints = rows_for_forward($db, 'service_endpoints', (int) ($fixedBondForward['id'] ?? 0));
    if (count($fixedBondEndpoints) !== 1 || (int) ($fixedBondEndpoints[0]['node_id'] ?? 0) !== 19) {
        fail('gated fixed-endpoint LeiGod bond must publish only to pinned node 19');
    }

    $service->createForward(2, [
        'listen_port' => 13080,
        'target_host' => '162.245.221.78',
        'target_port' => 80,
        'transport_mode' => 'tcp',
        'client_mode' => 'single',
        'node_group' => '_user_node_30',
    ], 'test');
    $seoulForward = $db->table('forwards')[3] ?? [];
    if ((string) ($seoulForward['node_group_key'] ?? '') !== 'seoul-edge') {
        fail('specific-node ingress must use the pinned node real group before defaulting ingress settings');
    }
    if ((int) ($seoulForward['public_port'] ?? 0) !== 8443) {
        fail('specific-node ingress must use pinned group preferred public port');
    }
    if ((string) ($seoulForward['sni_host'] ?? '') !== 'sni.seoul.example.com') {
        fail('specific-node ingress must use pinned group SNI host');
    }
    if ((string) ($seoulForward['cdn_host'] ?? '') !== 'cdn.seoul.example.com') {
        fail('specific-node ingress must use pinned group CDN host');
    }
    if (preg_match('/^\d{6}-[0-9a-f]{8}$/', (string) ($seoulForward['path_prefix'] ?? '')) !== 1) {
        fail('specific-node ingress must use pinned group date-random path strategy');
    }

    $forwardWithExtraMetadata = $db->table('forwards')[0];
    $forwardWithExtraMetadata['custom_endpoint_ids_json'] = '{"external_ids":[42],"specific_node_id":19}';
    $forwardsWithExtraMetadata = $db->table('forwards');
    $forwardsWithExtraMetadata[0] = $forwardWithExtraMetadata;
    $db->seed('forwards', $forwardsWithExtraMetadata);
    $service->updateForward((int) ($forwardWithExtraMetadata['id'] ?? 0), [
        'listen_port' => 12183,
        'target_host' => '162.245.221.78',
        'target_port' => 80,
        'transport_mode' => 'tcp',
        'client_mode' => 'single',
        'node_group' => '_user_node_19',
        'public_port' => 443,
    ], 'test');
    $updatedPinnedForward = $db->table('forwards')[0] ?? [];
    $updatedCustom = json_decode((string) ($updatedPinnedForward['custom_endpoint_ids_json'] ?? ''), true);
    if (!is_array($updatedCustom) || ($updatedCustom['external_ids'] ?? null) !== [42] || (int) ($updatedCustom['specific_node_id'] ?? 0) !== 19) {
        fail('structural update must preserve unrelated custom_endpoint_ids_json metadata while refreshing specific_node_id');
    }

    $service->createForward(2, [
        'listen_port' => 13180,
        'target_host' => '162.245.221.78',
        'target_port' => 80,
        'transport_mode' => 'tcp',
        'client_mode' => 'single',
        'node_group' => '_user_node_19',
        'forward_chain_id' => 999,
        'public_port' => 443,
    ], 'test');
    $chainBypassedForward = $db->table('forwards')[array_key_last($db->table('forwards'))] ?? [];
    if ((int) ($chainBypassedForward['forward_chain_id'] ?? 0) !== 0) {
        fail('specific-node placement must ignore an otherwise invalid forward_chain_id');
    }
    $chainBypassedEndpoints = rows_for_forward($db, 'service_endpoints', (int) ($chainBypassedForward['id'] ?? 0));
    if (count($chainBypassedEndpoints) !== 1 || (int) ($chainBypassedEndpoints[0]['node_id'] ?? 0) !== 19) {
        fail('specific-node placement with invalid forward_chain_id must still publish only to requested node 19');
    }
    $service->updateForward((int) ($chainBypassedForward['id'] ?? 0), [
        'listen_port' => 13181,
        'target_host' => '162.245.221.78',
        'target_port' => 80,
        'transport_mode' => 'tcp',
        'client_mode' => 'single',
        'forward_chain_id' => 999,
        'public_port' => 443,
    ], 'test');
    $chainIgnoredOnUpdate = row_by_id($db, 'forwards', (int) ($chainBypassedForward['id'] ?? 0));
    $chainIgnoredCustom = json_decode((string) ($chainIgnoredOnUpdate['custom_endpoint_ids_json'] ?? ''), true);
    if ((int) ($chainIgnoredOnUpdate['forward_chain_id'] ?? 0) !== 0 || (int) ($chainIgnoredCustom['specific_node_id'] ?? 0) !== 19) {
        fail('structural update must preserve durable exact-node pin even when a stale forward_chain_id is submitted');
    }
    $service->updateForward((int) ($chainBypassedForward['id'] ?? 0), [
        'listen_port' => 13182,
        'target_host' => '162.245.221.78',
        'target_port' => 80,
        'transport_mode' => 'tcp',
        'client_mode' => 'single',
        'forward_combination_id' => 0,
        'forward_route_id' => 0,
        'forward_chain_id' => 999,
        'public_port' => 443,
    ], 'test');
    $zeroPlacementUpdate = row_by_id($db, 'forwards', (int) ($chainBypassedForward['id'] ?? 0));
    $zeroPlacementCustom = json_decode((string) ($zeroPlacementUpdate['custom_endpoint_ids_json'] ?? ''), true);
    if ((int) ($zeroPlacementUpdate['forward_chain_id'] ?? 0) !== 0 || (int) ($zeroPlacementCustom['specific_node_id'] ?? 0) !== 19) {
        fail('zero-valued route/combination fields must not clear durable exact-node pin');
    }

    $service->createForward(2, [
        'listen_port' => 13280,
        'target_host' => '162.245.221.78',
        'target_port' => 80,
        'transport_mode' => 'tcp',
        'client_mode' => 'single',
        'node_group' => '_user_node_19',
        'public_port' => 443,
    ], 'test');
    $rollbackForward = $db->table('forwards')[array_key_last($db->table('forwards'))] ?? [];
    $rollbackForwardId = (int) ($rollbackForward['id'] ?? 0);
    $beforeRollbackEndpoints = $db->table('service_endpoints');
    foreach ($beforeRollbackEndpoints as &$endpoint) {
        if ((int) ($endpoint['forward_id'] ?? 0) === $rollbackForwardId) {
            $endpoint['health_status'] = 'active';
        }
    }
    unset($endpoint);
    $db->seed('service_endpoints', $beforeRollbackEndpoints);
    $tasksBeforeFailedUpdate = count(rows_for_forward($db, 'provision_tasks', $rollbackForwardId));
    try {
        $service->updateForward($rollbackForwardId, [
            'listen_port' => 13281,
            'target_host' => '162.245.221.78',
            'target_port' => 80,
            'transport_mode' => 'tcp',
            'client_mode' => 'single',
            'node_group' => '_user_node_999',
            'public_port' => 443,
        ], 'test');
        fail('invalid exact-node structural update must fail');
    } catch (\Leikwan\Master\Support\ApiException $exception) {
        if ($exception->getMessage() !== 'specific_node_not_allowed') {
            fail('invalid exact-node structural update failed with unexpected error: ' . $exception->getMessage());
        }
    }
    $rollbackEndpoints = rows_for_forward($db, 'service_endpoints', $rollbackForwardId);
    if (count($rollbackEndpoints) !== 1 || (string) ($rollbackEndpoints[0]['health_status'] ?? '') !== 'active') {
        fail('failed exact-node structural update must roll back endpoint revocation');
    }
    if (count(rows_for_forward($db, 'provision_tasks', $rollbackForwardId)) !== $tasksBeforeFailedUpdate) {
        fail('failed exact-node structural update must roll back queued destroy tasks');
    }

    $comboForwardId = (int) ($comboForward['id'] ?? 0);
    $forwardsBeforeCatalogUpdate = $db->table('forwards');
    foreach ($forwardsBeforeCatalogUpdate as &$storedForward) {
        if ((int) ($storedForward['id'] ?? 0) === $comboForwardId) {
            $storedForward['custom_endpoint_ids_json'] = '{"external_ids":[99],"specific_node_id":19}';
        }
    }
    unset($storedForward);
    $db->seed('forwards', $forwardsBeforeCatalogUpdate);
    $service->updateForward($comboForwardId, [
        'listen_port' => 12184,
        'target_host' => '162.245.221.78',
        'target_port' => 80,
        'transport_mode' => 'tcp',
        'client_mode' => 'single',
        'node_group' => '_user_node_30',
        'public_port' => 443,
    ], 'test');
    $catalogForward = row_by_id($db, 'forwards', $comboForwardId);
    $catalogCustom = json_decode((string) ($catalogForward['custom_endpoint_ids_json'] ?? ''), true);
    if ((int) ($catalogForward['forward_combination_id'] ?? 0) !== 77 || (string) ($catalogForward['node_group_key'] ?? '') !== 'shanghai-tencent') {
        fail('structural update without placement ids must preserve existing combination-owned placement');
    }
    if (!is_array($catalogCustom) || ($catalogCustom['external_ids'] ?? null) !== [99] || array_key_exists('specific_node_id', $catalogCustom)) {
        fail('combination-owned structural update must clear stale specific_node_id while preserving unrelated custom metadata');
    }

    $db->seed('forward_routes', [[
        'id' => 999,
        'name' => 'active-route-without-combos',
        'is_active' => 1,
        'sort_order' => 1,
    ]]);
    $db->seed('service_plan_forward_routes', [[
        'id' => 1,
        'plan_id' => 0,
        'forward_route_id' => 999,
    ]]);
    $forwardCountBeforeBadRoute = count($db->table('forwards'));
    try {
        $service->createForward(2, [
            'listen_port' => 13380,
            'target_host' => '162.245.221.78',
            'target_port' => 80,
            'transport_mode' => 'tcp',
            'client_mode' => 'single',
            'forward_route_id' => 999,
            'public_port' => 443,
        ], 'test');
        fail('route placement without an enabled primary combination must fail closed');
    } catch (\Leikwan\Master\Support\ApiException $exception) {
        if ($exception->getMessage() !== 'forward_route_has_no_enabled_combination') {
            fail('route without enabled primary failed with unexpected error: ' . $exception->getMessage());
        }
    }
    if (count($db->table('forwards')) !== $forwardCountBeforeBadRoute) {
        fail('route placement without enabled primary combination must not create a forward');
    }

    $db->seed('forward_routes', [
        [
            'id' => 1000,
            'name' => 'unauthorized-route',
            'is_active' => 1,
            'sort_order' => 1,
        ],
        [
            'id' => 1001,
            'name' => 'allowed-route',
            'is_active' => 1,
            'sort_order' => 2,
        ],
    ]);
    $db->seed('forward_route_combinations', [
        [
            'id' => 1,
            'forward_route_id' => 1000,
            'forward_combination_id' => 77,
            'enabled' => 1,
            'sort_order' => 1,
        ],
        [
            'id' => 2,
            'forward_route_id' => 1001,
            'forward_combination_id' => 77,
            'enabled' => 1,
            'sort_order' => 1,
        ],
    ]);
    $servicesWithRouteGating = $db->table('services');
    $servicesWithRouteGating[0]['plan_id'] = 43;
    $db->seed('services', $servicesWithRouteGating);
    $db->seed('service_plan_forward_routes', [[
        'id' => 1,
        'plan_id' => 43,
        'forward_route_id' => 1001,
    ]]);
    $forwardCountBeforeUnauthorizedRoute = count($db->table('forwards'));
    try {
        $service->createForward(2, [
            'listen_port' => 13381,
            'target_host' => '162.245.221.78',
            'target_port' => 80,
            'transport_mode' => 'tcp',
            'client_mode' => 'single',
            'forward_route_id' => 1000,
            'public_port' => 443,
        ], 'test');
        fail('route placement must reject routes outside the service plan');
    } catch (\Leikwan\Master\Support\ApiException $exception) {
        if ($exception->getMessage() !== 'forward_route_not_allowed') {
            fail('unauthorized route failed with unexpected error: ' . $exception->getMessage());
        }
    }
    if (count($db->table('forwards')) !== $forwardCountBeforeUnauthorizedRoute) {
        fail('unauthorized route must not create a forward');
    }

    $db->seed('forward_route_combinations', array_merge($db->table('forward_route_combinations'), [[
        'id' => 3,
        'forward_route_id' => 1001,
        'forward_combination_id' => 88,
        'enabled' => 0,
        'sort_order' => 2,
    ]]));
    try {
        $service->createForward(2, [
            'listen_port' => 13382,
            'target_host' => '162.245.221.78',
            'target_port' => 80,
            'transport_mode' => 'tcp',
            'client_mode' => 'single',
            'forward_route_id' => 1001,
            'forward_combination_id' => 88,
            'public_port' => 443,
        ], 'test');
        fail('route placement must reject a combination outside the selected route');
    } catch (\Leikwan\Master\Support\ApiException $exception) {
        if ($exception->getMessage() !== 'forward_route_combination_mismatch') {
            fail('route/combination mismatch failed with unexpected error: ' . $exception->getMessage());
        }
    }

    $servicesWithoutRouteGating = $db->table('services');
    $servicesWithoutRouteGating[0]['plan_id'] = 0;
    $db->seed('services', $servicesWithoutRouteGating);
    $db->seed('service_plan_forward_routes', []);

    $nodeGroupsWithPinned = $db->table('node_groups');
    $nodeGroupsWithPinned[] = [
        'id' => 3,
        'group_key' => 'berlin-edge',
        'name' => 'Berlin Edge',
        'ingress_preferred_public_port' => 9443,
        'ingress_port_strategy' => 'shared_preferred_port',
        'ingress_path_strategy' => 'random',
        'ingress_cdn_host' => '',
        'ingress_sni_host' => 'berlin.example.com',
    ];
    $db->seed('node_groups', $nodeGroupsWithPinned);
    $nodesWithPinned = $db->table('nodes');
    $nodesWithPinned[] = [
        'id' => 40,
        'group_id' => 3,
        'name' => 'berlin-pinned-node',
        'public_ip' => '203.0.113.40',
        'status' => 'active',
        'current_load' => 5,
        'last_heartbeat_at' => '2026-07-20 00:00:00',
        'owner_service_id' => null,
        'sharing_mode' => 'private',
        'user_slave_group_id' => null,
        'agent_config_json' => null,
    ];
    $db->seed('nodes', $nodesWithPinned);
    $db->seed('forward_combinations', [[
        'id' => 88,
        'name' => 'combo-with-extra-placements',
        'is_active' => 1,
        'backend_mode' => 'current_mode',
        'client_mode' => 'single',
        'node_group_key' => 'shanghai-tencent',
        'sidecar_point_id' => 1,
        'bond_ingress_mode' => 'plain_tcp_mux',
        'cf_location_group_id' => null,
        'sort_order' => 1,
    ]]);
    $db->seed('forward_combination_helper_groups', [[
        'id' => 1,
        'forward_combination_id' => 88,
        'node_group_key' => 'seoul-edge',
        'sort_order' => 1,
        'is_active' => 1,
    ]]);
    $db->seed('forward_combination_slaves', [[
        'id' => 1,
        'forward_combination_id' => 88,
        'node_id' => 40,
        'selection_kind' => 'admin_pin',
        'is_active' => 1,
    ]]);
    $db->seed('service_plan_forward_combinations', [[
        'id' => 1,
        'plan_id' => 42,
        'forward_combination_id' => 88,
    ]]);
    $allowedPlacementKeys = array_map(static fn(array $group): string => (string) ($group['group_key'] ?? ''), $service->allowedNodeGroupsForPlan(42));
    foreach (['shanghai-tencent', 'seoul-edge', 'berlin-edge'] as $expectedGroupKey) {
        if (!in_array($expectedGroupKey, $allowedPlacementKeys, true)) {
            fail('combination-plan allowed node groups must include primary/helper/admin-pinned group ' . $expectedGroupKey);
        }
    }

    $nodesWithShared = $db->table('nodes');
    $nodesWithShared[] = [
        'id' => 31,
        'group_id' => 2,
        'name' => 'group-shared-node',
        'public_ip' => '203.0.113.31',
        'status' => 'active',
        'current_load' => 1,
        'last_heartbeat_at' => '2026-07-20 00:00:00',
        'owner_service_id' => 99,
        'sharing_mode' => 'group_shared',
        'user_slave_group_id' => 501,
        'agent_config_json' => null,
    ];
    $db->seed('nodes', $nodesWithShared);
    $db->seed('user_slave_group_members', [[
        'group_id' => 501,
        'user_id' => 7,
        'role' => 'member',
    ]]);
    $service->createForward(2, [
        'listen_port' => 13480,
        'target_host' => '162.245.221.78',
        'target_port' => 80,
        'transport_mode' => 'tcp',
        'client_mode' => 'single',
        'node_group' => '_user_node_31',
        'public_port' => 8443,
    ], 'test');
    $sharedForward = $db->table('forwards')[array_key_last($db->table('forwards'))] ?? [];
    $sharedEndpoints = rows_for_forward($db, 'service_endpoints', (int) ($sharedForward['id'] ?? 0));
    if (count($sharedEndpoints) !== 1 || (int) ($sharedEndpoints[0]['node_id'] ?? 0) !== 31) {
        fail('specific-node placement must allow group-shared nodes visible to the service user');
    }

    echo "ok\n";
}
