<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<int, array<string, mixed>> */
        public array $desiredRows = [];

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

    $provisioning = new ProvisioningService($config, $db);
    $management = $provisioning->desiredLeigodLineManagementForNode(12);
    assertManagementSame([451, 453], array_column($management['lines'] ?? [], 'line_id'), 'provisioning management wrapper');

    $nodeController = file_get_contents(__DIR__ . '/../src/Controllers/NodeController.php') ?: '';
    foreach (['desiredLeigodLineManagementForNode', "'leigod_lines' => \$leigodLines", "\$leigodLines !== []"] as $needle) {
        if (!str_contains($nodeController, $needle)) {
            failManagement('NodeController.php missing ' . $needle);
        }
    }

    @unlink($envPath);
    echo "ok\n";
}
