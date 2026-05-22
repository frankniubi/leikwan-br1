<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {

    final class Database
    {
        /**
         * @param array<string, mixed> $params
         * @return array<string, mixed>|null
         */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            return null;
        }

        /**
         * @param array<string, mixed> $params
         * @return array<int, array<string, mixed>>
         */
        public function fetchAll(string $sql, array $params = []): array
        {
            return [];
        }

        /**
         * @param array<string, mixed> $data
         */
        public function insert(string $table, array $data): int
        {
            return 1;
        }

        /**
         * @param array<string, mixed> $params
         */
        public function execute(string $sql, array $params = []): int
        {
            return 0;
        }

        /**
         * @return mixed
         */
        public function transaction(callable $callback)
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
        if ($relative === false) {
            return;
        }

        $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });

    if (!class_exists('Leikwan\\Master\\Exceptions\\ApiException') && class_exists('Leikwan\\Master\\Support\\ApiException')) {
        class_alias('Leikwan\\Master\\Support\\ApiException', 'Leikwan\\Master\\Exceptions\\ApiException');
    }

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

    $envPath = tempnam(sys_get_temp_dir(), 'lw-sidecar-dual-port-env-');
    if ($envPath === false) {
        fail('failed to create temp env file');
    }
    file_put_contents($envPath, "DEFAULT_TLS_CERT_REF=default-edge-cert\nBOND_TOKEN_SECRET=test-token-secret\n");

    $service = new ProvisioningService(new Config($envPath), new Database());

    $point = [
        'listen_port' => 9000,
        'bond_listen_port' => 9000,
        'mptcp_listen_port' => 14969,
    ];
    $pointConfig = [
        'wireguard_listen_port' => 14969,
    ];

    $bondPort = call_private($service, 'sidecarBondListenPort', $point);
    if ($bondPort !== 9000) {
        fail('expected sidecarBondListenPort 9000, got ' . var_export($bondPort, true));
    }

    $mptcpPort = call_private($service, 'sidecarMptcpListenPort', $point, $pointConfig);
    if ($mptcpPort !== 14969) {
        fail('expected sidecarMptcpListenPort 14969, got ' . var_export($mptcpPort, true));
    }

    $legacyFallbackPort = call_private($service, 'sidecarMptcpListenPort', ['listen_port' => 9000], ['wireguard_listen_port' => 14969]);
    if ($legacyFallbackPort !== 14969) {
        fail('expected sidecarMptcpListenPort to fall back to wireguard_listen_port 14969, got ' . var_export($legacyFallbackPort, true));
    }

    $forward = [
        'id' => 25,
        'service_id' => 1,
        'target_host' => '127.0.0.1',
        'target_port' => 5201,
        'bond_remote_host' => 'manual.example.com',
        'bond_remote_port' => 7777,
        'bond_sidecar_host' => 'sidecar.example.com',
        'bond_sidecar_port' => 9000,
        'mptcp_sidecar_port' => 14969,
        'bond_sidecar_point_id' => 4,
        'bond_sidecar_point_token_secret' => 'test-token-secret',
        'bond_sidecar_point_agent_config_json' => json_encode([
            'wireguard_listen_port' => 14969,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'client_mode' => 'mptcp',
    ];

    $bondTarget = call_private($service, 'resolveSidecarDirectTarget', $forward, 'bond');
    if (($bondTarget['host'] ?? null) !== 'sidecar.example.com' || ($bondTarget['port'] ?? null) !== 9000) {
        fail('expected bond direct target sidecar.example.com:9000, got ' . json_encode($bondTarget, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    $mptcpTarget = call_private($service, 'resolveSidecarDirectTarget', $forward, 'mptcp');
    if (($mptcpTarget['host'] ?? null) !== 'sidecar.example.com' || ($mptcpTarget['port'] ?? null) !== 14969) {
        fail('expected mptcp direct target sidecar.example.com:14969, got ' . json_encode($mptcpTarget, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    $mptcpConfig = call_private($service, 'buildMptcpClientConfig', $forward, []);
    if (($mptcpConfig['sidecar_port'] ?? null) !== 14969) {
        fail('expected buildMptcpClientConfig sidecar_port 14969, got ' . var_export($mptcpConfig['sidecar_port'] ?? null, true));
    }
    if (($mptcpConfig['remote_port'] ?? null) !== 14969) {
        fail('expected buildMptcpClientConfig remote_port 14969, got ' . var_export($mptcpConfig['remote_port'] ?? null, true));
    }



    putenv('MPTCP_SLAVE_PRECONNECT=true');
    putenv('MPTCP_PRECONNECT_RELAY_PORT_RANGE_START=7000');
    putenv('MPTCP_PRECONNECT_RELAY_PORT_RANGE_END=9000');
    $forwardWithEndpoint = $forward + [
        'bond_sidecar_point_agent_config_json' => json_encode([
            'wireguard_public_key' => 'sidecar-public-key',
            'wireguard_listen_port' => 14969,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
    $endpoints = [[
        'id' => 179,
        'node_id' => 5,
        'node_name' => 'gz-node',
        'public_host' => '8.8.8.8',
        'public_ip' => '8.8.8.8',
        'public_port' => 7001,
        'node_agent_config_json' => json_encode([
            'wireguard_public_key' => 'slave-public-key',
            'wireguard_listen_port' => 7001,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]];
    $preconnectConfig = call_private($service, 'buildMptcpClientConfig', $forwardWithEndpoint, $endpoints);
    if (($preconnectConfig['slave_relay_host'] ?? '') === '') {
        fail('expected buildMptcpClientConfig to publish slave_relay_host when MPTCP_SLAVE_PRECONNECT=true');
    }
    $slaveRelayPort = (int) ($preconnectConfig['slave_relay_port'] ?? 0);
    if ($slaveRelayPort < 7000 || $slaveRelayPort > 9000) {
        fail('expected slave_relay_port to stay within 7000-9000, got ' . var_export($slaveRelayPort, true));
    }
    $slaveUotRelayPort = (int) ($preconnectConfig['slave_uot_relay_port'] ?? 0);
    if ($slaveUotRelayPort < 7000 || $slaveUotRelayPort > 9000) {
        fail('expected slave_uot_relay_port to stay within 7000-9000, got ' . var_export($slaveUotRelayPort, true));
    }
    if ($slaveUotRelayPort === $slaveRelayPort) {
        fail('expected slave_uot_relay_port to be distinct from slave_relay_port');
    }
    putenv('MPTCP_SLAVE_PRECONNECT');
    putenv('MPTCP_PRECONNECT_RELAY_PORT_RANGE_START');
    putenv('MPTCP_PRECONNECT_RELAY_PORT_RANGE_END');
    echo "ok\n";
}
