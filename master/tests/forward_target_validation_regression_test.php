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
        public int $writes = 0;

        /** @param list<array<string, mixed>> $rows */
        public function seed(string $table, array $rows): void { $this->tables[$table] = array_values($rows); }

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
        public function fetchAll(string $sql, array $params = []): array { return []; }

        /** @param array<string, mixed> $data */
        public function insert(string $table, array $data): int { $this->writes++; return 1; }

        /** @param array<string, mixed> $params */
        public function execute(string $sql, array $params = []): int { $this->writes++; return 1; }

        public function transaction(callable $callback): mixed { return $callback($this); }
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
    use Leikwan\Master\Support\ApiException;
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
    $db->seed('forwards', [[
        'id' => 9,
        'service_id' => 1,
        'listen_port' => 5000,
        'target_host' => 'old.example.com',
        'target_port' => 80,
        'transport_mode' => 'tcp',
    ]]);
    $service = new ProvisioningService(new Config(''), $db);

    foreach ([
        ['target_host' => "bad\rhost.example.com", 'target_port' => 443],
        ['target_host' => 'backend.example.com', 'target_port' => 65536],
    ] as $payload) {
        try {
            $service->createForward(1, $payload, 'user');
            fail('createForward accepted invalid target input');
        } catch (ApiException) {
        }
    }

    try {
        $service->updateForwardNonStructural(9, [
            'target_host' => "bad\nbackend.example.com",
            'target_port' => 443,
            'listen_port' => 5001,
            'transport_mode' => 'tcp',
        ], 'tenant');
        fail('updateForwardNonStructural accepted unsafe host');
    } catch (ApiException) {
    }

    try {
        $service->updateForwardNonStructural(9, [
            'target_host' => 'backend.example.com',
            'target_port' => 0,
            'listen_port' => 5001,
            'transport_mode' => 'tcp',
        ], 'tenant');
        fail('updateForwardNonStructural accepted invalid target port');
    } catch (ApiException) {
    }

    if ($db->writes !== 0) {
        fail('invalid direct provisioning calls performed writes before validation');
    }

    echo "ok\n";
}
