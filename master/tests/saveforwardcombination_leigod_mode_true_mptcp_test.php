<?php
declare(strict_types=1);

// LeiGod-only app contract regression coverage:
//   * leigod_mode + single is the dedicated forward-node path and must stay single/sidecar-less.
//   * explicit leigod_mode + mptcp/bond is aggregation and must keep the sidecar contract.
//   * combo-backed forwards read forwards.client_mode and forwards.bond_sidecar_point_id, so
//     saveForwardCombination must keep the denormalized forwards row aligned with the combination.

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<string, array<int, array<string, mixed>>> */
        public static array $inserts = [];

        /** @var array<int, array{sql: string, params: array<string, mixed>}> */
        public static array $executes = [];

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
            }
            return null;
        }

        /** @param array<string, mixed> $params */
        public function fetchAll(string $sql, array $params = []): array { return []; }

        /** @param array<string, mixed> $params */
        public function execute(string $sql, array $params = []): int
        {
            self::$executes[] = ['sql' => $sql, 'params' => $params];
            return 0;
        }

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
    use Leikwan\Master\Support\ProvisioningService;

    function fail(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    function assertSame(mixed $expected, mixed $actual, string $message): void
    {
        if ($actual !== $expected) {
            fail($message . ': got ' . var_export($actual, true) . ', expected ' . var_export($expected, true));
        }
    }

    function call_private(object $instance, string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod($instance, $method);
        $ref->setAccessible(true);
        return $ref->invoke($instance, ...$args);
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

    /** @return array{sql: string, params: array<string, mixed>} */
    function capturedForwardsUpdate(): array
    {
        foreach (Database::$executes as $execute) {
            if (str_contains($execute['sql'], 'UPDATE forwards')) {
                return $execute;
            }
        }
        fail('expected saveForwardCombination to execute UPDATE forwards');
    }

    function assertForwardsIngressSync(string $expectedClientMode, ?int $expectedSidecarPointId): void
    {
        $execute = capturedForwardsUpdate();
        if (!str_contains($execute['sql'], 'client_mode = :client_mode')) {
            fail('UPDATE forwards must set client_mode = :client_mode; SQL was: ' . $execute['sql']);
        }
        if (!str_contains($execute['sql'], 'bond_sidecar_point_id = :bond_sidecar_point_id')) {
            fail('UPDATE forwards must set bond_sidecar_point_id = :bond_sidecar_point_id; SQL was: ' . $execute['sql']);
        }
        assertSame(
            $expectedClientMode,
            $execute['params']['client_mode'] ?? null,
            'UPDATE forwards must carry normalized client_mode'
        );
        assertSame(
            $expectedSidecarPointId,
            array_key_exists('bond_sidecar_point_id', $execute['params']) ? $execute['params']['bond_sidecar_point_id'] : 'MISSING',
            'UPDATE forwards must carry normalized sidecar point id'
        );
    }

    /** @return array<string, mixed> */
    function saveCombo(ProvisioningService $service, array $payload): array
    {
        Database::$inserts = [];
        Database::$executes = [];
        $service->saveForwardCombination($payload);
        return lastCombo();
    }

    $envPath = tempnam(sys_get_temp_dir(), 'lw-leigod-true-mptcp-env-');
    if ($envPath === false) {
        fail('failed to create temp env file');
    }
    file_put_contents($envPath, "DEFAULT_TLS_CERT_REF=default-edge-cert\n");

    $service = new ProvisioningService(new Config($envPath), new Database());

    $base = [
        'name' => 'leigod-mode-app-contract',
        'tenant_label' => 'Leigod',
        'node_group_key' => 'sha-tencent',
        'backend_mode' => 'leigod_mode',
        'sidecar_point_id' => 4,
        'leigod_line_id' => 453,
        'runtime_preference' => 'single',
        'is_active' => 1,
    ];

    // leigod_mode + single is dedicated forward-node mode. It must not be normalized to MPTCP and
    // must not retain a stale sidecar because the app should target the selected slave directly.
    $row = saveCombo($service, $base + [
        'client_mode' => 'single',
        'sidecar_point_id' => 0,
        'helper_group_cap' => 0,
    ]);
    assertSame('single', $row['client_mode'] ?? null, 'leigod_mode/single must persist client_mode=single');
    assertSame('single', $row['runtime_preference'] ?? null, 'leigod_mode/single must persist runtime_preference=single');
    assertSame(null, array_key_exists('sidecar_point_id', $row) ? $row['sidecar_point_id'] : 'MISSING', 'leigod_mode/single must persist sidecar_point_id=null');
    assertSame(0, $row['helper_group_cap'] ?? null, 'dedicated leigod_mode/single must not default to aggregation helper legs');
    assertForwardsIngressSync('single', null);

    // Explicit MPTCP stays true MPTCP, clears hybrid fallback under leigod_mode, and keeps the sidecar.
    $row = saveCombo($service, $base + [
        'client_mode' => 'mptcp',
        'runtime_preference' => 'mptcp_preferred_bond_fallback',
    ]);
    assertSame('mptcp', $row['client_mode'] ?? null, 'explicit leigod_mode/mptcp must stay mptcp');
    assertSame('single', $row['runtime_preference'] ?? null, 'explicit leigod_mode/mptcp must coerce runtime_preference=single');
    assertSame(4, $row['sidecar_point_id'] ?? null, 'explicit leigod_mode/mptcp must keep sidecar_point_id');
    assertSame(4, $row['helper_group_cap'] ?? null, 'leigod_mode/mptcp must default helper_group_cap to 4');
    assertForwardsIngressSync('mptcp', 4);
    assertSame(
        'sidecar_aggregation',
        call_private($service, 'activeFamilyForRuntime', 'leigod_mode', 'mptcp', 'single'),
        'leigod_mode/mptcp app contract must be sidecar_aggregation'
    );

    // Explicit bond is the aggregation escape hatch and must stay bond all the way into dependent forwards.
    $row = saveCombo($service, $base + [
        'client_mode' => 'bond',
    ]);
    assertSame('bond', $row['client_mode'] ?? null, 'explicit leigod_mode/bond must persist client_mode=bond');
    assertSame(4, $row['sidecar_point_id'] ?? null, 'explicit leigod_mode/bond must keep sidecar_point_id');
    assertForwardsIngressSync('bond', 4);

    echo "ok\n";
}
