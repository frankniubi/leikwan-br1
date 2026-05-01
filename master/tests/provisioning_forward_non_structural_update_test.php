<?php
declare(strict_types=1);

namespace Leikwan\Master {

    final class Config
    {
        /** @var array<string, string> */
        private array $values = [];

        public function __construct(string $envPath)
        {
            foreach (@file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                if (!is_string($line) || $line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                $this->values[trim($key)] = trim($value);
            }
        }

        public function get(string $key, mixed $default = null): mixed
        {
            return $this->values[$key] ?? $default;
        }

        public function getInt(string $key, int $default = 0): int
        {
            return (int) ($this->values[$key] ?? $default);
        }

        public function getBool(string $key, bool $default = false): bool
        {
            if (!array_key_exists($key, $this->values)) {
                return $default;
            }
            return in_array(strtolower((string) $this->values[$key]), ['1', 'true', 'yes', 'on'], true);
        }
    }
}

namespace Leikwan\Master\Support {

    class ApiException extends \RuntimeException
    {
        /** @param array<string, mixed> $context */
        public function __construct(string $message, int $code = 500, private array $context = [])
        {
            parent::__construct($message, $code);
        }

        /** @return array<string, mixed> */
        public function context(): array
        {
            return $this->context;
        }
    }
}

namespace Leikwan\Master\Database {

    final class Database
    {
        /** @var array<string, array<int, array<string, mixed>>> */
        private array $tables = [];

        /** @var array<string, int> */
        private array $autoIds = [
            'service_endpoints' => 100,
            'provision_tasks' => 200,
        ];

        /**
         * @param array<int, array<string, mixed>> $rows
         */
        public function seed(string $table, array $rows): void
        {
            $this->tables[$table] = array_values($rows);
        }

        /**
         * @param array<string, mixed> $params
         * @return array<int, array<string, mixed>>
         */
        public function fetchAll(string $sql, array $params = []): array
        {
            $normalized = $this->normalizeSql($sql);

            if (str_contains($normalized, 'FROM service_endpoints e INNER JOIN nodes n ON n.id = e.node_id')) {
                $forwardId = (int) ($params['forward_id'] ?? 0);
                $deleted = (string) ($params['deleted'] ?? '');
                $deleting = (string) ($params['deleting'] ?? '');
                $rows = [];
                foreach ($this->tables['service_endpoints'] ?? [] as $endpoint) {
                    if ((int) ($endpoint['forward_id'] ?? 0) !== $forwardId) {
                        continue;
                    }
                    $health = (string) ($endpoint['health_status'] ?? '');
                    if (in_array($health, [$deleted, $deleting], true)) {
                        continue;
                    }
                    $node = null;
                    foreach ($this->tables['nodes'] ?? [] as $candidate) {
                        if ((int) ($candidate['id'] ?? 0) === (int) ($endpoint['node_id'] ?? 0)) {
                            $node = $candidate;
                            break;
                        }
                    }
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
                usort($rows, static fn(array $left, array $right): int => [((int) ($left['priority'] ?? 0)), ((int) ($left['id'] ?? 0))] <=> [((int) ($right['priority'] ?? 0)), ((int) ($right['id'] ?? 0))]);
                return $rows;
            }

            if (str_contains($normalized, 'SELECT * FROM service_endpoints')) {
                $forwardId = (int) ($params['forward_id'] ?? 0);
                $deleted = (string) ($params['deleted'] ?? '');
                $deleting = (string) ($params['deleting'] ?? '');
                $rows = array_values(array_filter($this->tables['service_endpoints'] ?? [], static function (array $row) use ($forwardId, $deleted, $deleting): bool {
                    if ((int) ($row['forward_id'] ?? 0) !== $forwardId) {
                        return false;
                    }
                    $health = (string) ($row['health_status'] ?? '');
                    return $health !== $deleted && $health !== $deleting;
                }));
                usort($rows, static fn(array $left, array $right): int => [((int) ($left['priority'] ?? 0)), ((int) ($left['id'] ?? 0))] <=> [((int) ($right['priority'] ?? 0)), ((int) ($right['id'] ?? 0))]);
                return $rows;
            }

            if (str_contains($normalized, 'SELECT n.* FROM nodes n LEFT JOIN node_groups g ON g.id = n.group_id')) {
                $active = (string) ($params['active'] ?? 'active');
                $degraded = (string) ($params['degraded'] ?? 'degraded');
                $groupKeys = [];
                foreach ($params as $key => $value) {
                    if (str_starts_with((string) $key, 'group_key_')) {
                        $groupKeys[] = (string) $value;
                    }
                }
                $groupIds = [];
                foreach ($this->tables['node_groups'] ?? [] as $group) {
                    if (in_array((string) ($group['group_key'] ?? ''), $groupKeys, true)) {
                        $groupIds[] = (int) ($group['id'] ?? 0);
                    }
                }
                $rows = array_values(array_filter($this->tables['nodes'] ?? [], static function (array $node) use ($active, $degraded, $groupIds, $params): bool {
                    $status = (string) ($node['status'] ?? '');
                    if (!in_array($status, [$active, $degraded], true)) {
                        return false;
                    }
                    if ($groupIds !== [] && !in_array((int) ($node['group_id'] ?? 0), $groupIds, true)) {
                        return false;
                    }
                    if (array_key_exists('owner_service_id', $params)) {
                        $owner = $node['owner_service_id'] ?? null;
                        return $owner === null || (int) $owner === (int) $params['owner_service_id'];
                    }
                    return true;
                }));
                usort($rows, static function (array $left, array $right): int {
                    $loadCmp = ((int) ($left['current_load'] ?? 0)) <=> ((int) ($right['current_load'] ?? 0));
                    if ($loadCmp !== 0) {
                        return $loadCmp;
                    }
                    return strcmp((string) ($right['last_heartbeat_at'] ?? ''), (string) ($left['last_heartbeat_at'] ?? ''));
                });
                if (preg_match('/LIMIT (\d+)$/', $normalized, $matches) === 1) {
                    $rows = array_slice($rows, 0, (int) $matches[1]);
                }
                return $rows;
            }

            if (str_contains($normalized, 'FROM provision_tasks')) {
                return $this->tables['provision_tasks'] ?? [];
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

            if (str_contains($normalized, 'SELECT f.*, s.client_token, s.status AS service_status, s.name AS service_name,')) {
                $forward = $this->fetchOne('SELECT * FROM forwards WHERE id = :id LIMIT 1', $params);
                if ($forward === null) {
                    return null;
                }
                $service = $this->fetchOne('SELECT * FROM services WHERE id = :id LIMIT 1', ['id' => (int) ($forward['service_id'] ?? 0)]);
                if ($service === null) {
                    return null;
                }
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
                    'bond_sidecar_host' => null,
                    'bond_sidecar_legacy_port' => null,
                    'bond_sidecar_port' => null,
                    'mptcp_sidecar_port' => null,
                    'bond_sidecar_point_token_secret' => '',
                    'bond_sidecar_point_agent_config_json' => null,
                ]);
            }

            if (str_contains($normalized, 'SELECT * FROM forwards WHERE id = :id LIMIT 1')) {
                foreach ($this->tables['forwards'] ?? [] as $row) {
                    if ((int) ($row['id'] ?? 0) === (int) ($params['id'] ?? 0)) {
                        return $row;
                    }
                }
                return null;
            }

            if (str_contains($normalized, 'SELECT * FROM services WHERE id = :id LIMIT 1')) {
                foreach ($this->tables['services'] ?? [] as $row) {
                    if ((int) ($row['id'] ?? 0) === (int) ($params['id'] ?? 0)) {
                        return $row;
                    }
                }
                return null;
            }

            if (str_contains($normalized, 'SELECT f.*, s.status AS service_status FROM forwards f INNER JOIN services s ON s.id = f.service_id WHERE f.id = :id LIMIT 1')) {
                $forward = $this->fetchOne('SELECT * FROM forwards WHERE id = :id LIMIT 1', $params);
                if ($forward === null) {
                    return null;
                }
                $service = $this->fetchOne('SELECT * FROM services WHERE id = :id LIMIT 1', ['id' => (int) ($forward['service_id'] ?? 0)]);
                if ($service === null) {
                    return null;
                }
                return array_merge($forward, [
                    'service_status' => (string) ($service['status'] ?? ''),
                ]);
            }

            if (str_contains($normalized, 'SELECT COUNT(*) AS total FROM nodes n LEFT JOIN node_groups g ON g.id = n.group_id')) {
                return ['total' => count($this->fetchAll('SELECT n.* FROM nodes n LEFT JOIN node_groups g ON g.id = n.group_id LIMIT 99', $params))];
            }

            return null;
        }

        /**
         * @param array<string, mixed> $data
         */
        public function insert(string $table, array $data): int
        {
            $id = $this->autoIds[$table] ?? 1;
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
                $this->tables['service_endpoints'] ??= [];
                foreach ($this->tables['service_endpoints'] as &$row) {
                    if ((int) ($row['id'] ?? 0) === (int) ($params['id'] ?? 0)) {
                        $row['health_status'] = (string) ($params['status'] ?? $row['health_status'] ?? '');
                        $row['updated_at'] = (string) ($params['updated_at'] ?? $row['updated_at'] ?? '');
                        return 1;
                    }
                }
            }

            if (str_contains($normalized, 'UPDATE forwards SET') && str_contains($normalized, 'listen_port = :listen_port') && str_contains($normalized, 'transport_mode = :transport_mode') && str_contains($normalized, 'WHERE id = :id')) {
                $this->tables['forwards'] ??= [];
                foreach ($this->tables['forwards'] as &$row) {
                    if ((int) ($row['id'] ?? 0) === (int) ($params['id'] ?? 0)) {
                        $row['listen_port'] = (int) ($params['listen_port'] ?? $row['listen_port'] ?? 0);
                        $row['target_host'] = (string) ($params['target_host'] ?? $row['target_host'] ?? '');
                        $row['target_port'] = (int) ($params['target_port'] ?? $row['target_port'] ?? 0);
                        $row['transport_mode'] = (string) ($params['transport_mode'] ?? $row['transport_mode'] ?? 'tcp');
                        $row['updated_at'] = (string) ($params['updated_at'] ?? $row['updated_at'] ?? '');
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

    $envPath = tempnam(sys_get_temp_dir(), 'lw-forward-env-');
    if ($envPath === false) {
        fail('failed to create temp env file');
    }
    file_put_contents($envPath, "DEFAULT_TLS_CERT_REF=default-edge-cert\nDEFAULT_CDN_HOST=edge.example.com\n");

    $db = new Database();
    $db->seed('services', [[
        'id' => 1,
        'user_id' => 7,
        'plan_id' => 1,
        'status' => 'active',
        'client_token' => 'svc-token',
    ]]);
    $db->seed('forwards', [[
        'id' => 9,
        'service_id' => 1,
        'enabled' => 1,
        'forward_combination_id' => null,
        'backend_mode' => 'current_mode',
        'cf_location_group_id' => null,
        'line_client_id' => null,
        'line_client_group_id' => null,
        'line_group_mode' => 'selected',
        'line_client_group_ids_json' => null,
        'endpoint_mode' => 'slave_group',
        'forward_chain_id' => null,
        'custom_endpoint_ids_json' => null,
        'transport_mode' => 'tcp',
        'client_mode' => 'single',
        'sni_host' => 'edge.example.com',
        'path_prefix' => 'route-9',
        'node_group_key' => 'default',
        'endpoint_count' => 1,
        'public_port' => 443,
        'cdn_host' => '',
        'filter_profile' => 'off',
        'listen_port' => 5000,
        'target_host' => 'old.example.com',
        'target_port' => 80,
        'bond_remote_host' => null,
        'bond_remote_port' => null,
        'bond_ingress_mode' => 'plain_tcp_mux',
        'bond_sidecar_point_id' => null,
    ]]);
    $db->seed('node_groups', [[
        'id' => 1,
        'group_key' => 'default',
    ]]);
    $db->seed('nodes', [[
        'id' => 11,
        'group_id' => 1,
        'name' => 'edge-1.example.com',
        'public_ip' => '8.8.8.8',
        'status' => 'active',
        'current_load' => 0,
        'last_heartbeat_at' => '2026-04-21 09:00:00',
        'owner_service_id' => null,
    ]]);
    $db->seed('service_endpoints', [[
        'id' => 70,
        'forward_id' => 9,
        'node_id' => 11,
        'public_host' => '8.8.8.8',
        'public_ip' => '8.8.8.8',
        'public_port' => 443,
        'cdn_host' => '',
        'priority' => 10,
        'weight' => 100,
        'health_status' => 'active',
        'last_error' => null,
        'created_at' => '2026-04-21 09:00:00',
        'updated_at' => '2026-04-21 09:00:00',
    ]]);
    $db->seed('provision_tasks', []);

    $service = new ProvisioningService(new Config($envPath), $db);

    try {
        $service->updateForwardNonStructural(9, [
            'target_host' => '',
            'target_port' => 443,
            'listen_port' => 5001,
            'transport_mode' => 'tcp+udp',
            'forward_combination_id' => 999,
        ], 'tenant');
        fail('expected invalid payload to throw');
    } catch (\Throwable) {
        $remaining = $db->fetchAll('SELECT * FROM service_endpoints WHERE forward_id = :forward_id AND health_status <> :deleted AND health_status <> :deleting', [
            'forward_id' => 9,
            'deleted' => 'deleted',
            'deleting' => 'deleting',
        ]);
        if (count($remaining) !== 1 || (int) ($remaining[0]['id'] ?? 0) !== 70) {
            fail('invalid edit should not revoke existing endpoint');
        }
        if ($db->fetchAll('SELECT action, payload_json FROM provision_tasks ORDER BY id ASC') !== []) {
            fail('invalid edit should not queue tasks');
        }
    }

    $service->updateForwardNonStructural(9, [
        'target_host' => 'new.example.com',
        'target_port' => 443,
        'listen_port' => 5443,
        'transport_mode' => 'tcp+udp',
        'forward_combination_id' => 999,
        'endpoint_mode' => 'forward_chain',
        'client_mode' => 'bond',
    ], 'user');

    $updated = $db->fetchOne('SELECT * FROM forwards WHERE id = :id LIMIT 1', ['id' => 9]);
    if (($updated['target_host'] ?? null) !== 'new.example.com') {
        fail('target_host should update');
    }
    if ((int) ($updated['target_port'] ?? 0) !== 443) {
        fail('target_port should update');
    }
    if ((int) ($updated['listen_port'] ?? 0) !== 5443) {
        fail('listen_port should update');
    }
    if (($updated['transport_mode'] ?? null) !== 'tcp+udp') {
        fail('transport_mode should update');
    }
    if (($updated['forward_combination_id'] ?? null) !== null) {
        fail('forward_combination_id must stay locked');
    }
    if (($updated['endpoint_mode'] ?? null) !== 'slave_group') {
        fail('endpoint_mode must stay locked');
    }
    if (($updated['client_mode'] ?? null) !== 'single') {
        fail('client_mode must stay locked');
    }

    $tasks = $db->fetchAll('SELECT action, payload_json FROM provision_tasks ORDER BY id ASC');
    if (count($tasks) < 2) {
        fail('expected destroy and publish tasks after valid edit');
    }

    $serviceController = file_get_contents(__DIR__ . '/../src/Controllers/ServiceController.php');
    foreach ([
        'private function normalizeForwardNonStructuralPayload(array $payload): array',
        '$payload = $this->normalizeForwardNonStructuralPayload($request->body());',
        '\'client_config\' => $this->provisioning->updateForwardNonStructural($forwardId, $payload, \'user\'),',
    ] as $snippet) {
        if (!str_contains((string) $serviceController, $snippet)) {
            fail('service API contract missing snippet: ' . $snippet);
        }
    }

    echo "ok\n";
}
