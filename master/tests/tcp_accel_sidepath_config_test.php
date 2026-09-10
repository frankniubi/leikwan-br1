<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {

    final class Database
    {
        /** @var array<int, array{forward_id:int,line_id:int}> */
        public array $memberships = [];
        /** @var array<int, array{sql:string,params:array<string,mixed>}> */
        public array $membershipQueries = [];
        /** @var array<int, array<string,mixed>> */
        public array $nodeForwards = [];

        /** @return array<int, array<string, mixed>> */
        public function fetchAll(string $sql, array $params = []): array
        {
            if (str_contains($sql, 'forward_combination_leigod_lines')) {
                $this->membershipQueries[] = ['sql' => $sql, 'params' => $params];
                return $this->memberships;
            }
            if (str_contains($sql, 'SELECT DISTINCT f.id')) {
                return $this->nodeForwards;
            }
            return [];
        }

        /** @return array<string, mixed>|null */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            return null;
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

    function fail_test(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    /** @param mixed $expected @param mixed $actual */
    function assert_same(mixed $expected, mixed $actual, string $message): void
    {
        if ($actual !== $expected) {
            fail_test($message . ': expected ' . json_encode($expected) . ', got ' . json_encode($actual));
        }
    }

    $envPath = tempnam(sys_get_temp_dir(), 'lw-tcp-accel-');
    if ($envPath === false) {
        fail_test('failed to create temp env file');
    }
    file_put_contents($envPath, "AGENT_API_URL=https://panel.example.com\n");

    $db = new Database();
    $service = new ProvisioningService(new Config($envPath), $db);
    $assignedForwards = [
        [
            'forward_id' => 121,
            'mode' => 'bond',
            'transport' => 'tcp+udp',
            'bonding' => ['ingress_mode' => 'plain_tcp_mux'],
            'target_host' => '89.144.30.119',
            'target_port' => 5201,
            'endpoints' => [
                ['host' => '101.133.142.65', 'port' => 8251],
                ['host' => '47.116.134.3', 'port' => 8251],
            ],
        ],
        [
            // tcp-only forward: bulk split does not pair with a UoT sidepath
            'forward_id' => 122,
            'mode' => 'bond',
            'transport' => 'tcp',
            'bonding' => ['ingress_mode' => 'plain_tcp_mux'],
            'target_host' => '89.144.30.119',
            'target_port' => 5201,
            'endpoints' => [['host' => '101.133.142.65', 'port' => 8251]],
        ],
        [
            // non-bond ingress: must not be eligible
            'forward_id' => 123,
            'mode' => 'bond',
            'transport' => 'tcp+udp',
            'bonding' => ['ingress_mode' => 'wss_path_mux'],
            'target_host' => '89.144.30.119',
            'target_port' => 5201,
            'endpoints' => [['host' => '101.133.142.65', 'port' => 8251]],
        ],
    ];

    // Default off: no TCP_ACCEL_SIDECAR_SERVER env -> no block at all.
    putenv('TCP_ACCEL_SIDECAR_SERVER');
    putenv('TCP_ACCEL_TOKEN');
    assert_same(null, $service->tcpAccelSidepathConfigForAssignedClientForwards($assignedForwards), 'client sidepath must stay off without env');
    assert_same(null, $service->tcpAccelSidepathConfigForNode(2), 'node sidepath must stay off without env');

    // Invalid env values are treated as off, never half-configured.
    putenv('TCP_ACCEL_SIDECAR_SERVER=not-a-port');
    assert_same(null, $service->tcpAccelSidepathConfigForAssignedClientForwards($assignedForwards), 'invalid env must disable the sidepath');

    putenv('TCP_ACCEL_SIDECAR_SERVER=89.144.30.80:29030');
    putenv('TCP_ACCEL_TOKEN=sekrit-token');

    $db->memberships = [['forward_id' => 121, 'line_id' => 455], ['forward_id' => 121, 'line_id' => 693]];
    $clientConfig = $service->tcpAccelSidepathConfigForAssignedClientForwards($assignedForwards);
    assert_same([121], $clientConfig['enabled_forward_ids'] ?? null, 'only eligible forwards are listed');
    assert_same('89.144.30.80:29030', $clientConfig['sidecar_server'] ?? null, 'sidecar server comes from env');
    assert_same('sekrit-token', $clientConfig['token'] ?? null, 'token comes from env');
    assert_same(4, $clientConfig['lanes'] ?? null, 'default lanes');
    $forwardEntry = $clientConfig['forwards']['121'] ?? null;
    if (!is_array($forwardEntry)) {
        fail_test('forward 121 must receive a tcp-accel entry');
    }
    assert_same('89.144.30.119:5201', $forwardEntry['target'], 'forward target is preserved');
    // One remote per (endpoint, line): line 455 -> port 7455, line 693 -> port 7693.
    assert_same(
        ['101.133.142.65:7455', '101.133.142.65:7693', '47.116.134.3:7455', '47.116.134.3:7693'],
        $forwardEntry['remotes'],
        'remotes fan out per endpoint x line with deterministic bridge ports'
    );
    foreach ([122, 123] as $ineligible) {
        if (array_key_exists((string) $ineligible, $clientConfig['forwards'])) {
            fail_test("forward {$ineligible} must not receive a tcp-accel entry");
        }
    }

    // Node config: bridge per forward, one listener per line in 7000-9000.
    $db->nodeForwards = [['id' => 121, 'target_host' => '89.144.30.119', 'target_port' => 5201]];
    $nodeConfig = $service->tcpAccelSidepathConfigForNode(2);
    $bridge = $nodeConfig['bridges']['121'] ?? null;
    if (!is_array($bridge)) {
        fail_test('node config must include a bridge for forward 121');
    }
    assert_same('89.144.30.80:29030', $bridge['remote'], 'bridge remote is the sidecar server');
    assert_same(
        [['line_id' => 455, 'listen_port' => 7455], ['line_id' => 693, 'listen_port' => 7693]],
        $bridge['listeners'],
        'one deterministic listener per leigod line'
    );

    // No lines -> no bridge, and no node config at all without eligible forwards.
    $db->memberships = [];
    assert_same(null, $service->tcpAccelSidepathConfigForNode(2), 'node without line membership emits nothing');
    $db->nodeForwards = [];
    $db->memberships = [['forward_id' => 121, 'line_id' => 455]];
    assert_same(null, $service->tcpAccelSidepathConfigForNode(2), 'node without eligible forwards emits nothing');

    // Controllers must treat tcp_accel_sidepath as a managed agent-config key.
    foreach ([
        __DIR__ . '/../src/Controllers/ClientAgentController.php',
        __DIR__ . '/../src/Controllers/NodeController.php',
    ] as $controllerPath) {
        $source = (string) file_get_contents($controllerPath);
        if (!str_contains($source, 'tcp_accel_sidepath')) {
            fail_test(basename($controllerPath) . ' must wire tcp_accel_sidepath');
        }
    }

    echo "ok\n";
}
