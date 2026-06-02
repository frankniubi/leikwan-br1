<?php
declare(strict_types=1);

// B5: the tenant add-forward UI selects a Forward Route (not a combination); the route resolves to its
// primary (lowest sort_order enabled) combination, and saveForward persists forward_route_id +
// active_combination_id. Unit test for primaryCombinationIdForRoute + source-grep for the wiring.

namespace Leikwan\Master\Database {
    final class Database
    {
        public function seed(string $table, array $rows): void {}
        public function fetchOne(string $sql, array $params = []): ?array
        {
            if (str_contains($sql, 'forward_route_combinations')) {
                // primary = lowest sort_order enabled; return the seeded "winner".
                return ['forward_combination_id' => 55];
            }
            return null;
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

    function fail(string $m): never { fwrite(STDERR, $m . PHP_EOL); exit(1); }
    $fail = 0;

    $envPath = tempnam(sys_get_temp_dir(), 'lw-fr-tenant-env-');
    file_put_contents($envPath, "DEFAULT_TLS_CERT_REF=default-edge-cert\n");
    $svc = new ProvisioningService(new Config($envPath), new Database());

    // Unit: route -> primary combination.
    $cid = $svc->primaryCombinationIdForRoute(9);
    if ($cid !== 55) { fail('primaryCombinationIdForRoute expected 55, got ' . $cid); }
    if ($svc->primaryCombinationIdForRoute(0) !== 0) { fail('route 0 must resolve to 0'); }

    // Wiring (source-grep).
    $tenant = file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php') ?: '';
    $prov = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php') ?: '';
    foreach ([
        ['name="forward_route_id"', $tenant, 'tenant route dropdown'],
        ["'forward_route_id' => max(0", $tenant, 'tenant payload parse'],
        ['availableForwardRoutesForService', $tenant, 'tenant calls route availability'],
        ['function availableForwardRoutesForService', $prov, 'service exposes route availability'],
        ['primaryCombinationIdForRoute($forwardRouteId)', $prov, 'saveForward resolves route -> combo'],
        ["'forward_route_id' => \$forwardRouteId > 0", $prov, 'saveForward persists forward_route_id'],
        ['active_combination_id = :active_combination_id', $prov, 'saveForward UPDATE persists active_combination_id'],
    ] as [$needle, $hay, $where]) {
        if (!str_contains($hay, $needle)) { fwrite(STDERR, "FAIL: $where missing ($needle)\n"); $fail++; }
    }

    if ($fail) { exit(1); }
    echo "ok\n";
}
