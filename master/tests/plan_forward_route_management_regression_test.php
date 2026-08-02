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
        public int $transactionCount = 0;

        /** @param list<array<string, mixed>> $rows */
        public function seed(string $table, array $rows): void
        {
            $this->tables[$table] = array_values($rows);
        }

        /** @return list<array<string, mixed>> */
        public function table(string $table): array
        {
            return $this->tables[$table] ?? [];
        }

        /** @param array<string, mixed> $params @return array<string, mixed>|null */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            $normalized = $this->normalize($sql);
            if (str_contains($normalized, 'SELECT id FROM service_plan_forward_combinations')) {
                return $this->firstMapping('service_plan_forward_combinations', (int) ($params['plan_id'] ?? 0));
            }
            if (str_contains($normalized, 'SELECT id FROM service_plan_forward_routes')) {
                return $this->firstMapping('service_plan_forward_routes', (int) ($params['plan_id'] ?? 0));
            }
            if (str_contains($normalized, 'SELECT * FROM service_plans WHERE id = :id LIMIT 1')) {
                return $this->findById('service_plans', (int) ($params['id'] ?? 0));
            }
            return null;
        }

        /** @param array<string, mixed> $params @return list<array<string, mixed>> */
        public function fetchAll(string $sql, array $params = []): array
        {
            $normalized = $this->normalize($sql);
            if (str_contains($normalized, 'SELECT id, is_active FROM forward_routes WHERE id IN')) {
                $ids = $this->idsFromInClause($normalized);
                return array_values(array_filter(
                    $this->tables['forward_routes'] ?? [],
                    static fn(array $row): bool => in_array((int) ($row['id'] ?? 0), $ids, true)
                ));
            }
            if (str_contains($normalized, 'FROM forward_route_combinations frc')
                && str_contains($normalized, 'INNER JOIN forward_combinations fc')) {
                $routeIds = $this->idsFromInClause($normalized);
                $activeCombinationIds = [];
                foreach ($this->tables['forward_combinations'] ?? [] as $combination) {
                    if ((int) ($combination['is_active'] ?? 0) === 1) {
                        $activeCombinationIds[(int) ($combination['id'] ?? 0)] = true;
                    }
                }
                $rows = [];
                foreach ($this->tables['forward_route_combinations'] ?? [] as $mapping) {
                    $routeId = (int) ($mapping['forward_route_id'] ?? 0);
                    $combinationId = (int) ($mapping['forward_combination_id'] ?? 0);
                    if (in_array($routeId, $routeIds, true)
                        && (int) ($mapping['enabled'] ?? 0) === 1
                        && isset($activeCombinationIds[$combinationId])) {
                        $rows[$combinationId] = ['id' => $combinationId, 'forward_route_id' => $routeId];
                    }
                }
                return array_values($rows);
            }
            if (str_contains($normalized, 'FROM service_plan_forward_routes spfr')
                && str_contains($normalized, 'INNER JOIN forward_routes fr')) {
                $planId = (int) ($params['plan_id'] ?? 0);
                $routeIds = [];
                foreach ($this->tables['service_plan_forward_routes'] ?? [] as $mapping) {
                    if ((int) ($mapping['plan_id'] ?? 0) === $planId) {
                        $routeIds[(int) ($mapping['forward_route_id'] ?? 0)] = true;
                    }
                }
                return array_values(array_filter(
                    $this->tables['forward_routes'] ?? [],
                    static fn(array $row): bool => isset($routeIds[(int) ($row['id'] ?? 0)])
                ));
            }
            if (str_contains($normalized, 'SELECT * FROM service_plans')) {
                return $this->tables['service_plans'] ?? [];
            }
            return [];
        }

        /** @param array<string, mixed> $params */
        public function execute(string $sql, array $params = []): int
        {
            $normalized = $this->normalize($sql);
            foreach (['service_plan_node_groups', 'service_plan_client_groups', 'service_plan_sidecar_points', 'service_plan_forward_combinations', 'service_plan_forward_routes'] as $table) {
                if (str_contains($normalized, 'DELETE FROM ' . $table . ' WHERE plan_id = :plan_id')) {
                    $before = count($this->tables[$table] ?? []);
                    $this->tables[$table] = array_values(array_filter(
                        $this->tables[$table] ?? [],
                        static fn(array $row): bool => (int) ($row['plan_id'] ?? 0) !== (int) ($params['plan_id'] ?? 0)
                    ));
                    return $before - count($this->tables[$table]);
                }
            }
            return 0;
        }

        /** @param array<string, mixed> $data */
        public function insert(string $table, array $data): int
        {
            $rows = $this->tables[$table] ?? [];
            $id = $data['id'] ?? (count($rows) + 1);
            $data['id'] = $id;
            $rows[] = $data;
            $this->tables[$table] = $rows;
            return (int) $id;
        }

        public function transaction(callable $callback): mixed
        {
            $this->transactionCount++;
            return $callback($this);
        }

        /** @return array<string, mixed>|null */
        private function firstMapping(string $table, int $planId): ?array
        {
            foreach ($this->tables[$table] ?? [] as $row) {
                if ((int) ($row['plan_id'] ?? 0) === $planId) {
                    return $row;
                }
            }
            return null;
        }

        /** @return array<string, mixed>|null */
        private function findById(string $table, int $id): ?array
        {
            foreach ($this->tables[$table] ?? [] as $row) {
                if ((int) ($row['id'] ?? 0) === $id) {
                    return $row;
                }
            }
            return null;
        }

        /** @return list<int> */
        private function idsFromInClause(string $sql): array
        {
            if (preg_match('/\bIN \(([^)]+)\)/', $sql, $matches) !== 1) {
                return [];
            }
            return array_values(array_filter(array_map(
                static fn(string $value): int => (int) trim($value),
                explode(',', $matches[1])
            ), static fn(int $value): bool => $value > 0));
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

    function fail_plan_route_management(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    $db = new Database();
    $db->seed('forward_routes', [
        ['id' => 14, 'name' => 'route-14', 'tenant_label' => 'Smart Route', 'is_active' => 1, 'sort_order' => 10],
        ['id' => 15, 'name' => 'route-15', 'tenant_label' => 'Inactive Route', 'is_active' => 0, 'sort_order' => 20],
    ]);
    $db->seed('forward_combinations', [
        ['id' => 11, 'is_active' => 1],
        ['id' => 12, 'is_active' => 0],
    ]);
    $db->seed('forward_route_combinations', [
        ['forward_route_id' => 14, 'forward_combination_id' => 11, 'enabled' => 1],
        ['forward_route_id' => 14, 'forward_combination_id' => 12, 'enabled' => 1],
    ]);

    $service = new ProvisioningService(new Config(''), $db);
    $plan = $service->savePlan([
        'name' => 'Alpha',
        'product_code' => 'alpha',
        'allowed_forward_route_ids' => [14, '14'],
    ]);

    $routeMappings = $db->table('service_plan_forward_routes');
    if (count($routeMappings) !== 1 || (int) ($routeMappings[0]['forward_route_id'] ?? 0) !== 14) {
        fail_plan_route_management('savePlan must persist one deduplicated route allowlist row');
    }
    $combinationMappings = $db->table('service_plan_forward_combinations');
    if (count($combinationMappings) !== 1 || (int) ($combinationMappings[0]['forward_combination_id'] ?? 0) !== 11) {
        fail_plan_route_management('savePlan must derive only enabled active combinations from selected routes');
    }
    if ((int) ($plan['allowed_forward_routes'][0]['id'] ?? 0) !== 14 || empty($plan['uses_forward_routes'])) {
        fail_plan_route_management('savePlan result must expose the persisted route allowlist');
    }
    if ($db->transactionCount !== 1) {
        fail_plan_route_management('plan and route mappings must be saved in one transaction');
    }

    try {
        $service->savePlan([
            'name' => 'Invalid',
            'product_code' => 'invalid',
            'allowed_forward_route_ids' => [15],
        ]);
        fail_plan_route_management('inactive route must be rejected');
    } catch (ApiException $exception) {
        if ($exception->getMessage() !== 'invalid_plan_forward_route_payload') {
            fail_plan_route_management('inactive route returned unexpected error: ' . $exception->getMessage());
        }
    }

    $adminSource = file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php') ?: '';
    foreach (['<label>Forward Routes</label>', 'allowed_forward_route_ids[]', 'renderForwardRoutePlanCheckboxes('] as $required) {
        if (!str_contains($adminSource, $required)) {
            fail_plan_route_management('admin route allowlist UI missing: ' . $required);
        }
    }
    foreach (['<label>Allowed Forward Combinations</label>', 'name="allowed_forward_combination_ids[]"'] as $forbidden) {
        if (str_contains($adminSource, $forbidden)) {
            fail_plan_route_management('admin plan UI still exposes combinations: ' . $forbidden);
        }
    }

    echo "ok\n";
}
