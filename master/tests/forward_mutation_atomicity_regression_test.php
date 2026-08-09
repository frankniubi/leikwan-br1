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
        public int $transactionCalls = 0;
        /** @var list<string> */
        public array $queries = [];

        /** @param list<array<string, mixed>> $rows */
        public function seed(string $table, array $rows): void { $this->tables[$table] = array_values($rows); }

        /** @return list<array<string, mixed>> */
        public function rows(string $table): array { return $this->tables[$table] ?? []; }

        /** @param array<string, mixed> $params @return array<string, mixed>|null */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            $this->queries[] = $sql;
            if (str_contains($sql, 'COUNT(*) AS active_count')) {
                $count = count(array_filter(
                    $this->tables['forwards'] ?? [],
                    static fn(array $row): bool => (int) ($row['service_id'] ?? 0) === (int) ($params['service_id'] ?? 0)
                        && (int) ($row['enabled'] ?? 0) === 1
                ));
                return ['active_count' => $count];
            }
            if (str_contains($sql, 'COUNT(*) AS stored_count')) {
                $count = count(array_filter(
                    $this->tables['forwards'] ?? [],
                    static fn(array $row): bool => (int) ($row['service_id'] ?? 0) === (int) ($params['service_id'] ?? 0)
                ));
                return ['stored_count' => $count];
            }
            if (str_contains($sql, 'api_idempotency_key_hash = :key_hash')) {
                foreach ($this->tables['forwards'] ?? [] as $row) {
                    if ((int) ($row['service_id'] ?? 0) === (int) ($params['service_id'] ?? 0)
                        && (string) ($row['api_idempotency_key_hash'] ?? '') === (string) ($params['key_hash'] ?? '')) {
                        return $row;
                    }
                }
                return null;
            }
            if (str_contains($sql, 'FROM users WHERE id = :id')) {
                foreach ($this->tables['users'] ?? [] as $row) {
                    if ((int) ($row['id'] ?? 0) === (int) ($params['id'] ?? 0)) {
                        return $row;
                    }
                }
                return null;
            }
            if (str_contains($sql, 'FROM service_plans WHERE id = :id')) {
                foreach ($this->tables['service_plans'] ?? [] as $row) {
                    if ((int) ($row['id'] ?? 0) === (int) ($params['id'] ?? 0)) {
                        return $row;
                    }
                }
                return null;
            }
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

    $db->seed('forwards', [
        ['id' => 1, 'service_id' => 1, 'enabled' => 1],
        ['id' => 2, 'service_id' => 1, 'enabled' => 1],
    ]);
    try {
        $service->createForward(1, [
            'listen_port' => 5000,
            'target_host' => 'backend.example.com',
            'target_port' => 443,
            'transport_mode' => 'tcp',
        ], 'user', null, null, 2);
        fail('createForward unexpectedly bypassed the transaction-scoped quota');
    } catch (\Throwable $throwable) {
        if ($throwable->getMessage() !== 'forward_quota_exceeded') {
            fail('expected forward_quota_exceeded, got ' . $throwable->getMessage());
        }
    }
    if (!array_filter($db->queries, static fn(string $sql): bool => str_contains($sql, 'FROM services') && str_contains($sql, 'FOR UPDATE'))) {
        fail('createForward must lock the service row before checking quota');
    }
    if (count($db->rows('forwards')) !== 2) {
        fail('quota rejection must not create a forward');
    }

    $keyHash = hash('sha256', 'same-create-key');
    $requestHash = hash('sha256', 'same-request');
    $db->seed('forwards', [[
        'id' => 7,
        'service_id' => 1,
        'enabled' => 1,
        'api_idempotency_key_hash' => $keyHash,
        'api_request_hash' => $requestHash,
    ]]);
    $replayed = $service->createForward(1, [], 'user', $keyHash, $requestHash, 1);
    if (($replayed['forward_id'] ?? null) !== 7 || empty($replayed['idempotent_replay'])) {
        fail('same idempotency key and request must replay before quota rejection');
    }
    try {
        $service->createForward(1, [], 'user', $keyHash, hash('sha256', 'changed-request'), 1);
        fail('changed request unexpectedly reused an idempotency key');
    } catch (\Throwable $throwable) {
        if ($throwable->getMessage() !== 'idempotency_conflict') {
            fail('expected idempotency_conflict, got ' . $throwable->getMessage());
        }
    }
    $db->seed('forwards', [[
        'id' => 7,
        'service_id' => 1,
        'enabled' => 0,
        'api_idempotency_key_hash' => $keyHash,
        'api_request_hash' => $requestHash,
    ]]);
    try {
        $service->createForward(1, [], 'user', $keyHash, $requestHash, 1);
        fail('inactive idempotency resource unexpectedly replayed');
    } catch (\Throwable $throwable) {
        if ($throwable->getMessage() !== 'idempotency_resource_inactive') {
            fail('expected idempotency_resource_inactive, got ' . $throwable->getMessage());
        }
    }

    $db->seed('users', [[
        'id' => 7,
        'role' => 'tenant',
        'status' => 'active',
        'feature_flags_json' => json_encode(['allow_manage_forwards' => false]),
    ]]);
    $db->seed('services', [[
        'id' => 1,
        'user_id' => 7,
        'plan_id' => 0,
        'status' => 'active',
    ]]);
    try {
        $service->createForward(1, [], 'user', null, null, null, ['user_id' => 7, 'feature' => 'allow_manage_forwards']);
        fail('transaction-scoped feature revocation unexpectedly allowed create');
    } catch (\Throwable $throwable) {
        if ($throwable->getMessage() !== 'feature_denied_manage_forwards') {
            fail('expected feature_denied_manage_forwards, got ' . $throwable->getMessage());
        }
    }
    try {
        $service->createForward(1, [], 'user', null, null, null, ['user_id' => 8, 'feature' => 'allow_manage_forwards']);
        fail('transaction-scoped ownership mismatch unexpectedly allowed create');
    } catch (\Throwable $throwable) {
        if ($throwable->getMessage() !== 'service_not_found') {
            fail('expected service_not_found for ownership mismatch, got ' . $throwable->getMessage());
        }
    }
    $db->seed('services', [[
        'id' => 1,
        'user_id' => 7,
        'plan_id' => 0,
        'status' => 'suspended',
    ]]);
    try {
        $service->createForward(1, [], 'user', null, null, null, ['user_id' => 7, 'feature' => 'allow_manage_forwards']);
        fail('transaction-scoped inactive service unexpectedly allowed create');
    } catch (\Throwable $throwable) {
        if ($throwable->getMessage() !== 'service_inactive') {
            fail('expected service_inactive, got ' . $throwable->getMessage());
        }
    }

    $db->seed('users', [[
        'id' => 7,
        'role' => 'tenant',
        'status' => 'active',
        'feature_flags_json' => json_encode(['allow_manage_forwards' => true]),
    ]]);
    $db->seed('services', [[
        'id' => 1,
        'user_id' => 7,
        'plan_id' => 0,
        'status' => 'active',
    ]]);
    $stored = [];
    for ($i = 1; $i <= 512; $i++) {
        $stored[] = ['id' => $i, 'service_id' => 1, 'enabled' => 0];
    }
    $db->seed('forwards', $stored);
    try {
        $service->createForward(1, [], 'user', null, null, 64, ['user_id' => 7, 'feature' => 'allow_manage_forwards']);
        fail('stored forward quota unexpectedly allowed unbounded create/delete churn');
    } catch (\Throwable $throwable) {
        if ($throwable->getMessage() !== 'forward_storage_quota_exceeded') {
            fail('expected forward_storage_quota_exceeded, got ' . $throwable->getMessage());
        }
    }

    echo "ok\n";
}
