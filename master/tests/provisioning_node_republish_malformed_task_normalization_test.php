<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {

    final class Database
    {
        /** @var array<int, array<string, mixed>> */
        private array $tasks = [];

        /**
         * @param array<string, mixed> $params
         * @return array<int, array<string, mixed>>
         */
        public function fetchAll(string $sql, array $params = []): array
        {
            $normalized = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);

            if (str_contains($normalized, 'SELECT e.*, f.*, e.id AS service_endpoint_id, s.status AS service_status')) {
                return [[
                    'id' => 23,
                    'service_endpoint_id' => 173,
                    'forward_id' => 23,
                    'service_id' => 1,
                    'node_id' => 5,
                    'service_status' => 'active',
                    'enabled' => 1,
                    'public_port' => 7000,
                    'path_prefix' => 'dcfa28eea4bf6b3b',
                    'client_mode' => 'single',
                    'target_host' => '138.252.163.34',
                    'target_port' => 14961,
                    'sni_host' => 'edge.example.com',
                    'cdn_host' => '',
                    'filter_profile' => 'off',
                    'transport_mode' => 'tcp+udp',
                    'forward_combination_id' => null,
                ]];
            }

            if (str_contains($normalized, 'FROM provision_tasks')) {
                return $this->tasks;
            }

            return [];
        }

        /**
         * @param array<string, mixed> $params
         * @return array<string, mixed>|null
         */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            $normalized = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);

            if (str_contains($normalized, 'SELECT * FROM nodes WHERE id = :id LIMIT 1')) {
                return [
                    'id' => (int) ($params['id'] ?? 0),
                    'public_ip' => '8.8.8.8',
                    'public_host' => 'edge.example.com',
                    'name' => 'edge-1',
                    'status' => 'active',
                ];
            }

            return null;
        }

        /**
         * @param array<string, mixed> $data
         */
        public function insert(string $table, array $data): int
        {
            if ($table === 'provision_tasks') {
                $this->tasks[] = $data;
                return count($this->tasks);
            }

            return 1;
        }

        /**
         * @param array<string, mixed> $params
         */
        public function execute(string $sql, array $params = []): int
        {
            $normalized = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
            if (!str_contains($normalized, 'UPDATE provision_tasks')) {
                return 0;
            }

            $updated = 0;
            foreach ($this->tasks as &$task) {
                if ((int) ($task['node_id'] ?? 0) !== (int) ($params['node_id'] ?? 0)) {
                    continue;
                }
                if ((string) ($task['action'] ?? '') !== 'publish') {
                    continue;
                }
                if ((int) ($task['forward_id'] ?? 0) !== (int) ($params['forward_id'] ?? 0)) {
                    continue;
                }
                if ((int) ($task['endpoint_id'] ?? 0) !== (int) ($params['forward_id'] ?? 0)) {
                    continue;
                }
                if ((int) ($task['endpoint_id'] ?? 0) === (int) ($params['service_endpoint_id'] ?? 0)) {
                    continue;
                }
                if ((string) ($task['status'] ?? '') !== 'failed') {
                    continue;
                }
                if ((string) ($task['error_message'] ?? '') !== (string) ($params['expected_error_message'] ?? '')) {
                    continue;
                }

                $task['status'] = $params['completed_status'] ?? 'completed';
                $task['error_message'] = null;
                $task['result_json'] = $params['result_json'] ?? null;
                $task['lease_expires_at'] = null;
                $task['updated_at'] = $params['updated_at'] ?? '2026-04-20 00:00:00';
                $updated++;
            }
            unset($task);

            return $updated;
        }

        /**
         * @return mixed
         */
        public function transaction(callable $callback)
        {
            return $callback($this);
        }

        /**
         * @param array<int, array<string, mixed>> $tasks
         */
        public function seedTasks(array $tasks): void
        {
            $this->tasks = $tasks;
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

    $envPath = tempnam(sys_get_temp_dir(), 'lw-node-republish-normalize-');
    if ($envPath === false) {
        fail('failed to create temp env file');
    }
    file_put_contents($envPath, "DEFAULT_TLS_CERT_REF=default-edge-cert\n");

    $config = new Config($envPath);
    $db = new Database();
    $db->seedTasks([
        [
            'id' => 279467,
            'node_id' => 5,
            'action' => 'publish',
            'service_id' => 1,
            'forward_id' => 23,
            'endpoint_id' => 23,
            'status' => 'failed',
            'payload_json' => '{"target":"138.252.163.34:14961"}',
            'lease_expires_at' => null,
            'attempt_count' => 1,
            'result_json' => null,
            'error_message' => 'purged malformed publish task after endpoint_id hotfix',
            'created_at' => '2026-04-16 16:02:06',
            'updated_at' => '2026-04-16 16:08:34',
        ],
        [
            'id' => 279471,
            'node_id' => 5,
            'action' => 'publish',
            'service_id' => 1,
            'forward_id' => 23,
            'endpoint_id' => 23,
            'status' => 'failed',
            'payload_json' => '{"target":"138.252.163.34:14961"}',
            'lease_expires_at' => null,
            'attempt_count' => 1,
            'result_json' => null,
            'error_message' => 'path_prefix conflict on public_port=7000: dcfa28eea4bf6b3b already used by wstunnel-svc-1-172',
            'created_at' => '2026-04-16 16:02:06',
            'updated_at' => '2026-04-16 16:08:34',
        ],
    ]);

    $service = new ProvisioningService($config, $db);
    $service->republishActiveEndpointsForNode(5);

    $tasks = $db->fetchAll('SELECT * FROM provision_tasks');
    if (count($tasks) !== 3) {
        fail('expected 3 tasks after normalization + republish, got ' . count($tasks));
    }

    $normalizedTask = $tasks[0];
    if (($normalizedTask['status'] ?? null) !== 'completed') {
        fail('expected exact hotfix-purge task to become completed, got ' . var_export($normalizedTask['status'] ?? null, true));
    }
    if (array_key_exists('error_message', $normalizedTask) && $normalizedTask['error_message'] !== null) {
        fail('expected exact hotfix-purge task error_message to be cleared');
    }
    $normalizedResult = json_decode((string) ($normalizedTask['result_json'] ?? ''), true);
    if (!is_array($normalizedResult) || ($normalizedResult['note'] ?? '') !== 'purged malformed publish task after endpoint_id hotfix') {
        fail('expected exact hotfix-purge task result_json note to be recorded');
    }

    $otherTask = $tasks[1];
    if (($otherTask['status'] ?? null) !== 'failed') {
        fail('expected non-exact malformed publish failure to remain failed, got ' . var_export($otherTask['status'] ?? null, true));
    }

    $newTask = $tasks[2];
    if ((int) ($newTask['endpoint_id'] ?? 0) !== 173) {
        fail('expected newly queued publish task endpoint_id 173, got ' . var_export($newTask['endpoint_id'] ?? null, true));
    }
    if (($newTask['status'] ?? null) !== 'pending') {
        fail('expected newly queued publish task to stay pending, got ' . var_export($newTask['status'] ?? null, true));
    }

    echo "ok\n";
}
