<?php
declare(strict_types=1);

// leigod_mode is a first-class Forward Mode (peer to current_mode / cf_mode). It means DIRECT
// client->slave ingress (like current_mode, no Cloudflare, no self-leg CF) + a LeiGod basis when
// aggregation uses a sidecar. Dedicated leigod_mode/single uses the slave as the forward node and
// therefore does not require or persist a sidecar. saveForwardCombination must:
//   1. accept backend_mode='leigod_mode' (validation),
//   2. DERIVE self_leg_outer_transport from the Forward Mode (picker retired):
//        leigod_mode -> leigod (+ leigod_uot_impl default 'lguot', all CF coupling cleared)
//        cf_mode     -> cf
//        current_mode-> cnix
//   3. persist leigod_uot_impl (previously never written by saveForwardCombination),
//   4. require a LeiGod line for leigod_mode, and require sidecar only for aggregation.
// A capturing Database fake records the persisted forward_combinations row.

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<string, array<int, array<string, mixed>>> */
        public static array $inserts = [];

        /** @param array<int, array<string, mixed>> $rows */
        public function seed(string $table, array $rows): void {}

        /** @param array<string, mixed> $params */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            $normalized = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
            if (str_contains($normalized, 'FROM sidecar_points sp')) {
                $id = (int) ($params['id'] ?? 0);
                if ($id === 4) {
                    return [
                        'id' => 4,
                        'is_active' => 1,
                        'sidecar_host' => '198.51.100.4',
                        'listen_port' => 8899,
                        'bond_listen_port' => 8899,
                        'mptcp_listen_port' => 8898,
                        'agent_config_json' => json_encode(['aggregation_region_scope' => 'overseas', 'country_code' => 'JP'], JSON_UNESCAPED_SLASHES),
                        'owner_email' => null,
                        'owner_name' => null,
                    ];
                }
                if ($id === 5) {
                    return [
                        'id' => 5,
                        'is_active' => 0,
                        'sidecar_host' => '198.51.100.5',
                        'listen_port' => 8899,
                        'bond_listen_port' => 8899,
                        'mptcp_listen_port' => 8898,
                        'agent_config_json' => json_encode(['aggregation_region_scope' => 'overseas', 'country_code' => 'JP'], JSON_UNESCAPED_SLASHES),
                        'owner_email' => null,
                        'owner_name' => null,
                    ];
                }
            }
            return null;
        }

        /** @param array<string, mixed> $params */
        public function fetchAll(string $sql, array $params = []): array
        {
            if (str_contains($sql, 'FROM ultra_zones')) {
                $codes = ($params['zone_role'] ?? '') === 'ingress' ? ['shanghai'] : ['tokyo'];
                return array_map(static fn(string $code): array => ['zone_code' => $code], $codes);
            }
            if (str_contains($sql, 'FROM ultra_lines')) {
                return [[
                    'id' => 10,
                    'line_id' => 453,
                    'is_source_present' => 1,
                    'is_enabled' => 1,
                    'effective_ingress_zone' => 'shanghai',
                    'effective_destination_zone' => 'tokyo',
                ]];
            }
            return [];
        }

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

    // 1. leigod_mode/single is accepted as dedicated forward-node mode and clears sidecar/CF coupling.
    Database::$inserts = [];
    $service->saveForwardCombination($base);
    $row = lastCombo();
    $expect = [
        'backend_mode' => 'leigod_mode',
        'client_mode' => 'single',
        'self_leg_outer_transport' => 'leigod',
        'leigod_uot_impl' => 'lguot',
        'allow_private_self_leg' => 0,
        'self_leg_cf_transport' => 'cf_direct',
        'leigod_line_id' => 453,
        'helper_group_cap' => 0,
    ];
    foreach ($expect as $k => $v) {
        if (!array_key_exists($k, $row)) {
            fail("leigod_mode insert missing key: $k");
        }
        if ($row[$k] !== $v) {
            fail("leigod_mode persisted $k = " . var_export($row[$k], true) . ', expected ' . var_export($v, true));
        }
    }
    if (!array_key_exists('sidecar_point_id', $row) || $row['sidecar_point_id'] !== null) {
        fail('dedicated leigod_mode must persist sidecar_point_id=null, got ' . var_export($row['sidecar_point_id'] ?? 'MISSING', true));
    }
    if (!array_key_exists('cf_location_group_id', $row) || $row['cf_location_group_id'] !== null) {
        fail('leigod_mode must persist cf_location_group_id=null, got ' . var_export($row['cf_location_group_id'] ?? 'MISSING', true));
    }

    // 2. leigod_mode without a line id keeps the line guard.
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

    // 3. dedicated leigod_mode without a sidecar is valid and persists sidecar_point_id=null.
    Database::$inserts = [];
    $p = $base;
    $p['sidecar_point_id'] = 0;
    $service->saveForwardCombination($p);
    $row = lastCombo();
    $persistedSidecarPointId = array_key_exists('sidecar_point_id', $row) ? $row['sidecar_point_id'] : 'MISSING';
    if (($row['client_mode'] ?? null) !== 'single' || $persistedSidecarPointId !== null) {
        fail('dedicated leigod_mode without sidecar must persist single/null, got client_mode=' . var_export($row['client_mode'] ?? null, true) . ' sidecar=' . var_export($persistedSidecarPointId, true));
    }

    // 4. aggregation still requires an active overseas sidecar.
    $p = $base;
    $p['client_mode'] = 'bond';
    $p['sidecar_point_id'] = 0;
    try {
        $service->saveForwardCombination($p);
        fail('expected a sidecar-required failure for leigod aggregation with no sidecar');
    } catch (ApiException $e) {
        if (!str_contains($e->getMessage(), 'sidecar')) {
            fail('expected sidecar-related message, got ' . $e->getMessage());
        }
    }

    $p = $base;
    $p['client_mode'] = 'bond';
    $p['sidecar_point_id'] = 5;
    try {
        $service->saveForwardCombination($p);
        fail('expected inactive sidecar to be rejected for leigod aggregation');
    } catch (ApiException $e) {
        if ($e->getMessage() !== 'sidecar_point_not_allowed_for_aggregation') {
            fail('expected sidecar_point_not_allowed_for_aggregation, got ' . $e->getMessage());
        }
    }

    // 5. Back-compat: current_mode derives cnix self-leg and keeps leigod_uot_impl='gost'.
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

    // 6. cf_mode derives self_leg=cf (a realization no-op, but keeps the column honest).
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

    // 7. ultra is a separate single-runtime capability mode and must not persist legacy LeiGod state.
    Database::$inserts = [];
    $p = $base;
    $p['backend_mode'] = 'ultra';
    $p['sidecar_point_id'] = 4;
    $p['leigod_line_id'] = 453;
    $p['line_direction'] = 'Shanghai to Japan';
    $p['leigod_socks_user'] = 'legacy-user-must-be-cleared';
    $p['leigod_socks_pass'] = 'legacy-pass-must-be-cleared';
    $p['ultra_destination_zone'] = 'tokyo';
    $p['ultra_allowed_ingress_zones'] = ['shanghai'];
    $p['ultra_fallback_policy'] = 'detected_then_any';
    $p['ultra_lines'] = [['ultra_line_id' => 10, 'enabled' => 1, 'sort_order' => 10]];
    $service->saveForwardCombination($p);
    $row = lastCombo();
    foreach (['backend_mode' => 'ultra', 'client_mode' => 'single', 'runtime_preference' => 'single', 'self_leg_outer_transport' => 'cnix', 'helper_group_cap' => 0, 'line_direction' => 'Shanghai to Japan', 'ultra_destination_zone' => 'tokyo', 'ultra_fallback_policy' => 'detected_then_any'] as $k => $v) {
        if (($row[$k] ?? null) !== $v) {
            fail("ultra persisted $k = " . var_export($row[$k] ?? null, true) . ', expected ' . var_export($v, true));
        }
    }
    foreach (['sidecar_point_id', 'cf_location_group_id', 'leigod_line_id', 'leigod_socks_user', 'leigod_socks_pass'] as $key) {
        if (($row[$key] ?? null) !== null) {
            fail('ultra must clear legacy field ' . $key . ', got ' . var_export($row[$key] ?? null, true));
        }
    }

    Database::$inserts = [];
    $ultraWithoutSlaveGroup = $p;
    $ultraWithoutSlaveGroup['node_group_key'] = '';
    $service->saveForwardCombination($ultraWithoutSlaveGroup);
    $row = lastCombo();
    if (($row['node_group_key'] ?? null) !== 'ultra-control-plane') {
        fail('ultra without a slave group must persist the control-plane sentinel');
    }

    $missingUltraLine = $p;
    $missingUltraLine['ultra_lines'] = [];
    try {
        $service->saveForwardCombination($missingUltraLine);
        fail('expected ultra_line_required for ultra with no line id');
    } catch (ApiException $e) {
        if ($e->getMessage() !== 'ultra_line_required') {
            fail('expected ultra_line_required, got ' . $e->getMessage());
        }
    }

    $p['client_mode'] = 'bond';
    try {
        $service->saveForwardCombination($p);
        fail('expected ultra_requires_single_runtime for ultra aggregation');
    } catch (ApiException $e) {
        if ($e->getMessage() !== 'ultra_requires_single_runtime') {
            fail('expected ultra_requires_single_runtime, got ' . $e->getMessage());
        }
    }

    $serviceReflection = new \ReflectionClass(ProvisioningService::class);
    $sidecarRequired = $serviceReflection->getMethod('sidecarRequiredForRuntime');
    $sidecarRequired->setAccessible(true);
    if ($sidecarRequired->invoke($service, 'ultra', 'single', 'single') !== false) {
        fail('ultra must not require a sidecar');
    }
    $activeFamily = $serviceReflection->getMethod('activeFamilyForRuntime');
    $activeFamily->setAccessible(true);
    if ($activeFamily->invoke($service, 'ultra', 'single', 'single') !== 'ultra') {
        fail('ultra active_family must be ultra');
    }
    $targetContract = $serviceReflection->getMethod('targetContractForRuntime');
    $targetContract->setAccessible(true);
    if ($targetContract->invoke($service, 'ultra', 'single', 'single') !== 'ultra') {
        fail('ultra target_contract must be ultra');
    }
    $dedicatedLeigod = $serviceReflection->getMethod('isDedicatedLeigodRuntime');
    $dedicatedLeigod->setAccessible(true);
    if ($dedicatedLeigod->invoke($service, 'ultra', 'single', 'single') !== false
        || $dedicatedLeigod->invoke($service, 'leigod_mode', 'single', 'single') !== true) {
        fail('ultra/legacy dedicated runtime boundary changed');
    }

    echo "ok\n";
}
