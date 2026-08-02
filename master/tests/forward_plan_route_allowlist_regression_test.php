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
        public int $fallbackRouteQueries = 0;

        /** @param list<array<string, mixed>> $rows */
        public function seed(string $table, array $rows): void
        {
            $this->tables[$table] = array_values($rows);
        }

        /** @param array<string, mixed> $params @return array<string, mixed>|null */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            $normalized = $this->normalize($sql);
            if (str_contains($normalized, 'SELECT * FROM services WHERE id = :id LIMIT 1')) {
                foreach ($this->tables['services'] ?? [] as $service) {
                    if ((int) ($service['id'] ?? 0) === (int) ($params['id'] ?? 0)) {
                        return $service;
                    }
                }
                return null;
            }
            if (str_contains($normalized, 'SELECT id FROM service_plan_forward_routes')) {
                foreach ($this->tables['service_plan_forward_routes'] ?? [] as $mapping) {
                    if ((int) ($mapping['plan_id'] ?? 0) === (int) ($params['plan_id'] ?? 0)) {
                        return $mapping;
                    }
                }
                return null;
            }
            if (str_contains($normalized, 'SELECT COUNT(*) AS combination_count')) {
                return ['combination_count' => 0, 'dedicated_count' => 0];
            }
            return null;
        }

        /** @param array<string, mixed> $params @return list<array<string, mixed>> */
        public function fetchAll(string $sql, array $params = []): array
        {
            $normalized = $this->normalize($sql);
            if (str_contains($normalized, 'FROM forward_routes fr INNER JOIN service_plan_forward_routes spfr')) {
                $mappedIds = [];
                foreach ($this->tables['service_plan_forward_routes'] ?? [] as $mapping) {
                    if ((int) ($mapping['plan_id'] ?? 0) === (int) ($params['plan_id'] ?? 0)) {
                        $mappedIds[(int) ($mapping['forward_route_id'] ?? 0)] = true;
                    }
                }
                $activeCombinationIds = [];
                foreach ($this->tables['forward_combinations'] ?? [] as $combination) {
                    if ((int) ($combination['is_active'] ?? 0) === 1) {
                        $activeCombinationIds[(int) ($combination['id'] ?? 0)] = true;
                    }
                }
                $routeIdsWithActiveCombination = [];
                foreach ($this->tables['forward_route_combinations'] ?? [] as $mapping) {
                    if ((int) ($mapping['enabled'] ?? 0) === 1
                        && isset($activeCombinationIds[(int) ($mapping['forward_combination_id'] ?? 0)])) {
                        $routeIdsWithActiveCombination[(int) ($mapping['forward_route_id'] ?? 0)] = true;
                    }
                }
                return array_values(array_filter($this->tables['forward_routes'] ?? [], static function (array $route) use ($mappedIds, $routeIdsWithActiveCombination): bool {
                    $routeId = (int) ($route['id'] ?? 0);
                    return (int) ($route['is_active'] ?? 0) === 1
                        && isset($mappedIds[$routeId])
                        && isset($routeIdsWithActiveCombination[$routeId]);
                }));
            }
            if (str_contains($normalized, 'SELECT * FROM forward_routes WHERE is_active = 1')) {
                $this->fallbackRouteQueries++;
                return array_values(array_filter($this->tables['forward_routes'] ?? [], static fn(array $route): bool => (int) ($route['is_active'] ?? 0) === 1));
            }
            return [];
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
    use Leikwan\Master\Support\ProvisioningService;

    function fail(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    $db = new Database();
    $db->seed('services', [[
        'id' => 42,
        'plan_id' => 6,
        'user_id' => 7,
        'status' => 'active',
    ]]);
    $db->seed('forward_routes', [
        ['id' => 14, 'is_active' => 1, 'tenant_label' => 'Mapped'],
        ['id' => 15, 'is_active' => 1, 'tenant_label' => 'Unmapped'],
    ]);
    $service = new ProvisioningService(new Config(''), $db);

    $unmapped = $service->availableForwardRoutesForService(42);
    if ($unmapped !== []) {
        fail('plan with no explicit route mappings must receive no routes');
    }
    if ($db->fallbackRouteQueries !== 0) {
        fail('route visibility used the all-active fallback query');
    }

    $db->seed('service_plan_forward_routes', [[
        'id' => 1,
        'plan_id' => 6,
        'forward_route_id' => 14,
    ]]);
    $mappedWithoutActiveCombination = $service->availableForwardRoutesForService(42);
    if ($mappedWithoutActiveCombination !== []) {
        fail('mapped route without an enabled active combination must stay hidden');
    }
    $db->seed('forward_combinations', [[
        'id' => 22,
        'is_active' => 1,
    ]]);
    $db->seed('forward_route_combinations', [[
        'forward_route_id' => 14,
        'forward_combination_id' => 22,
        'enabled' => 1,
    ]]);
    $mapped = $service->availableForwardRoutesForService(42);
    if (count($mapped) !== 1 || (int) ($mapped[0]['id'] ?? 0) !== 14) {
        fail('explicit route mapping must be the only visible route');
    }

    echo "ok\n";
}
