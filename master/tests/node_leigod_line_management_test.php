<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<int, array<string, mixed>> */
        public array $desiredRows = [];
        /** @var array<int, array<string, mixed>> */
        public array $candidateRows = [];

        public function fetchAll(string $sql, array $params = []): array
        {
            if (str_contains($sql, 'node_leigod_line_health')) {
                return $this->candidateRows;
            }
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
    use Leikwan\Master\Support\ProvisioningService;

    function failManagement(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    function assertManagementSame(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            failManagement($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    $toml451 = "line = 451\n";
    $toml453 = "line = 453\n";
    $envPath = tempnam(sys_get_temp_dir(), 'lw-line-management-');
    if ($envPath === false) {
        failManagement('unable to create temp env');
    }
    file_put_contents($envPath, implode("\n", [
        'LEIGOD_LINE_TOML_451=' . base64_encode($toml451),
        'LEIGOD_LINE_TOML_453=' . base64_encode($toml453),
        'LEIGOD_SESSION_JSON_B64=' . base64_encode('{"secret":"must-not-be-in-line"}'),
        '',
    ]));

    $db = new Database();
    $db->desiredRows = [
        [
            'id' => 10,
            'line_id' => 455,
            'label' => 'Missing Config',
            'config_sha256' => str_repeat('0', 64),
            'is_active' => 1,
            'sort_order' => 5,
            'membership_sort_order' => 5,
            'tcp_probe_host' => '162.245.221.78',
            'tcp_probe_port' => 80,
        ],
        [
            'id' => 11,
            'line_id' => 451,
            'label' => 'Korea',
            'config_sha256' => hash('sha256', $toml451),
            'is_active' => 1,
            'sort_order' => 10,
            'membership_sort_order' => 10,
            'tcp_probe_host' => '162.245.221.78',
            'tcp_probe_port' => 80,
            'udp_probe_host' => null,
            'udp_probe_port' => null,
            'udp_probe_method' => null,
        ],
        [
            'id' => 12,
            'line_id' => 453,
            'label' => 'Japan',
            'config_sha256' => hash('sha256', $toml453),
            'is_active' => 1,
            'sort_order' => 20,
            'membership_sort_order' => 20,
            'tcp_probe_host' => '162.245.221.78',
            'tcp_probe_port' => 80,
            'udp_probe_host' => '223.5.5.5',
            'udp_probe_port' => 53,
            'udp_probe_method' => 'dns',
        ],
    ];

    $config = new Config($envPath);
    $catalog = new LeigodLineCatalogService($config, $db);
    $desired = $catalog->desiredLinesForNode(12);
    assertManagementSame([451, 453], array_column($desired, 'line_id'), 'desired line order');
    assertManagementSame($toml451, $desired[0]['toml'] ?? null, 'decoded line TOML');
    assertManagementSame(hash('sha256', $toml451), $desired[0]['config_sha256'] ?? null, 'expected hash');
    if (array_key_exists('session_json', $desired[0]) || array_key_exists('leigod_session_json', $desired[0])) {
        failManagement('line management exposes account session');
    }
    assertManagementSame([], $catalog->desiredLinesForNode(99), 'unrelated node desired lines');
    foreach ($desired as $line) {
        if (array_key_exists('lease_id', $line) || array_key_exists('lease_generation', $line)) {
            failManagement('gate-off node payload changed');
        }
    }

    $provisioning = new ProvisioningService($config, $db);
    $management = $provisioning->desiredLeigodLineManagementForNode(12);
    assertManagementSame([451, 453], array_column($management['lines'] ?? [], 'line_id'), 'provisioning management wrapper');

    $leaseEnvPath = tempnam(sys_get_temp_dir(), 'lw-line-management-lease-');
    if ($leaseEnvPath === false) {
        failManagement('unable to create lease env');
    }
    file_put_contents($leaseEnvPath, implode("\n", [
        'LEIGOD_EXECUTION_LEASES_ENABLED=1',
        'LEIGOD_LINE_TOML_451=' . base64_encode($toml451),
        'LEIGOD_LINE_TOML_453=' . base64_encode($toml453),
        '',
    ]));
    $lease451 = '00000000-0000-4000-8000-000000000012';
    $lease453 = '00000000-0000-4000-8000-000000000013';
    $leaseDb = new Database();
    $leaseDb->desiredRows = [
        $db->desiredRows[1] + [
            'lease_id' => $lease451,
            'lease_generation' => 3,
            'lease_state' => 'active',
            'lease_owner_type' => 'node',
            'lease_owner_id' => 12,
            'max_parallel_lanes' => 2,
            'lease_expires_at' => gmdate('Y-m-d H:i:s', time() + 90),
        ],
        $db->desiredRows[2] + [
            'lease_id' => $lease453,
            'lease_generation' => 4,
            'lease_state' => 'active',
            'lease_owner_type' => 'node',
            'lease_owner_id' => 13,
            'max_parallel_lanes' => 2,
            'lease_expires_at' => gmdate('Y-m-d H:i:s', time() + 90),
        ],
    ];
    $baseCandidate = [
        'route_id' => 0,
        'combination_sort_order' => 10,
        'combination_id' => 100,
        'membership_sort_order' => 10,
        'catalog_id' => 11,
        'line_id' => 451,
        'config_sha256' => hash('sha256', $toml451),
        'line_is_active' => 1,
        'node_id' => 12,
        'node_name' => 'node-12',
        'node_public_ip' => '192.0.2.12',
        'node_private_ip' => '10.0.0.12',
        'node_status' => 'active',
        'node_group_key' => 'group-a',
        'health_config_sha256' => hash('sha256', $toml451),
        'service_state' => 'active',
        'tcp_status' => 'healthy',
        'udp_status' => 'unknown',
        'checked_at' => gmdate('Y-m-d H:i:s'),
        'lease_id' => $lease451,
        'lease_generation' => 3,
        'lease_state' => 'active',
        'lease_owner_type' => 'node',
        'lease_owner_id' => 12,
        'lease_expires_at' => gmdate('Y-m-d H:i:s', time() + 90),
        'health_lease_id' => $lease451,
        'health_lease_generation' => 3,
    ];
    $leaseDb->candidateRows = [
        $baseCandidate,
        array_replace($baseCandidate, [
            'catalog_id' => 12,
            'line_id' => 453,
            'config_sha256' => hash('sha256', $toml453),
            'health_config_sha256' => hash('sha256', $toml453),
            'lease_id' => $lease453,
            'lease_generation' => 4,
            'lease_owner_id' => 13,
            'health_lease_id' => $lease453,
            'health_lease_generation' => 4,
        ]),
    ];
    $leaseCatalog = new LeigodLineCatalogService(new Config($leaseEnvPath), $leaseDb);
    $leasedDesired = $leaseCatalog->desiredLinesForNode(12);
    assertManagementSame([451], array_column($leasedDesired, 'line_id'), 'lease gate filters node projection by owner');
    assertManagementSame($lease451, $leasedDesired[0]['lease_id'] ?? null, 'node projection lease id');
    assertManagementSame(3, $leasedDesired[0]['lease_generation'] ?? null, 'node projection generation');
    assertManagementSame('active', $leasedDesired[0]['lease_state'] ?? null, 'node projection state');
    assertManagementSame(2, $leasedDesired[0]['max_parallel_lanes'] ?? null, 'node projection lane cap');
    foreach (['single', 'race', 'bond'] as $mode) {
        $candidates = $leaseCatalog->candidatesForForwardOnNode([
            'mode' => $mode,
            'transport_mode' => 'tcp',
            'forward_combination_id' => 100,
        ], 12);
        assertManagementSame([451], array_column($candidates, 'line_id'), $mode . ' candidates use active fenced leases');
        assertManagementSame($lease451, $candidates[0]['lease_id'] ?? null, $mode . ' candidate lease id');
    }

    $nodeController = file_get_contents(__DIR__ . '/../src/Controllers/NodeController.php') ?: '';
    foreach (['desiredLeigodLineManagementForNode', "'leigod_lines' => \$leigodLines", "\$leigodLines !== []"] as $needle) {
        if (!str_contains($nodeController, $needle)) {
            failManagement('NodeController.php missing ' . $needle);
        }
    }
    $catalogSource = file_get_contents(__DIR__ . '/../src/Support/LeigodLineCatalogService.php') ?: '';
    if (substr_count($catalogSource, 'AND ll.allow_node_execution = 1') < 3) {
        failManagement('node projection and both candidate queries must enforce allow_node_execution');
    }

    @unlink($envPath);
    @unlink($leaseEnvPath);
    echo "ok\n";
}
