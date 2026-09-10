<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {

    final class Database
    {
        /** @var array<string, array<int, array<string, mixed>>> */
        private array $tables = [];

        /**
         * @param array<int, array<string, mixed>> $rows
         */
        public function seed(string $table, array $rows): void
        {
            $this->tables[$table] = array_values($rows);
        }

        /**
         * @param array<string, mixed> $params
         * @return array<string, mixed>|null
         */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            $normalized = $this->normalizeSql($sql);

            if (str_contains($normalized, 'SELECT COUNT(*) AS total FROM forwards WHERE bond_sidecar_point_id = :id AND enabled = 1')) {
                $pointId = (int) ($params['id'] ?? 0);
                $total = 0;
                foreach ($this->tables['forwards'] ?? [] as $row) {
                    if ((int) ($row['bond_sidecar_point_id'] ?? 0) === $pointId && (int) ($row['enabled'] ?? 0) === 1) {
                        $total++;
                    }
                }

                return ['total' => $total];
            }

            return null;
        }

        /**
         * @param array<string, mixed> $params
         */
        public function execute(string $sql, array $params = []): int
        {
            $normalized = $this->normalizeSql($sql);

            if (str_contains($normalized, 'DELETE FROM agent_usage_hourly WHERE entity_type = :entity_type AND entity_id = :entity_id')) {
                $entityType = (string) ($params['entity_type'] ?? '');
                $entityId = (int) ($params['entity_id'] ?? 0);
                $before = count($this->tables['agent_usage_hourly'] ?? []);
                $this->tables['agent_usage_hourly'] = array_values(array_filter(
                    $this->tables['agent_usage_hourly'] ?? [],
                    static fn(array $row): bool => !(
                        (string) ($row['entity_type'] ?? '') === $entityType
                        && (int) ($row['entity_id'] ?? 0) === $entityId
                    )
                ));

                return $before - count($this->tables['agent_usage_hourly']);
            }

            if (str_contains($normalized, 'DELETE FROM sidecar_points WHERE id = :id')) {
                $pointId = (int) ($params['id'] ?? 0);
                $before = count($this->tables['sidecar_points'] ?? []);
                $this->tables['sidecar_points'] = array_values(array_filter(
                    $this->tables['sidecar_points'] ?? [],
                    static fn(array $row): bool => (int) ($row['id'] ?? 0) !== $pointId
                ));

                return $before - count($this->tables['sidecar_points']);
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

        public function countRows(string $table): int
        {
            return count($this->tables[$table] ?? []);
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

    use Leikwan\Master\Config;
    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Support\ApiException;
    use Leikwan\Master\Support\ProvisioningService;

    function fail(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    $envPath = tempnam(sys_get_temp_dir(), 'lw-sidecar-delete-env-');
    if ($envPath === false) {
        fail('failed to create temp env file');
    }
    file_put_contents($envPath, "\n");

    $db = new Database();
    $db->seed('sidecar_points', [[
        'id' => 7,
        'name' => 'point-7',
    ]]);
    $db->seed('agent_usage_hourly', [[
        'id' => 1,
        'entity_type' => 'sidecar',
        'entity_id' => 7,
    ]]);
    $db->seed('forwards', [[
        'id' => 101,
        'bond_sidecar_point_id' => 7,
        'enabled' => 1,
    ]]);

    $service = new ProvisioningService(new Config($envPath), $db);

    try {
        $service->deleteSidecarPoint(7);
        fail('expected deleteSidecarPoint() to block active sidecar usage');
    } catch (ApiException $exception) {
        if ($exception->getMessage() !== 'sidecar_point_delete_blocked') {
            fail('expected sidecar_point_delete_blocked, got ' . $exception->getMessage());
        }
        if ($exception->statusCode() !== 409) {
            fail('expected 409 status code, got ' . $exception->statusCode());
        }
        if (($exception->details()['usage'] ?? null) !== 1) {
            fail('expected exception usage detail to equal 1');
        }
    } catch (\Throwable $throwable) {
        fail('expected ApiException, got ' . $throwable::class . ': ' . $throwable->getMessage());
    }

    $db->seed('forwards', [[
        'id' => 101,
        'bond_sidecar_point_id' => 7,
        'enabled' => 0,
    ]]);

    $service->deleteSidecarPoint(7);

    if ($db->countRows('sidecar_points') !== 0) {
        fail('expected sidecar point row to be deleted');
    }
    if ($db->countRows('agent_usage_hourly') !== 0) {
        fail('expected sidecar usage rows to be deleted');
    }

    echo "ok\n";
}
