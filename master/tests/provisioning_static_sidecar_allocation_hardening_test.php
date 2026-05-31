<?php
declare(strict_types=1);

// Behavioral regression (Appendix C.5a / C.5c): static sidecar uplink port and /30
// subnet allocation must be uniqueness-checked per sidecar point, not naive % hashing.
//
// The old formulas (stableStaticSidecarUplinkPort / stableSidecarLegPort / staticSidecarUplinkAddressing)
// computed pure (hash % rangeSize) buckets with NO collision check, so distinct
// (point, combination, node, lane) tuples that happen to alias the same bucket received
// the SAME WG listen port or the SAME /30 transit subnet. Two sidecar interfaces sharing a
// port crash-loop with EADDRINUSE; two sharing a /30 silently cross-route. This test drives
// the REAL ProvisioningService allocation helpers across many distinct tuples and asserts:
//   (a) every assigned uplink/leg port is DISTINCT and inside the correct disjoint subrange,
//   (b) every assigned /30 subnet is DISTINCT,
//   (c) repeat allocation of an already-assigned tuple is idempotent (republish stability),
//   (d) an undersize subrange (more tuples than the subrange can hold) is detected + logged.

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<int, array{table:string,data:array<string,mixed>}> */
        public array $inserts = [];

        /** @var array<string, array<int, array<string, mixed>>> */
        private array $tables = [];

        public function seed(string $table, array $rows): void
        {
            $this->tables[$table] = array_values($rows);
        }

        public function fetchOne(string $sql, array $params = []): ?array
        {
            $normalized = preg_replace('/\s+/', ' ', trim($sql)) ?? $sql;
            if (str_contains($normalized, 'FROM app_settings WHERE setting_key = :setting_key')) {
                foreach ($this->tables['app_settings'] ?? [] as $row) {
                    if ((string) ($row['setting_key'] ?? '') === (string) ($params['setting_key'] ?? '')) {
                        return $row;
                    }
                }
                return null;
            }
            return null;
        }

        public function fetchAll(string $sql, array $params = []): array
        {
            return [];
        }

        public function insert(string $table, array $data): int
        {
            $this->inserts[] = ['table' => $table, 'data' => $data];
            $data['id'] = count($this->tables[$table] ?? []) + 1;
            $this->tables[$table][] = $data;
            return (int) $data['id'];
        }

        public function execute(string $sql, array $params = []): int
        {
            $normalized = preg_replace('/\s+/', ' ', trim($sql)) ?? $sql;
            if (str_contains($normalized, 'UPDATE app_settings SET setting_value')) {
                foreach ($this->tables['app_settings'] ?? [] as &$row) {
                    if ((string) ($row['setting_key'] ?? '') === (string) ($params['setting_key'] ?? '')) {
                        $row['setting_value'] = $params['setting_value'] ?? null;
                        return 1;
                    }
                }
            }
            return 0;
        }

        public function transaction(callable $callback)
        {
            return $callback($this);
        }

        /** @return array<int, array<string, mixed>> */
        public function rows(string $table): array
        {
            return $this->tables[$table] ?? [];
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

    function call_private(object $instance, string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod($instance, $method);
        $ref->setAccessible(true);
        return $ref->invoke($instance, ...$args);
    }

    $envPath = tempnam(sys_get_temp_dir(), 'lw-alloc-env-');
    file_put_contents($envPath, "DEFAULT_TLS_CERT_REF=default-edge-cert\n");

    $db = new Database();
    $db->seed('app_settings', []);
    $service = new ProvisioningService(new Config($envPath), $db);

    // A sidecar WG range of 200 slots: listen 14969 .. range_end 15169.
    // uplink subrange = floor(199/2) = 99 slots starting at 14970.
    // leg subrange starts after the uplink subrange.
    $listenPort = 14969;
    $rangeEnd = 15169;

    // Build many distinct tuples on the SAME sidecar point (id=4). Naive % hashing across
    // these will alias multiple tuples to the same bucket; uniqueness-checked allocation
    // must hand out distinct ports/subnets.
    $tuples = [];
    for ($combinationId = 1; $combinationId <= 8; $combinationId++) {
        for ($nodeId = 1; $nodeId <= 6; $nodeId++) {
            foreach (['primary', 'backup'] as $lane) {
                $tuples[] = ['combination_id' => $combinationId, 'node_id' => $nodeId, 'lane' => $lane];
            }
        }
    }

    $pointId = 4;
    $uplinkPorts = [];
    $legPorts = [];
    $subnets = [];
    $uplinkPortsByTuple = [];

    // Disjoint subrange boundaries (mirrors the implementation's halving).
    $rangeSize = $rangeEnd - $listenPort;          // 200
    $uplinkRangeSize = max(1, intdiv($rangeSize, 2)); // 100
    $uplinkLow = $listenPort + 1;                   // 14970
    $uplinkHigh = $listenPort + $uplinkRangeSize;   // 15069 inclusive-ish
    $legLow = $listenPort + 1 + $uplinkRangeSize;   // 15070
    $legHigh = $rangeEnd;                           // 15169

    foreach ($tuples as $i => $t) {
        $up = (int) call_private(
            $service,
            'allocateStaticSidecarUplinkPort',
            $listenPort,
            $rangeEnd,
            $pointId,
            $t['combination_id'],
            $t['node_id'],
            $t['lane']
        );
        $leg = (int) call_private(
            $service,
            'allocateStaticSidecarLegPort',
            $listenPort,
            $rangeEnd,
            $pointId,
            // leg ports are keyed on (forward, node) in production; here use a synthetic
            // forward id derived from the tuple index so every tuple is distinct.
            1000 + $i,
            $t['node_id']
        );
        $addr = call_private(
            $service,
            'allocateStaticSidecarUplinkSubnet',
            $pointId,
            $t['combination_id'],
            $t['node_id'],
            $t['lane']
        );

        if ($up <= 0) {
            fail("uplink port allocation returned $up for tuple #$i");
        }
        if ($up < $uplinkLow || $up > $uplinkHigh) {
            fail("uplink port $up for tuple #$i outside disjoint uplink subrange [$uplinkLow,$uplinkHigh]");
        }
        if ($leg < $legLow || $leg > $legHigh) {
            fail("leg port $leg for tuple #$i outside disjoint leg subrange [$legLow,$legHigh]");
        }
        $subnet = (string) ($addr['subnet'] ?? '');
        if ($subnet === '' || !str_ends_with($subnet, '/30')) {
            fail("subnet for tuple #$i not a /30: '$subnet'");
        }

        $uplinkPorts[] = $up;
        $legPorts[] = $leg;
        $subnets[] = $subnet;
        $uplinkPortsByTuple[$t['combination_id'] . ':' . $t['node_id'] . ':' . $t['lane']] = $up;
    }

    if (count(array_unique($uplinkPorts)) !== count($uplinkPorts)) {
        fail('REGRESSION: uplink ports collided across distinct tuples (naive %rangeSize hashing not de-collided)');
    }
    if (count(array_unique($legPorts)) !== count($legPorts)) {
        fail('REGRESSION: leg ports collided across distinct tuples');
    }
    if (count(array_unique($subnets)) !== count($subnets)) {
        fail('REGRESSION: /30 subnets collided across distinct tuples');
    }

    // Idempotency: re-allocating an already-assigned tuple reproduces its port.
    $repeat = (int) call_private(
        $service,
        'allocateStaticSidecarUplinkPort',
        $listenPort,
        $rangeEnd,
        $pointId,
        3,
        2,
        'primary'
    );
    if ($repeat !== $uplinkPortsByTuple['3:2:primary']) {
        fail("uplink port allocation not idempotent: expected {$uplinkPortsByTuple['3:2:primary']} got $repeat");
    }

    // Undersize detection: tiny range that cannot hold all tuples must log a persisted marker.
    $tinyListen = 20000;
    $tinyEnd = 20005; // rangeSize=5, uplink subrange = floor(4/2)=2 slots only
    $tinyDb = new Database();
    $tinyDb->seed('app_settings', []);
    $tinyService = new ProvisioningService(new Config($envPath), $tinyDb);
    for ($c = 1; $c <= 10; $c++) {
        call_private(
            $tinyService,
            'allocateStaticSidecarUplinkPort',
            $tinyListen,
            $tinyEnd,
            9,
            $c,
            1,
            'primary'
        );
    }
    $marker = $tinyDb->fetchOne('SELECT setting_value FROM app_settings WHERE setting_key = :setting_key LIMIT 1', [
        'setting_key' => 'static_sidecar_alloc_undersize:9',
    ]);
    if (!is_array($marker)) {
        fail('undersize uplink subrange was not detected/logged (no persisted marker for sidecar point 9)');
    }

    echo "provisioning_static_sidecar_allocation_hardening_test passed\n";
}
