<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<int, array<string, mixed>> */
        public array $routes = [];
        /** @var array<int, array<string, mixed>> */
        public array $availabilityRows = [];

        public function fetchOne(string $sql, array $params = []): ?array
        {
            if (str_contains($sql, 'FROM forward_routes')) {
                return $this->routes[(int) ($params['id'] ?? 0)] ?? null;
            }
            return null;
        }

        public function fetchAll(string $sql, array $params = []): array
        {
            if (str_contains($sql, 'FROM forward_routes fr')) {
                $routeId = (int) ($params['route_id'] ?? 0);
                return array_values(array_filter(
                    $this->availabilityRows,
                    static fn(array $row): bool => (int) ($row['route_id'] ?? 0) === $routeId
                ));
            }
            return [];
        }

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
    use Leikwan\Master\Support\LeigodLineCatalogService;

    function failAvailability(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    function assertAvailabilitySame(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            failAvailability($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    $toml451 = "line = 451\n";
    $toml453 = "line = 453\n";
    $hash451 = hash('sha256', $toml451);
    $hash453 = hash('sha256', $toml453);
    $envPath = tempnam(sys_get_temp_dir(), 'lw-route-availability-');
    if ($envPath === false) {
        failAvailability('unable to create temp env');
    }
    file_put_contents($envPath, implode("\n", [
        'LEIGOD_LINE_TOML_451=' . base64_encode($toml451),
        'LEIGOD_LINE_TOML_453=' . base64_encode($toml453),
        'LEIGOD_LINE_HEALTH_MAX_AGE_SECONDS=180',
        'FORWARD_ROUTE_RACE_CANDIDATE_CAP=1',
        '',
    ]));

    $db = new Database();
    $db->routes = [
        8 => ['id' => 8, 'race_enabled' => 0, 'is_active' => 1],
        9 => ['id' => 9, 'race_enabled' => 1, 'is_active' => 1],
    ];
    $base = [
        'node_name' => 'node-12',
        'node_public_ip' => '203.0.113.12',
        'node_status' => 'active',
        'line_is_active' => 1,
        'service_state' => 'active',
        'tcp_status' => 'healthy',
        'udp_status' => 'unhealthy',
        'checked_at' => gmdate('Y-m-d H:i:s'),
        'combination_sort_order' => 10,
        'membership_sort_order' => 10,
    ];
    $db->availabilityRows = [
        array_replace($base, ['route_id' => 9, 'node_id' => 12, 'combination_id' => 10, 'line_id' => 451, 'config_sha256' => $hash451, 'health_config_sha256' => $hash451, 'tcp_connect_ms' => 12]),
        array_replace($base, ['route_id' => 9, 'node_id' => 12, 'combination_id' => 11, 'line_id' => 453, 'config_sha256' => $hash453, 'health_config_sha256' => $hash453, 'tcp_connect_ms' => 18, 'combination_sort_order' => 20]),
        array_replace($base, ['route_id' => 9, 'node_id' => 13, 'node_name' => 'node-13', 'node_public_ip' => '203.0.113.13', 'combination_id' => 10, 'line_id' => 451, 'config_sha256' => $hash451, 'health_config_sha256' => str_repeat('f', 64)]),
        array_replace($base, ['route_id' => 8, 'node_id' => 12, 'combination_id' => 10, 'line_id' => 451, 'config_sha256' => $hash451, 'health_config_sha256' => $hash451, 'tcp_connect_ms' => 12]),
    ];

    $originalTimezone = date_default_timezone_get();
    date_default_timezone_set('Asia/Taipei');
    $catalog = new LeigodLineCatalogService(new Config($envPath), $db);
    $nodeIds = static fn(array $nodes): array => array_map(static fn(array $node): int => (int) ($node['id'] ?? 0), $nodes);
    assertAvailabilitySame([12], $nodeIds($catalog->eligibleNodesForRoute(9, 'tcp')), 'race eligible nodes');
    assertAvailabilitySame(2, count($catalog->eligibleNodesForRoute(9, 'tcp')[0]['candidates'] ?? []), 'race cap preserves two combinations');
    assertAvailabilitySame(false, $catalog->nodeSupportsRoute(13, 9, 'tcp'), 'hash mismatch node');
    assertAvailabilitySame(false, $catalog->nodeSupportsRoute(12, 8, 'udp'), 'single UDP gate');
    assertAvailabilitySame(true, $catalog->nodeSupportsRoute(12, 8, 'tcp'), 'single TCP gate');

    foreach ($db->availabilityRows as &$row) {
        if ((int) ($row['route_id'] ?? 0) === 9 && (int) ($row['node_id'] ?? 0) === 12 && (int) ($row['line_id'] ?? 0) === 453) {
            $row['checked_at'] = gmdate('Y-m-d H:i:s', time() - 181);
        }
    }
    unset($row);
    assertAvailabilitySame([], $nodeIds($catalog->eligibleNodesForRoute(9, 'tcp')), 'stale race combination removes node');
    $availability = $catalog->routeAvailability(9, 'tcp');
    assertAvailabilitySame(false, $availability['available'] ?? null, 'race availability after stale health');
    assertAvailabilitySame('leigod_route_no_healthy_candidate', $availability['unavailable_reason'] ?? null, 'unavailable reason');

    $provisioningSource = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php') ?: '';
    $saveStart = strpos($provisioningSource, 'private function saveForward(');
    $saveEnd = strpos($provisioningSource, 'private function revokeForwardEndpoints(', $saveStart !== false ? $saveStart : 0);
    $saveBlock = $saveStart !== false && $saveEnd !== false
        ? substr($provisioningSource, $saveStart, $saveEnd - $saveStart)
        : '';
    $preflightPosition = strpos($saveBlock, "throw new ApiException('leigod_route_no_healthy_candidate'");
    $insertPosition = strpos($saveBlock, "\$this->db->insert('forwards'");
    if ($preflightPosition === false || $insertPosition === false || $preflightPosition >= $insertPosition) {
        failAvailability('saveForward health preflight must run before the forward write');
    }
    foreach (['eligibleNodesForRoute(', 'eligibleNodesForCombination(', 'healthy_node_count', 'unavailable_reason'] as $needle) {
        if (!str_contains($provisioningSource, $needle)) {
            failAvailability('ProvisioningService.php missing placement contract ' . $needle);
        }
    }

    date_default_timezone_set($originalTimezone);
    @unlink($envPath);
    echo "ok\n";
}
