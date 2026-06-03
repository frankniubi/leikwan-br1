<?php
declare(strict_types=1);

// leigod_mode is a first-class Forward Mode (peer to current_mode / cf_mode). It means DIRECT
// client->slave ingress (like current_mode, no Cloudflare, no self-leg CF) + a LeiGod slave<->sidecar
// self-leg via the lguot UoT carrier. saveForwardCombination must:
//   1. accept backend_mode='leigod_mode' (validation),
//   2. DERIVE self_leg_outer_transport from the Forward Mode (picker retired):
//        leigod_mode -> leigod (+ leigod_uot_impl default 'lguot', all CF coupling cleared)
//        cf_mode     -> cf
//        current_mode-> cnix
//   3. persist leigod_uot_impl (previously never written by saveForwardCombination),
//   4. reuse the existing leigod guards (line/sidecar required) under leigod_mode.
// A capturing Database fake records the persisted forward_combinations row.

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<string, array<int, array<string, mixed>>> */
        public static array $inserts = [];

        /** @param array<int, array<string, mixed>> $rows */
        public function seed(string $table, array $rows): void {}

        /** @param array<string, mixed> $params */
        public function fetchOne(string $sql, array $params = []): ?array { return null; }

        /** @param array<string, mixed> $params */
        public function fetchAll(string $sql, array $params = []): array { return []; }

        /** @param array<string, mixed> $params */
        public function execute(string $sql, array $params = []): int { return 0; }

        /** @param array<string, mixed> $row */
        public function insert(string $table, array $row): int
        {
            self::$inserts[$table][] = $row;
            return 42;
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

    /** @return array<string, mixed> */
    function lastCombo(): array
    {
        $rows = Database::$inserts['forward_combinations'] ?? [];
        if (count($rows) !== 1) {
            fail('expected exactly one forward_combinations insert, got ' . count($rows));
        }
        return $rows[0];
    }

    $envPath = tempnam(sys_get_temp_dir(), 'lw-leigod-mode-env-');
    if ($envPath === false) {
        fail('failed to create temp env file');
    }
    file_put_contents($envPath, "DEFAULT_TLS_CERT_REF=default-edge-cert\n");

    $service = new ProvisioningService(new Config($envPath), new Database());

    $base = [
        'name' => 'leigod-mode-combo',
        'tenant_label' => 'Leigod',
        'node_group_key' => 'sha-tencent',
        'backend_mode' => 'leigod_mode',
        'sidecar_point_id' => 4,
        'client_mode' => 'single',
        'runtime_preference' => 'single',
        'is_active' => 1,
        'leigod_line_id' => 453,
    ];

    // 1. leigod_mode is accepted and derives the leigod self-leg + lguot + cleared CF coupling.
    Database::$inserts = [];
    $service->saveForwardCombination($base);
    $row = lastCombo();
    $expect = [
        'backend_mode' => 'leigod_mode',
        'self_leg_outer_transport' => 'leigod',
        'leigod_uot_impl' => 'lguot',
        'allow_private_self_leg' => 0,
        'self_leg_cf_transport' => 'cf_direct',
        'leigod_line_id' => 453,
    ];
    foreach ($expect as $k => $v) {
        if (!array_key_exists($k, $row)) {
            fail("leigod_mode insert missing key: $k");
        }
        if ($row[$k] !== $v) {
            fail("leigod_mode persisted $k = " . var_export($row[$k], true) . ', expected ' . var_export($v, true));
        }
    }
    if (!array_key_exists('cf_location_group_id', $row) || $row['cf_location_group_id'] !== null) {
        fail('leigod_mode must persist cf_location_group_id=null, got ' . var_export($row['cf_location_group_id'] ?? 'MISSING', true));
    }

    // 2. leigod_mode without a line id reuses the existing guard.
    $p = $base;
    $p['leigod_line_id'] = 0;
    try {
        $service->saveForwardCombination($p);
        fail('expected leigod_line_required for leigod_mode with no line id');
    } catch (ApiException $e) {
        if ($e->getMessage() !== 'leigod_line_required') {
            fail('expected leigod_line_required, got ' . $e->getMessage());
        }
    }

    // 3. leigod_mode without a sidecar reuses the existing guard.
    $p = $base;
    $p['sidecar_point_id'] = 0;
    try {
        $service->saveForwardCombination($p);
        fail('expected a sidecar-required failure for leigod_mode with no sidecar');
    } catch (ApiException $e) {
        if (!str_contains($e->getMessage(), 'sidecar')) {
            fail('expected sidecar-related message, got ' . $e->getMessage());
        }
    }

    // 4. Back-compat: current_mode derives cnix self-leg and keeps leigod_uot_impl='gost'.
    Database::$inserts = [];
    $p = $base;
    $p['backend_mode'] = 'current_mode';
    unset($p['leigod_line_id']);
    $service->saveForwardCombination($p);
    $row = lastCombo();
    if (($row['self_leg_outer_transport'] ?? '') !== 'cnix') {
        fail('current_mode must derive self_leg=cnix, got ' . var_export($row['self_leg_outer_transport'] ?? '', true));
    }
    if (($row['leigod_uot_impl'] ?? '') !== 'gost') {
        fail('current_mode must persist leigod_uot_impl=gost, got ' . var_export($row['leigod_uot_impl'] ?? '', true));
    }

    // 5. cf_mode derives self_leg=cf (a realization no-op, but keeps the column honest).
    Database::$inserts = [];
    $p = $base;
    $p['backend_mode'] = 'cf_mode';
    unset($p['leigod_line_id']);
    $p['cf_location_group_id'] = 1;
    $service->saveForwardCombination($p);
    $row = lastCombo();
    if (($row['self_leg_outer_transport'] ?? '') !== 'cf') {
        fail('cf_mode must derive self_leg=cf, got ' . var_export($row['self_leg_outer_transport'] ?? '', true));
    }

    echo "ok\n";
}
