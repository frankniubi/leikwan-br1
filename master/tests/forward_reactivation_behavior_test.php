<?php
declare(strict_types=1);

namespace Leikwan\Master {
    final class Config
    {
        public function __construct(string $envPath) {}
        public function get(string $key, mixed $default = null): mixed { return $default; }
        public function getInt(string $key, int $default = 0): int { return $default; }
        public function getBool(string $key, bool $default = false): bool { return $default; }
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

        /** @param list<array<string, mixed>> $rows */
        public function seed(string $table, array $rows): void { $this->tables[$table] = array_values($rows); }
        /** @return list<array<string, mixed>> */
        public function rows(string $table): array { return $this->tables[$table] ?? []; }

        public function transaction(callable $callback): mixed { return $callback($this); }

        public function fetchOne(string $sql, array $params = []): ?array
        {
            if (str_contains($sql, 'FROM users WHERE id = :id')) {
                return $this->byId('users', (int) ($params['id'] ?? 0));
            }
            if (str_contains($sql, 'FROM services WHERE id = :id')) {
                return $this->byId('services', (int) ($params['id'] ?? 0));
            }
            if (str_contains($sql, 'COUNT(*) AS active_count')) {
                $count = count(array_filter(
                    $this->tables['forwards'] ?? [],
                    static fn(array $row): bool => (int) ($row['service_id'] ?? 0) === (int) ($params['service_id'] ?? 0)
                        && (int) ($row['enabled'] ?? 0) === 1
                ));
                return ['active_count' => $count];
            }
            return null;
        }

        public function fetchAll(string $sql, array $params = []): array
        {
            if (str_contains($sql, 'FROM services') && str_contains($sql, 'WHERE user_id = :user_id')) {
                return array_values(array_filter(
                    $this->tables['services'] ?? [],
                    static fn(array $row): bool => (int) ($row['user_id'] ?? 0) === (int) ($params['user_id'] ?? 0)
                        && (string) ($row['status'] ?? '') === (string) ($params['status'] ?? '')
                ));
            }
            if (str_contains($sql, 'FROM forwards')
                && str_contains($sql, 'user_deleted_at IS NULL')
                && str_contains($sql, 'balance_suspended_at IS NOT NULL')) {
                $rows = array_values(array_filter(
                    $this->tables['forwards'] ?? [],
                    static fn(array $row): bool => (int) ($row['service_id'] ?? 0) === (int) ($params['service_id'] ?? 0)
                        && (int) ($row['enabled'] ?? 0) === 0
                        && ($row['user_deleted_at'] ?? null) === null
                        && ($row['balance_suspended_at'] ?? null) !== null
                ));
                preg_match('/LIMIT\s+(\d+)/i', $sql, $matches);
                return array_slice($rows, 0, max(0, (int) ($matches[1] ?? count($rows))));
            }
            return [];
        }

        public function execute(string $sql, array $params = []): int
        {
            if (!str_contains($sql, 'SET enabled = 1')
                || !str_contains($sql, 'user_deleted_at IS NULL')
                || !str_contains($sql, 'balance_suspended_at IS NOT NULL')) {
                return 0;
            }
            foreach ($this->tables['forwards'] ?? [] as $index => $row) {
                if ((int) ($row['id'] ?? 0) !== (int) ($params['id'] ?? 0)
                    || (int) ($row['enabled'] ?? 0) !== 0
                    || ($row['user_deleted_at'] ?? null) !== null
                    || ($row['balance_suspended_at'] ?? null) === null) {
                    continue;
                }
                $this->tables['forwards'][$index]['enabled'] = 1;
                $this->tables['forwards'][$index]['api_revision'] = max(1, (int) ($row['api_revision'] ?? 1)) + 1;
                $this->tables['forwards'][$index]['balance_suspended_at'] = null;
                return 1;
            }
            return 0;
        }

        public function insert(string $table, array $data): int
        {
            $this->tables[$table][] = $data;
            return count($this->tables[$table]);
        }

        private function byId(string $table, int $id): ?array
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
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });

    use Leikwan\Master\Config;
    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Support\ProvisioningService;

    function failReactivationBehavior(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    $db = new Database();
    $db->seed('users', [[
        'id' => 7,
        'role' => 'tenant',
        'status' => 'active',
        'wallet_balance' => '10.00',
        'feature_flags_json' => json_encode(['allow_manage_forwards' => true]),
    ]]);
    $db->seed('services', [[
        'id' => 42,
        'user_id' => 7,
        'plan_id' => 0,
        'status' => 'active',
    ]]);
    $db->seed('forwards', [
        ['id' => 1, 'service_id' => 42, 'enabled' => 0, 'api_revision' => 3, 'user_deleted_at' => '2026-08-02 01:00:00', 'balance_suspended_at' => null],
        ['id' => 2, 'service_id' => 42, 'enabled' => 0, 'api_revision' => 4, 'user_deleted_at' => null, 'balance_suspended_at' => '2026-08-02 02:00:00'],
        ['id' => 3, 'service_id' => 42, 'enabled' => 0, 'api_revision' => 7, 'user_deleted_at' => null, 'balance_suspended_at' => null],
    ]);

    $service = new ProvisioningService(new Config(''), $db);
    $count = $service->reactivateEligibleForwardsForUser(7, 1);
    $rows = $db->rows('forwards');
    if ($count !== 1
        || (int) ($rows[0]['enabled'] ?? 0) !== 0
        || (int) ($rows[0]['api_revision'] ?? 0) !== 3
        || (int) ($rows[1]['enabled'] ?? 0) !== 1
        || (int) ($rows[1]['api_revision'] ?? 0) !== 5
        || !array_key_exists('balance_suspended_at', $rows[1])
        || $rows[1]['balance_suspended_at'] !== null
        || (int) ($rows[2]['enabled'] ?? 0) !== 0) {
        failReactivationBehavior('reactivation must restore only balance suspensions, clear the marker, respect quota, and advance revision: ' . json_encode([
            'count' => $count,
            'rows' => $rows,
        ]));
    }

    $db->seed('users', [[
        'id' => 7,
        'role' => 'tenant',
        'status' => 'active',
        'wallet_balance' => '0.00',
        'feature_flags_json' => json_encode(['allow_manage_forwards' => true]),
    ]]);
    $db->seed('forwards', [[
        'id' => 4,
        'service_id' => 42,
        'enabled' => 0,
        'api_revision' => 1,
        'user_deleted_at' => null,
        'balance_suspended_at' => '2026-08-02 03:00:00',
    ]]);
    try {
        $service->reactivateEligibleForwardsForUser(7, 1);
        failReactivationBehavior('zero balance unexpectedly allowed reactivation');
    } catch (\Throwable $throwable) {
        if ($throwable->getMessage() !== 'insufficient_balance'
            || (int) ($db->rows('forwards')[0]['enabled'] ?? 0) !== 0) {
            failReactivationBehavior('transactional balance guard failed: ' . $throwable->getMessage());
        }
    }

    echo "ok\n";
}
