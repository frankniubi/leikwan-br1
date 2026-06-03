<?php
declare(strict_types=1);

// B2: saveForwardRoute must reject a route whose ENABLED combos do not share the client->slave ingress
// realization (backend_mode + node_group_key + client_mode + bond_ingress_mode), per B0. Combos that
// differ only in the slave<->sidecar basis (e.g. cnix vs leigod, both current_mode) are accepted.

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<int, array<string,mixed>> */
        public array $combos = [];

        public function seed(string $table, array $rows): void {}

        /** @param array<string,mixed> $params */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            if (str_contains($sql, 'FROM forward_combinations') && isset($params['id'])) {
                return $this->combos[(int) $params['id']] ?? null;
            }
            return null;
        }

        /** @param array<string,mixed> $params */
        public function fetchAll(string $sql, array $params = []): array { return []; }

        /** @param array<string,mixed> $params */
        public function execute(string $sql, array $params = []): int { return 0; }

        /** @param array<string,mixed> $row */
        public function insert(string $table, array $row): int { return 7; }
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
    use Leikwan\Master\Support\ApiException;
    use Leikwan\Master\Support\ProvisioningService;

    function fail(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    $envPath = tempnam(sys_get_temp_dir(), 'lw-fr-guard-env-');
    file_put_contents($envPath, "DEFAULT_TLS_CERT_REF=default-edge-cert\n");

    $db = new Database();
    $ingress = ['node_group_key' => 'sha', 'client_mode' => 'bond', 'bond_ingress_mode' => 'plain_tcp_mux', 'sidecar_point_id' => 4];
    $db->combos = [
        10 => ['backend_mode' => 'current_mode'] + $ingress,  // cnix
        11 => ['backend_mode' => 'current_mode'] + $ingress,  // leigod (same ingress + same sidecar, differs only in self-leg)
        12 => ['backend_mode' => 'cf_mode'] + $ingress,       // cf-bond (different backend_mode -> different ingress)
        13 => ['backend_mode' => 'current_mode'] + ['node_group_key' => 'sha', 'client_mode' => 'bond', 'bond_ingress_mode' => 'plain_tcp_mux', 'sidecar_point_id' => 5], // same backend_mode, DIFFERENT sidecar
    ];
    $svc = new ProvisioningService(new Config($envPath), $db);

    // Same-ingress route (cnix + leigod) -> OK.
    $r = $svc->saveForwardRoute(['name' => 'r1', 'combinations' => [
        ['forward_combination_id' => 10], ['forward_combination_id' => 11],
    ]]);
    if ((int) ($r['id'] ?? 0) !== 7) {
        fail('expected route id 7 for same-ingress combos');
    }

    // Mixed backend_mode (cnix + cf) -> ingress mismatch.
    try {
        $svc->saveForwardRoute(['name' => 'r2', 'combinations' => [
            ['forward_combination_id' => 10], ['forward_combination_id' => 12],
        ]]);
        fail('expected forward_route_combos_ingress_mismatch for mixed backend_mode');
    } catch (ApiException $e) {
        if ($e->getMessage() !== 'forward_route_combos_ingress_mismatch') {
            fail('expected ingress_mismatch, got ' . $e->getMessage());
        }
    }

    // Same backend_mode but DIFFERENT sidecar_point_id (cnix point 4 vs point 5) -> mismatch
    // (candidates must share one sidecar service port for the selector to race them — R2).
    try {
        $svc->saveForwardRoute(['name' => 'r-sidecar', 'combinations' => [
            ['forward_combination_id' => 10], ['forward_combination_id' => 13],
        ]]);
        fail('expected forward_route_combos_ingress_mismatch for different sidecar_point_id');
    } catch (ApiException $e) {
        if ($e->getMessage() !== 'forward_route_combos_ingress_mismatch') {
            fail('expected ingress_mismatch for different sidecar, got ' . $e->getMessage());
        }
    }

    // Disabling the cf combo removes it from the check -> OK.
    $r3 = $svc->saveForwardRoute(['name' => 'r3', 'combinations' => [
        ['forward_combination_id' => 10, 'enabled' => 1],
        ['forward_combination_id' => 12, 'enabled' => 0],
    ]]);
    if ((int) ($r3['id'] ?? 0) !== 7) {
        fail('expected route id 7 when cf combo disabled');
    }

    // Empty name -> invalid.
    try {
        $svc->saveForwardRoute(['name' => '']);
        fail('expected invalid_forward_route_payload');
    } catch (ApiException $e) {
        if ($e->getMessage() !== 'invalid_forward_route_payload') {
            fail('expected invalid_forward_route_payload, got ' . $e->getMessage());
        }
    }

    echo "ok\n";
}
