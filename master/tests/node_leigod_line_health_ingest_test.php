<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<int, array<string, mixed>> */
        public array $desiredRows = [];
        /** @var array<string, array<string, mixed>> */
        public array $health = [];
        /** @var array<int, array<string, mixed>> */
        public array $lines = [];
        /** @var array<int, array<string, mixed>> */
        public array $leases = [];
        public bool $inTransaction = false;

        public function transaction(callable $callback): mixed
        {
            if ($this->inTransaction) {
                throw new \RuntimeException('nested health transaction');
            }
            $this->inTransaction = true;
            try {
                return $callback($this);
            } finally {
                $this->inTransaction = false;
            }
        }

        public function fetchAll(string $sql, array $params = []): array
        {
            if (str_contains($sql, 'forward_combination_leigod_lines')) {
                return (int) ($params['node_id'] ?? 0) === 12 ? $this->desiredRows : [];
            }
            return [];
        }

        public function fetchOne(string $sql, array $params = []): ?array
        {
            if (str_contains($sql, 'FROM leigod_lines')) {
                return $this->lines[(int) ($params['line_id'] ?? $params['id'] ?? 0)] ?? null;
            }
            if (str_contains($sql, 'FROM leigod_execution_leases')) {
                if (isset($params['line_id'])) {
                    return $this->leases[(int) $params['line_id']] ?? null;
                }
                foreach ($this->leases as $lease) {
                    if ((string) ($lease['lease_id'] ?? '') === (string) ($params['lease_id'] ?? '')) {
                        return $lease;
                    }
                }
            }
            return null;
        }

        public function execute(string $sql, array $params = []): int
        {
            if (str_contains($sql, 'INSERT INTO node_leigod_line_health')) {
                $this->health[(int) $params['node_id'] . ':' . (int) $params['leigod_line_id']] = $params;
                return 1;
            }
            if (str_contains($sql, 'UPDATE leigod_execution_leases')) {
                $lineId = (int) ($params['line_id'] ?? 0);
                if (isset($this->leases[$lineId])) {
                    $this->leases[$lineId] = array_replace($this->leases[$lineId], $params);
                    return 1;
                }
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
    assertHealthSame(false, array_key_exists('lease_id', $health451), 'gate-off health params unchanged');
    assertHealthSame(false, array_key_exists('lease_generation', $health451), 'gate-off generation param unchanged');
    assertHealthSame(false, isset($db->health['12:999']), 'unrelated line was not stored');

    $leaseEnvPath = tempnam(sys_get_temp_dir(), 'lw-line-health-lease-');
    if ($leaseEnvPath === false) {
        failHealth('unable to create lease health env');
    }
    file_put_contents($leaseEnvPath, implode("\n", [
        'LEIGOD_EXECUTION_LEASES_ENABLED=1',
        'LEIGOD_LINE_TOML_451=' . base64_encode($toml451),
        '',
    ]));
    $leaseId = '00000000-0000-4000-8000-000000000012';
    $leaseDb = new Database();
    $leaseDb->lines = [11 => [
        'id' => 11,
        'line_id' => 451,
        'config_sha256' => $hash451,
        'is_active' => 1,
        'allow_node_execution' => 1,
        'allow_client_execution' => 1,
        'max_parallel_lanes' => 2,
    ]];
    $leaseDb->leases = [11 => [
        'leigod_line_id' => 11,
        'lease_id' => $leaseId,
        'owner_type' => 'node',
        'owner_id' => 12,
        'owner_device_id' => null,
        'generation' => 3,
        'max_parallel_lanes' => 2,
        'state' => 'pending',
        'issued_at' => gmdate('Y-m-d H:i:s'),
        'ready_deadline_at' => gmdate('Y-m-d H:i:s', time() + 45),
        'expires_at' => gmdate('Y-m-d H:i:s', time() + 45),
        'last_renewed_at' => gmdate('Y-m-d H:i:s'),
        'updated_at' => gmdate('Y-m-d H:i:s'),
    ]];
    $leaseDb->desiredRows = [[
        'id' => 11,
        'catalog_id' => 11,
        'line_id' => 451,
        'config_sha256' => $hash451,
        'is_active' => 1,
        'sort_order' => 10,
        'membership_sort_order' => 10,
        'tcp_probe_host' => '162.245.221.78',
        'tcp_probe_port' => 80,
        'lease_id' => $leaseId,
        'lease_generation' => 3,
        'lease_state' => 'pending',
        'lease_owner_type' => 'node',
        'lease_owner_id' => 12,
        'max_parallel_lanes' => 2,
        'lease_expires_at' => gmdate('Y-m-d H:i:s', time() + 45),
    ]];
    $leaseCatalog = new LeigodLineCatalogService(new Config($leaseEnvPath), $leaseDb);
    $fencedHealthy = $healthy(451, $hash451) + [
        'lease_id' => $leaseId,
        'lease_generation' => 3,
        'lease_state' => 'pending',
    ];
    $fencedResult = $leaseCatalog->ingestNodeHealth(12, [$fencedHealthy]);
    assertHealthSame(['accepted' => 1, 'rejected' => 0], $fencedResult, 'matching fenced health accepted');
    assertHealthSame($leaseId, $leaseDb->health['12:11']['lease_id'] ?? null, 'stored health lease id');
    assertHealthSame(3, $leaseDb->health['12:11']['lease_generation'] ?? null, 'stored health generation');
    assertHealthSame('active', $leaseDb->leases[11]['state'] ?? null, 'matching health activates pending lease');
    $storedFencedHealth = $leaseDb->health['12:11'];

    $missingFence = $fencedHealthy;
    unset($missingFence['lease_id'], $missingFence['lease_generation']);
    assertHealthSame(
        ['accepted' => 0, 'rejected' => 1],
        $leaseCatalog->ingestNodeHealth(12, [$missingFence]),
        'missing health fence rejected'
    );
    $staleFence = array_replace($fencedHealthy, ['lease_generation' => 2]);
    assertHealthSame(
        ['accepted' => 0, 'rejected' => 1],
        $leaseCatalog->ingestNodeHealth(12, [$staleFence]),
        'stale health fence rejected'
    );
    assertHealthSame($storedFencedHealth, $leaseDb->health['12:11'], 'rejected fence cannot update stored health');

    $clientLeaseId = '00000000-0000-4000-8000-000000000020';
    $leaseDb->leases[11] = array_replace($leaseDb->leases[11], [
        'lease_id' => $clientLeaseId,
        'owner_type' => 'client_device',
        'owner_id' => 20,
        'owner_device_id' => 'linux-device-a',
        'generation' => 4,
        'state' => 'pending',
    ]);
    $clientHealth = [[
        'catalog_id' => 11,
        'line_id' => 451,
        'lease_id' => $clientLeaseId,
        'lease_generation' => 4,
        'config_sha256' => $hash451,
        'service_state' => 'active',
        'tcp_status' => 'ok',
    ]];
    assertHealthSame(
        ['accepted' => 1, 'rejected' => 0],
        $leaseCatalog->ingestClientHealth(20, 'linux-device-a', $clientHealth),
        'matching client pathd health accepted'
    );
    assertHealthSame('active', $leaseDb->leases[11]['state'] ?? null, 'client pathd health activates pending lease');
    $clientHealth[0]['lease_generation'] = 3;
    assertHealthSame(
        ['accepted' => 0, 'rejected' => 1],
        $leaseCatalog->ingestClientHealth(20, 'linux-device-a', $clientHealth),
        'stale client pathd health rejected'
    );

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
    $clientController = file_get_contents(__DIR__ . '/../src/Controllers/ClientAgentController.php') ?: '';
    foreach (['linux_path_line_health', 'ingestClientHealth', 'leikwan_pathd'] as $needle) {
        if (!str_contains($clientController, $needle)) {
            failHealth('client heartbeat missing ' . $needle);
        }
    }

    @unlink($envPath);
    @unlink($leaseEnvPath);
    echo "ok\n";
}
