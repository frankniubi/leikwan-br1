<?php
declare(strict_types=1);

// Phase 2 regression coverage:
//   * leigod_mode means true MPTCP client->slave ingress unless the operator explicitly chooses bond.
//   * combo-backed forwards read forwards.client_mode, so saveForwardCombination must keep the
//     denormalized forwards row aligned with the normalized combination.

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
        public function fetchOne(string $sql, array $params = []): ?array { return null; }

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

    function assertForwardsClientModeSync(string $expectedClientMode): void
    {
        $execute = capturedForwardsUpdate();
        if (!str_contains($execute['sql'], 'client_mode = :client_mode')) {
            fail('UPDATE forwards must set client_mode = :client_mode; SQL was: ' . $execute['sql']);
        }
        assertSame(
            $expectedClientMode,
            $execute['params']['client_mode'] ?? null,
            'UPDATE forwards must carry normalized client_mode'
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
        'name' => 'leigod-mode-true-mptcp',
        'tenant_label' => 'Leigod',
        'node_group_key' => 'sha-tencent',
        'backend_mode' => 'leigod_mode',
        'sidecar_point_id' => 4,
        'leigod_line_id' => 453,
        'runtime_preference' => 'single',
        'is_active' => 1,
    ];

    // leigod_mode + legacy/single client mode is normalized to true MPTCP and receives the
    // explicit default cap of 4 legs, not unlimited/zero.
    $row = saveCombo($service, $base + [
        'client_mode' => 'single',
        'helper_group_cap' => 0,
    ]);
    assertSame('mptcp', $row['client_mode'] ?? null, 'leigod_mode/single must persist client_mode=mptcp');
    assertSame('single', $row['runtime_preference'] ?? null, 'leigod_mode/single must persist runtime_preference=single');
    assertSame(4, $row['helper_group_cap'] ?? null, 'leigod_mode/mptcp must default helper_group_cap to 4');
    assertForwardsClientModeSync('mptcp');

    // Explicit MPTCP must not publish the hybrid bond fallback runtime under leigod_mode.
    $row = saveCombo($service, $base + [
        'client_mode' => 'mptcp',
        'runtime_preference' => 'mptcp_preferred_bond_fallback',
    ]);
    assertSame('mptcp', $row['client_mode'] ?? null, 'explicit leigod_mode/mptcp must stay mptcp');
    assertSame('single', $row['runtime_preference'] ?? null, 'explicit leigod_mode/mptcp must coerce runtime_preference=single');
    assertForwardsClientModeSync('mptcp');

    // Explicit bond is the opt-in escape hatch and must stay bond all the way into dependent forwards.
    $row = saveCombo($service, $base + [
        'client_mode' => 'bond',
    ]);
    assertSame('bond', $row['client_mode'] ?? null, 'explicit leigod_mode/bond must persist client_mode=bond');
    assertForwardsClientModeSync('bond');

    echo "ok\n";
}
