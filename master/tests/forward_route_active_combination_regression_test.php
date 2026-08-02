<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<int, array<string, int|string|null>> */
        private array $memberships = [
            [
                'route_id' => 9,
                'membership_id' => 1,
                'combination_id' => 11,
                'membership_sort_order' => 10,
                'enabled' => 1,
                'is_active' => 0,
                'backend_mode' => 'current_mode',
                'client_mode' => 'single',
                'sidecar_point_id' => null,
            ],
            [
                'route_id' => 9,
                'membership_id' => 2,
                'combination_id' => 22,
                'membership_sort_order' => 20,
                'enabled' => 1,
                'is_active' => 1,
                'backend_mode' => 'leigod_mode',
                'client_mode' => 'single',
                'sidecar_point_id' => null,
            ],
            [
                'route_id' => 10,
                'membership_id' => 3,
                'combination_id' => 33,
                'membership_sort_order' => 10,
                'enabled' => 1,
                'is_active' => 0,
                'backend_mode' => 'leigod_mode',
                'client_mode' => 'single',
                'sidecar_point_id' => null,
            ],
            [
                'route_id' => 10,
                'membership_id' => 4,
                'combination_id' => 44,
                'membership_sort_order' => 20,
                'enabled' => 1,
                'is_active' => 1,
                'backend_mode' => 'current_mode',
                'client_mode' => 'single',
                'sidecar_point_id' => null,
            ],
        ];

        public function fetchOne(string $sql, array $params = []): ?array
        {
            if (!str_contains($sql, 'forward_route_combinations')) {
                return null;
            }

            $routeId = (int) ($params['route_id'] ?? $params['id'] ?? 0);
            $rows = array_values(array_filter(
                $this->memberships,
                static fn(array $row): bool => (int) $row['route_id'] === $routeId
                    && (int) $row['enabled'] === 1
            ));
            if (str_contains($sql, 'fc.is_active = 1')) {
                $rows = array_values(array_filter(
                    $rows,
                    static fn(array $row): bool => (int) $row['is_active'] === 1
                ));
            }

            if (str_contains($sql, 'COUNT(*) AS combination_count')) {
                $dedicatedCount = count(array_filter(
                    $rows,
                    static fn(array $row): bool => $row['backend_mode'] === ($params['backend_mode'] ?? '')
                        && $row['client_mode'] === ($params['client_mode'] ?? '')
                        && $row['sidecar_point_id'] === null
                ));
                return [
                    'combination_count' => count($rows),
                    'dedicated_count' => $dedicatedCount,
                ];
            }

            usort($rows, static fn(array $left, array $right): int =>
                [(int) $left['membership_sort_order'], (int) $left['membership_id']]
                <=> [(int) $right['membership_sort_order'], (int) $right['membership_id']]
            );
            if ($rows === []) {
                return null;
            }
            return ['forward_combination_id' => (int) $rows[0]['combination_id']];
        }

        public function fetchAll(string $sql, array $params = []): array { return []; }
        public function execute(string $sql, array $params = []): int { return 0; }
        public function insert(string $table, array $row): int { return 1; }
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

    function failActiveCombination(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    function callActiveCombinationPrivate(object $instance, string $method, mixed ...$args): mixed
    {
        $reflection = new ReflectionMethod($instance, $method);
        $reflection->setAccessible(true);
        return $reflection->invoke($instance, ...$args);
    }

    $envPath = tempnam(sys_get_temp_dir(), 'lw-route-active-combo-');
    if ($envPath === false) {
        failActiveCombination('unable to create temp env');
    }
    file_put_contents($envPath, "DEFAULT_TLS_CERT_REF=default-edge-cert\n");

    $service = new ProvisioningService(new Config($envPath), new Database());

    $source = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php') ?: '';
    $availableStart = strpos($source, 'public function availableForwardRoutesForService(');
    $availableEnd = strpos($source, 'private function visibleForwardRouteForService(', $availableStart === false ? 0 : $availableStart);
    $availableSource = ($availableStart !== false && $availableEnd !== false)
        ? substr($source, $availableStart, $availableEnd - $availableStart)
        : '';
    if (!str_contains($availableSource, 'INNER JOIN forward_route_combinations')
        || !str_contains($availableSource, 'INNER JOIN forward_combinations')
        || !str_contains($availableSource, 'fc.is_active = 1')) {
        failActiveCombination('tenant route options must exclude routes without enabled active combinations');
    }

    $primary = $service->primaryCombinationIdForRoute(9);
    if ($primary !== 22) {
        failActiveCombination('primary route combination must skip inactive combination 11; got ' . $primary);
    }
    if (callActiveCombinationPrivate($service, 'forwardRouteUsesDedicatedLeigod', 9) !== true) {
        failActiveCombination('inactive non-dedicated combination must not disable dedicated route classification');
    }
    if (callActiveCombinationPrivate($service, 'forwardRouteUsesDedicatedLeigod', 10) !== false) {
        failActiveCombination('inactive dedicated combination must not enable dedicated route classification');
    }

    @unlink($envPath);
    echo "ok\n";
}
