<?php
declare(strict_types=1);

namespace Leikwan\Master {
    final class Config
    {
        public function get(string $key, ?string $default = null): ?string
        {
            return $default;
        }

        public function getInt(string $key, int $default = 0): int
        {
            return $default;
        }

        public function getBool(string $key, bool $default = false): bool
        {
            return $default;
        }
    }
}

namespace Leikwan\Master\Exceptions {
    final class ApiException extends \RuntimeException
    {
        public function __construct(string $message, int $status = 500, private array $details = [])
        {
            parent::__construct($message, $status);
        }

        public function details(): array
        {
            return $this->details;
        }
    }
}

namespace Leikwan\Master\Database {
    final class Database
    {
        private array $tables = [];
        private array $autoIds = [];

        public function seed(string $table, array $rows): void
        {
            $this->tables[$table] = array_values($rows);
            $maxId = 0;
            foreach ($rows as $row) {
                $maxId = max($maxId, (int) ($row['id'] ?? 0));
            }
            $this->autoIds[$table] = $maxId + 1;
        }

        public function fetchOne(string $sql, array $params = []): ?array
        {
            $normalized = $this->normalizeSql($sql);

            if (str_contains($normalized, 'SELECT * FROM users WHERE id = :id LIMIT 1')) {
                return $this->findById('users', (int) ($params['id'] ?? 0));
            }

            if (str_contains($normalized, 'SELECT * FROM clients WHERE id = :id LIMIT 1')) {
                return $this->findById('clients', (int) ($params['id'] ?? 0));
            }

            if (str_contains($normalized, 'FROM clients') && str_contains($normalized, 'is_default_for_owner = 1')) {
                $ownerUserId = (int) ($params['owner_user_id'] ?? 0);
                foreach ($this->rows('clients') as $row) {
                    if ((int) ($row['owner_user_id'] ?? 0) === $ownerUserId && (int) ($row['is_default_for_owner'] ?? 0) === 1) {
                        return $row;
                    }
                }
                return null;
            }

            if (str_contains($normalized, 'SELECT p.default_client_online_device_limit FROM services s')) {
                $userId = (int) ($params['user_id'] ?? 0);
                $status = (string) ($params['status'] ?? 'active');
                foreach ($this->rows('services') as $service) {
                    if ((int) ($service['user_id'] ?? 0) !== $userId || (string) ($service['status'] ?? '') !== $status) {
                        continue;
                    }
                    foreach ($this->rows('service_plans') as $plan) {
                        if ((int) ($plan['id'] ?? 0) === (int) ($service['plan_id'] ?? 0)) {
                            return ['default_client_online_device_limit' => $plan['default_client_online_device_limit'] ?? 1];
                        }
                    }
                }
                return null;
            }

            return null;
        }

        public function fetchAll(string $sql, array $params = []): array
        {
            $normalized = $this->normalizeSql($sql);

            if (str_contains($normalized, 'SELECT * FROM users WHERE role = :role ORDER BY id ASC')) {
                return array_values(array_filter($this->rows('users'), static fn(array $row): bool => (string) ($row['role'] ?? '') === (string) ($params['role'] ?? '')));
            }

            if (str_contains($normalized, 'SELECT * FROM clients') && str_contains($normalized, 'WHERE owner_user_id = :owner_user_id')) {
                $ownerUserId = (int) ($params['owner_user_id'] ?? 0);
                $rows = array_values(array_filter($this->rows('clients'), static fn(array $row): bool => (int) ($row['owner_user_id'] ?? 0) === $ownerUserId));
                usort($rows, static function (array $left, array $right): int {
                    $leftRank = ((string) ($left['status'] ?? '')) === 'active' ? 0 : 1;
                    $rightRank = ((string) ($right['status'] ?? '')) === 'active' ? 0 : 1;
                    return [$leftRank, (int) ($left['id'] ?? 0)] <=> [$rightRank, (int) ($right['id'] ?? 0)];
                });
                return $rows;
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
            $normalized = $this->normalizeSql($sql);

            if (str_contains($normalized, 'UPDATE clients SET is_default_for_owner = 0')) {
                $count = 0;
                foreach ($this->tables['clients'] as &$row) {
                    if ((int) ($row['owner_user_id'] ?? 0) === (int) ($params['owner_user_id'] ?? 0)) {
                        $row['is_default_for_owner'] = 0;
                        $row['updated_at'] = $params['updated_at'] ?? $row['updated_at'] ?? null;
                        $count++;
                    }
                }
                unset($row);
                return $count;
            }

            if (str_contains($normalized, 'UPDATE clients SET is_default_for_owner = 1')) {
                $count = 0;
                foreach ($this->tables['clients'] as &$row) {
                    if ((int) ($row['id'] ?? 0) === (int) ($params['id'] ?? 0) && (int) ($row['owner_user_id'] ?? 0) === (int) ($params['owner_user_id'] ?? 0)) {
                        $row['is_default_for_owner'] = 1;
                        $row['updated_at'] = $params['updated_at'] ?? $row['updated_at'] ?? null;
                        $count++;
                    }
                }
                unset($row);
                return $count;
            }

            return 0;
        }

        public function transaction(callable $callback): mixed
        {
            return $callback($this);
        }

        private function rows(string $table): array
        {
            return array_values($this->tables[$table] ?? []);
        }

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
        if (!str_starts_with($class, $prefix) || $class === 'Leikwan\\Master\\Config' || $class === 'Leikwan\\Master\\Database\\Database' || $class === 'Leikwan\\Master\\Exceptions\\ApiException') {
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
    $db->seed('users', [
        ['id' => 10, 'name' => 'Tenant A', 'email' => 'tenant-a@example.com', 'role' => 'tenant', 'status' => 'active'],
        ['id' => 20, 'name' => 'Tenant B', 'email' => 'tenant-b@example.com', 'role' => 'tenant', 'status' => 'active'],
        ['id' => 30, 'name' => 'Tenant C', 'email' => 'tenant-c@example.com', 'role' => 'tenant', 'status' => 'active'],
        ['id' => 40, 'name' => 'Tenant D', 'email' => 'tenant-d@example.com', 'role' => 'tenant', 'status' => 'active'],
    ]);
    $db->seed('clients', [
        ['id' => 101, 'owner_user_id' => 20, 'group_id' => null, 'is_default_for_owner' => 0, 'agent_token' => 'tok-101', 'name' => 'Older Active', 'display_host' => 'older.example.com', 'status' => 'active', 'created_at' => '2026-04-25 00:00:00', 'updated_at' => '2026-04-25 00:00:00'],
        ['id' => 102, 'owner_user_id' => 20, 'group_id' => null, 'is_default_for_owner' => 0, 'agent_token' => 'tok-102', 'name' => 'Newer Active', 'display_host' => 'newer.example.com', 'status' => 'active', 'created_at' => '2026-04-25 00:00:01', 'updated_at' => '2026-04-25 00:00:01'],
        ['id' => 201, 'owner_user_id' => 30, 'group_id' => null, 'is_default_for_owner' => 1, 'agent_token' => 'tok-201', 'name' => 'First Wrong Default', 'display_host' => 'first.example.com', 'status' => 'active', 'created_at' => '2026-04-25 00:00:00', 'updated_at' => '2026-04-25 00:00:00'],
        ['id' => 202, 'owner_user_id' => 30, 'group_id' => null, 'is_default_for_owner' => 1, 'agent_token' => 'tok-202', 'name' => 'Second Wrong Default', 'display_host' => 'second.example.com', 'status' => 'active', 'created_at' => '2026-04-25 00:00:01', 'updated_at' => '2026-04-25 00:00:01'],
    ]);
    $db->seed('service_plans', [
        ['id' => 501, 'default_client_online_device_limit' => 3],
    ]);
    $db->seed('services', [
        ['id' => 601, 'user_id' => 10, 'plan_id' => 501, 'status' => 'active'],
    ]);

    $service = new ProvisioningService(new Config(), $db);

    $created = $service->ensureDefaultClientForUser(10);
    if ((int) ($created['owner_user_id'] ?? 0) !== 10 || (int) ($created['is_default_for_owner'] ?? 0) !== 1) {
        fail('expected ensureDefaultClientForUser() to create a default client for tenant 10');
    }
    if ((int) ($created['online_device_limit'] ?? 0) !== 3) {
        fail('expected default client to inherit active plan device limit');
    }

    $repeat = $service->ensureDefaultClientForUser(10);
    if ((int) ($repeat['id'] ?? 0) !== (int) ($created['id'] ?? 0)) {
        fail('expected ensureDefaultClientForUser() to be idempotent for tenant 10');
    }

    $backfill = $service->backfillDefaultClientsForUsers(null, true);
    if ((int) ($backfill['created'] ?? 0) < 1 || (int) ($backfill['marked_existing'] ?? 0) < 1 || (int) ($backfill['normalized_duplicates'] ?? 0) < 1) {
        fail('expected backfillDefaultClientsForUsers() to report created, marked, and normalized tenants');
    }

    $defaultB = $service->defaultClientForUser(20);
    if ((int) ($defaultB['id'] ?? 0) !== 101) {
        fail('expected oldest active owned client to become default for tenant 20');
    }

    $allC = $db->fetchAll('SELECT * FROM clients WHERE owner_user_id = :owner_user_id ORDER BY id ASC', ['owner_user_id' => 30]);
    $defaultCount = 0;
    foreach ($allC as $row) {
        if ((int) ($row['is_default_for_owner'] ?? 0) === 1) {
            $defaultCount++;
        }
    }
    if ($defaultCount !== 1) {
        fail('expected tenant 30 to have exactly one default client after normalization');
    }

    echo "ok\n";
}
