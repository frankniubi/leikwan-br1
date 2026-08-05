<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<int, array<string, mixed>> */
        public array $leaseRows = [];

        public function fetchAll(string $sql, array $params = []): array
        {
            if (str_contains($sql, 'leigod_execution_leases')) {
                return $this->leaseRows;
            }
            return [];
        }

        public function fetchOne(string $sql, array $params = []): ?array
        {
            return null;
        }

        public function execute(string $sql, array $params = []): int
        {
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
    use Leikwan\Master\Support\ProvisioningService;

    function failClientLeaseProjection(string $message): never
    {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }

    function assertClientLeaseSame(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            failClientLeaseProjection($message . ': expected ' . var_export($expected, true)
                . ', got ' . var_export($actual, true));
        }
    }

    $toml = "line = 451\n";
    $session = '{"token":"root-only"}';
    $leaseId = '00000000-0000-4000-8000-000000000020';
    $envPath = tempnam(sys_get_temp_dir(), 'lw-client-line-lease-');
    $offEnvPath = tempnam(sys_get_temp_dir(), 'lw-client-line-lease-off-');
    if ($envPath === false || $offEnvPath === false) {
        failClientLeaseProjection('unable to create temp env');
    }
    file_put_contents($envPath, implode("\n", [
        'LEIGOD_EXECUTION_LEASES_ENABLED=1',
        'LEIGOD_LINE_TOML_451=' . base64_encode($toml),
        'LEIGOD_SESSION_JSON_B64=' . base64_encode($session),
        '',
    ]));
    file_put_contents($offEnvPath, 'LEIGOD_EXECUTION_LEASES_ENABLED=0' . PHP_EOL);

    $db = new Database();
    $db->leaseRows = [
        [
            'catalog_id' => 11,
            'line_id' => 451,
            'label' => 'must-not-leak',
            'config_sha256' => hash('sha256', $toml),
            'is_active' => 1,
            'allow_client_execution' => 1,
            'lease_id' => $leaseId,
            'lease_generation' => 7,
            'lease_state' => 'pending',
            'lease_owner_type' => 'client_device',
            'lease_owner_id' => 20,
            'lease_owner_device_id' => 'linux-device-a',
            'max_parallel_lanes' => 2,
            'lease_ready_deadline_at' => gmdate('Y-m-d H:i:s', time() + 45),
            'lease_expires_at' => gmdate('Y-m-d H:i:s', time() + 45),
        ],
        [
            'catalog_id' => 12,
            'line_id' => 453,
            'config_sha256' => str_repeat('a', 64),
            'is_active' => 1,
            'allow_client_execution' => 1,
            'lease_id' => '00000000-0000-4000-8000-000000000021',
            'lease_generation' => 8,
            'lease_state' => 'active',
            'lease_owner_type' => 'client_device',
            'lease_owner_id' => 20,
            'lease_owner_device_id' => 'other-device',
            'max_parallel_lanes' => 2,
            'lease_ready_deadline_at' => gmdate('Y-m-d H:i:s', time() + 45),
            'lease_expires_at' => gmdate('Y-m-d H:i:s', time() + 90),
        ],
    ];

    $projection = (new ProvisioningService(new Config($envPath), $db))
        ->desiredLeigodLineManagementForClientDevice(20, 'linux-device-a');
    assertClientLeaseSame([451], array_column($projection['lines'] ?? [], 'line_id'), 'device lease projection');
    $line = $projection['lines'][0] ?? [];
    assertClientLeaseSame(11, $line['catalog_id'] ?? null, 'catalog id for fenced health');
    assertClientLeaseSame($leaseId, $line['lease_id'] ?? null, 'lease id');
    assertClientLeaseSame(7, $line['lease_generation'] ?? null, 'lease generation');
    assertClientLeaseSame('client_device', $line['lease_owner_type'] ?? null, 'lease owner type');
    assertClientLeaseSame(20, $line['lease_owner_id'] ?? null, 'lease owner id');
    assertClientLeaseSame('linux-device-a', $line['lease_owner_device_id'] ?? null, 'lease owner device id');
    assertClientLeaseSame(hash('sha256', $toml), $line['config_sha256'] ?? null, 'config hash');
    assertClientLeaseSame($toml, $line['toml'] ?? null, 'root-only TOML');
    assertClientLeaseSame(false, isset($line['label']), 'provider label is not projected');
    assertClientLeaseSame($session, $projection['session_json'] ?? null, 'root-only session payload');

    $offProjection = (new ProvisioningService(new Config($offEnvPath), $db))
        ->desiredLeigodLineManagementForClientDevice(20, 'linux-device-a');
    assertClientLeaseSame(['lines' => []], $offProjection, 'gate-off client projection');

    $clientController = file_get_contents(__DIR__ . '/../src/Controllers/ClientAgentController.php') ?: '';
    foreach (["'linux_path_leases'", 'desiredLeigodLineManagementForClientDevice'] as $needle) {
        if (!str_contains($clientController, $needle)) {
            failClientLeaseProjection('authenticated pull missing ' . $needle);
        }
    }
    foreach (glob(__DIR__ . '/../src/Controllers/*.php') ?: [] as $controllerPath) {
        if (basename($controllerPath) === 'ClientAgentController.php') {
            continue;
        }
        $source = file_get_contents($controllerPath) ?: '';
        if (str_contains($source, 'linux_path_leases')) {
            failClientLeaseProjection('linux_path_leases leaked into ' . basename($controllerPath));
        }
    }

    @unlink($envPath);
    @unlink($offEnvPath);
    echo "ok\n";
}
