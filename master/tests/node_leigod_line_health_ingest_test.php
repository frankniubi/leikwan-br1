<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<int, array<string, mixed>> */
        public array $desiredRows = [];
        /** @var array<string, array<string, mixed>> */
        public array $health = [];

        public function fetchAll(string $sql, array $params = []): array
        {
            if (str_contains($sql, 'forward_combination_leigod_lines')) {
                return (int) ($params['node_id'] ?? 0) === 12 ? $this->desiredRows : [];
            }
            return [];
        }

        public function fetchOne(string $sql, array $params = []): ?array
        {
            return null;
        }

        public function execute(string $sql, array $params = []): int
        {
            if (str_contains($sql, 'INSERT INTO node_leigod_line_health')) {
                $this->health[(int) $params['node_id'] . ':' . (int) $params['leigod_line_id']] = $params;
                return 1;
            }
            return 0;
        }

        public function insert(string $table, array $row): int
        {
            return 1;
        }
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

    function failHealth(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    function assertHealthSame(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            failHealth($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    $toml451 = "line = 451\n";
    $toml453 = "line = 453\n";
    $hash451 = hash('sha256', $toml451);
    $hash453 = hash('sha256', $toml453);
    $envPath = tempnam(sys_get_temp_dir(), 'lw-line-health-');
    if ($envPath === false) {
        failHealth('unable to create temp env');
    }
    file_put_contents($envPath, implode("\n", [
        'LEIGOD_LINE_TOML_451=' . base64_encode($toml451),
        'LEIGOD_LINE_TOML_453=' . base64_encode($toml453),
        'LEIGOD_LINE_HEARTBEAT_CAP=5',
        '',
    ]));

    $db = new Database();
    $db->desiredRows = [
        ['id' => 11, 'line_id' => 451, 'config_sha256' => $hash451, 'is_active' => 1, 'sort_order' => 10, 'membership_sort_order' => 10, 'tcp_probe_host' => '162.245.221.78', 'tcp_probe_port' => 80],
        ['id' => 12, 'line_id' => 453, 'config_sha256' => $hash453, 'is_active' => 1, 'sort_order' => 20, 'membership_sort_order' => 20, 'tcp_probe_host' => '162.245.221.78', 'tcp_probe_port' => 80],
    ];
    $catalog = new LeigodLineCatalogService(new Config($envPath), $db);
    $healthy = static fn(int $lineId, string $hash, string $error = ''): array => [
        'line_id' => $lineId,
        'config_sha256' => $hash,
        'service_state' => 'active',
        'tcp_status' => 'healthy',
        'tcp_connect_ms' => 18,
        'udp_status' => 'unknown',
        'consecutive_successes' => 3,
        'consecutive_failures' => 0,
        'last_error' => $error,
        'checked_at' => gmdate('c'),
    ];
    $payload = [
        array_replace($healthy(451, $hash451), ['checked_at' => gmdate('c', time() + 3600)]),
        $healthy(451, $hash451, str_repeat('x', 900)),
        $healthy(451, $hash451),
        $healthy(453, $hash453),
        ['line_id' => 'not-an-id'],
        $healthy(999, str_repeat('0', 64)),
    ];

    $result = $catalog->ingestNodeHealth(12, $payload);
    assertHealthSame(2, $result['accepted'] ?? null, 'accepted health rows');
    assertHealthSame(4, $result['rejected'] ?? null, 'rejected health rows');
    $health451 = $db->health['12:11'] ?? [];
    assertHealthSame('healthy', $health451['tcp_status'] ?? null, 'stored TCP status');
    if (strlen((string) ($health451['last_error'] ?? '')) > 500) {
        failHealth('stored error exceeds 500 bytes');
    }
    assertHealthSame($hash451, $health451['config_sha256'] ?? null, 'stored expected hash');
    assertHealthSame(false, isset($db->health['12:999']), 'unrelated line was not stored');

    $nodeController = file_get_contents(__DIR__ . '/../src/Controllers/NodeController.php') ?: '';
    $adminController = file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php') ?: '';
    foreach (['leigod_line_health', 'leigod_forward_selections'] as $dynamicKey) {
        if (!str_contains($nodeController, "\$republishCapabilityState['" . $dynamicKey . "']")) {
            failHealth('republish fingerprint does not exclude ' . $dynamicKey);
        }
        if (!str_contains($adminController, "'" . $dynamicKey . "'")) {
            failHealth('node management does not preserve ' . $dynamicKey);
        }
    }

    @unlink($envPath);
    echo "ok\n";
}
