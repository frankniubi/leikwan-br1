<?php
declare(strict_types=1);

namespace Leikwan\Master {
    final class Config
    {
        public function __construct(string $envPath) {}
        public function get(string $key, mixed $default = null): mixed { return $default; }
        public function getInt(string $key, int $default = 0): int { return $default; }
    }
}

namespace Leikwan\Master\Support {
    class ApiException extends \RuntimeException
    {
        public function __construct(string $message, int $statusCode = 400, array $details = [])
        {
            parent::__construct($message, $statusCode);
        }
    }
}

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<string, list<array<string, mixed>>> */
        private array $tables = [];
        public int $transactionCalls = 0;

        /** @param list<array<string, mixed>> $rows */
        public function seed(string $table, array $rows): void { $this->tables[$table] = array_values($rows); }

        /** @return list<array<string, mixed>> */
        public function rows(string $table): array { return $this->tables[$table] ?? []; }

        /** @param array<string, mixed> $params @return array<string, mixed>|null */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            if (str_contains($sql, 'FROM services WHERE id = :id')) {
                foreach ($this->tables['services'] ?? [] as $row) {
                    if ((int) ($row['id'] ?? 0) === (int) ($params['id'] ?? 0)) {
                        return $row;
                    }
                }
            }
            if (str_contains($sql, 'FROM forwards WHERE id = :id')) {
                foreach ($this->tables['forwards'] ?? [] as $row) {
                    if ((int) ($row['id'] ?? 0) === (int) ($params['id'] ?? 0)) {
                        return $row;
                    }
                }
            }
            return null;
        }

        /** @param array<string, mixed> $params @return list<array<string, mixed>> */
        public function fetchAll(string $sql, array $params = []): array
        {
            foreach (['forwards', 'service_endpoints', 'provision_tasks', 'audit_logs'] as $table) {
                if (str_contains($sql, 'FROM ' . $table)) {
                    return $this->tables[$table] ?? [];
                }
            }
            return [];
        }

        /** @param array<string, mixed> $data */
        public function insert(string $table, array $data): int
        {
            $id = count($this->tables[$table] ?? []) + 1;
            $data['id'] = $id;
            $this->tables[$table][] = $data;
            return $id;
        }

        /** @param array<string, mixed> $params */
        public function execute(string $sql, array $params = []): int { return 1; }

        public function transaction(callable $callback): mixed
        {
            $this->transactionCalls++;
            $snapshot = $this->tables;
            try {
                return $callback($this);
            } catch (\Throwable $throwable) {
                $this->tables = $snapshot;
                throw $throwable;
            }
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

    $db = new Database();
    $db->seed('services', [[
        'id' => 1,
        'user_id' => 7,
        'plan_id' => 1,
        'status' => 'active',
    ]]);
    $service = new ProvisioningService(new Config(''), $db);

    try {
        $service->createForward(1, [
            'listen_port' => 5000,
            'target_host' => 'backend.example.com',
            'target_port' => 443,
            'transport_mode' => 'tcp',
            'endpoint_mode' => 'slave_group',
            'node_group' => 'edge',
            'client_mode' => 'single',
        ], 'user');
        fail('createForward unexpectedly succeeded without an available node');
    } catch (\Throwable $throwable) {
        if ($throwable->getMessage() !== 'no_available_nodes') {
            fail('expected no_available_nodes, got ' . $throwable->getMessage());
        }
    }

    if ($db->transactionCalls !== 1) {
        fail('createForward must execute the full mutation inside one transaction');
    }
    foreach (['forwards', 'service_endpoints', 'provision_tasks', 'audit_logs'] as $table) {
        if ($db->rows($table) !== []) {
            fail($table . ' retained partial mutation after placement failure');
        }
    }

    echo "ok\n";
}
