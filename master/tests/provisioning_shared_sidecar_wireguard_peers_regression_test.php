<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<string, array<int, array<string, mixed>>> */
        private array $tables = [];

        public function seed(string $table, array $rows): void
        {
            $this->tables[$table] = array_values($rows);
        }

        public function fetchOne(string $sql, array $params = []): ?array
        {
            if (str_contains($sql, 'FROM app_settings WHERE setting_key = :setting_key')) {
                foreach ($this->tables['app_settings'] ?? [] as $row) {
                    if ((string) ($row['setting_key'] ?? '') === (string) ($params['setting_key'] ?? '')) {
                        return $row;
                    }
                }
            }
            return null;
        }

        public function fetchAll(string $sql, array $params = []): array
        {
            return [];
        }

        public function insert(string $table, array $data): int
        {
            $data['id'] = count($this->tables[$table] ?? []) + 1;
            $this->tables[$table][] = $data;
            return (int) $data['id'];
        }

        public function execute(string $sql, array $params = []): int
        {
            if (str_contains($sql, 'UPDATE app_settings SET setting_value')) {
                foreach ($this->tables['app_settings'] ?? [] as &$row) {
                    if ((string) ($row['setting_key'] ?? '') === (string) ($params['setting_key'] ?? '')) {
                        $row['setting_value'] = $params['setting_value'] ?? null;
                        return 1;
                    }
                }
            }
            return 0;
        }

        public function transaction(callable $callback): mixed
        {
            return $callback($this);
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

    function assert_same(mixed $expected, mixed $actual, string $message): void
    {
        if ($actual !== $expected) {
            fail($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    function assert_true(bool $condition, string $message): void
    {
        if (!$condition) {
            fail($message);
        }
    }

    function call_private(object $instance, string $method, mixed ...$args): mixed
    {
        $reflection = new ReflectionMethod($instance, $method);
        $reflection->setAccessible(true);
        return $reflection->invoke($instance, ...$args);
    }

    $envPath = tempnam(sys_get_temp_dir(), 'lw-shared-wg-env-');
    if (!is_string($envPath)) {
        fail('failed to allocate env path');
    }
    file_put_contents($envPath, implode("\n", [
        'DEFAULT_TLS_CERT_REF=default-edge-cert',
        'STATIC_SIDECAR_SHARED_WG_POINT_IDS=10',
        '',
    ]));

    $db = new Database();
    $db->seed('app_settings', []);
    $service = new ProvisioningService(new Config($envPath), $db);

    $combination = [
        'id' => 13,
        'sidecar_point_id' => 10,
        'backend_mode' => 'current_mode',
        'client_mode' => 'bond',
        'sidecar_host' => 'hk-sidecar.example',
        'sidecar_listen_port' => 18501,
        'sidecar_bond_listen_port' => 18501,
        'sidecar_mptcp_listen_port' => 18502,
        'sidecar_cf_listen_port' => 18510,
        'sidecar_agent_config_json' => json_encode([
            'wireguard_public_key' => 'sidecar-public-key',
            'wireguard_listen_port' => 18502,
            'wireguard_port_range_end' => 18509,
        ], JSON_UNESCAPED_SLASHES),
    ];
    $node = [
        'id' => 2,
        'agent_config_json' => json_encode([
            'wireguard_public_key' => 'slave-public-key-2',
        ], JSON_UNESCAPED_SLASHES),
    ];

    $pairs = call_private($service, 'buildStaticSidecarUplinkPairForNode', $combination, $node);
    assert_same(1, count($pairs), 'allowlisted point must emit one shared pair');
    assert_same('shared', $pairs[0]['sidecar']['lane'] ?? null, 'sidecar lane must be shared');
    assert_same('shared', $pairs[0]['sidecar']['wireguard_peer_mode'] ?? null, 'sidecar peer mode must be shared');
    assert_same('lwsp10', $pairs[0]['sidecar']['interface_name'] ?? null, 'sidecar interface must be point-level');
    assert_same('lwsp10n2', $pairs[0]['slave']['interface_name'] ?? null, 'slave interface must be point-and-node-level');
    assert_same(18502, $pairs[0]['sidecar']['listen_port'] ?? null, 'sidecar must keep the persisted WG listen port');
    assert_same(18502, $pairs[0]['slave']['sidecar_endpoint_port'] ?? null, 'slave must dial the shared WG listen port');

    $samePointOtherCombination = $combination;
    $samePointOtherCombination['id'] = 99;
    $repeat = call_private($service, 'buildStaticSidecarUplinkPairForNode', $samePointOtherCombination, $node);
    assert_same($pairs[0]['sidecar']['interface_name'], $repeat[0]['sidecar']['interface_name'] ?? null, 'combination must not change shared interface identity');
    assert_same($pairs[0]['sidecar']['subnet'], $repeat[0]['sidecar']['subnet'] ?? null, 'combination must not change shared subnet identity');

    $deduped = call_private($service, 'deduplicateStaticSidecarManagementUplinks', [
        $pairs[0]['sidecar'],
        $repeat[0]['sidecar'],
        [
            'wireguard_peer_mode' => 'legacy',
            'interface_name' => 'lws9c13n2p',
            'sidecar_point_id' => 9,
            'node_id' => 2,
        ],
        [
            'wireguard_peer_mode' => 'legacy',
            'interface_name' => 'lws9c13n2b',
            'sidecar_point_id' => 9,
            'node_id' => 2,
        ],
    ]);
    assert_same(3, count($deduped), 'shared peers must deduplicate by point and node while legacy interfaces remain distinct');
    assert_same('lwsp10', $deduped[0]['interface_name'] ?? null, 'first shared peer must be retained');

    $legacyCombination = $combination;
    $legacyCombination['sidecar_point_id'] = 9;
    $legacy = call_private($service, 'buildStaticSidecarUplinkPairForNode', $legacyCombination, $node);
    assert_same(2, count($legacy), 'non-allowlisted point must retain legacy dual lanes');
    assert_true(
        (int) ($legacy[0]['sidecar']['listen_port'] ?? 0) !== 18502,
        'legacy point must continue to use its allocated uplink range'
    );

    @unlink($envPath);
    echo "provisioning_shared_sidecar_wireguard_peers_regression_test passed\n";
}
