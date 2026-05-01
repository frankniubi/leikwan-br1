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
    use Leikwan\Master\Controllers\ClientAgentController;
    use Leikwan\Master\Controllers\NodeController;
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

    $envPath = tempnam(sys_get_temp_dir(), 'lw-agent-uot-sidepath-');
    if ($envPath === false) {
        fail('failed to create temp env file');
    }
    file_put_contents($envPath, "AGENT_API_URL=https://panel.example.com\nDEFAULT_TLS_CERT_REF=default-edge-cert\n");

    $config = new Config($envPath);
    $db = new Database();
    $provisioning = new ProvisioningService($config, $db);

    $clientController = new ClientAgentController($config, $db, $provisioning);
    $nodeController = new NodeController($config, $db, $provisioning);

    $sidepath = [
        'enabled_forward_ids' => [26],
        'shadow_route_suffix' => '.uot',
        'sidecar_port' => 8999,
        'reorder_window_packets' => 4096,
    ];

    $clientConfig = call_private($clientController, 'authoritativeAgentConfig', [
        'agent_config_json' => json_encode([
            'poll_interval_seconds' => 60,
            'udp_uot_sidepath' => $sidepath,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    if (($clientConfig['master_url'] ?? null) !== 'https://panel.example.com') {
        fail('expected authoritative client config to keep panel agent base URL');
    }
    if (($clientConfig['poll_interval_seconds'] ?? null) !== 60) {
        fail('expected authoritative client config to preserve poll_interval_seconds');
    }
    if (($clientConfig['udp_uot_sidepath'] ?? null) !== $sidepath) {
        fail('expected authoritative client config to preserve udp_uot_sidepath');
    }

    $nodeConfig = call_private($nodeController, 'authoritativeAgentConfig', [
        'name' => 'gz-node',
        'region' => 'gz',
        'public_ip' => '8.8.8.8',
        'private_ip' => '10.0.0.5',
    ], [
        'poll_interval_seconds' => 30,
        'udp_uot_sidepath' => $sidepath,
    ], 'canton-ali');

    if (($nodeConfig['master_url'] ?? null) !== 'https://panel.example.com') {
        fail('expected authoritative node config to keep panel agent base URL');
    }
    if (($nodeConfig['group_key'] ?? null) !== 'canton-ali') {
        fail('expected authoritative node config to preserve group_key');
    }
    if (($nodeConfig['udp_uot_sidepath'] ?? null) !== $sidepath) {
        fail('expected authoritative node config to preserve udp_uot_sidepath');
    }

    echo "ok\n";
}
