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
                    // Simulate the ambiguous current DB row shape where f.id overwrote e.id.
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
            return 0;
        }

        /**
         * @return mixed
         */
        public function transaction(callable $callback)
        {
            return $callback($this);
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

    $envPath = tempnam(sys_get_temp_dir(), 'lw-node-republish-');
    if ($envPath === false) {
        fail('failed to create temp env file');
    }
    file_put_contents($envPath, "DEFAULT_TLS_CERT_REF=default-edge-cert\n");

    $config = new Config($envPath);
    $db = new Database();
    $service = new ProvisioningService($config, $db);
    $service->republishActiveEndpointsForNode(5);

    $tasks = $db->fetchAll('SELECT * FROM provision_tasks');
    if (count($tasks) !== 1) {
        fail('expected exactly 1 queued publish task, got ' . count($tasks));
    }

    $task = $tasks[0];
    if ((int) ($task['endpoint_id'] ?? 0) !== 173) {
        fail('expected republish task endpoint_id 173, got ' . var_export($task['endpoint_id'] ?? null, true));
    }
    if ((int) ($task['forward_id'] ?? 0) !== 23) {
        fail('expected republish task forward_id 23, got ' . var_export($task['forward_id'] ?? null, true));
    }

    echo "ok\n";
}
