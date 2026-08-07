<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<int, array<string, mixed>> */
        public array $availabilityRows = [];
        public int $raceEnabled = 1;

        public function fetchOne(string $sql, array $params = []): ?array
        {
            if (str_contains($sql, 'FROM forward_routes')) {
                return ['id' => 9, 'race_enabled' => $this->raceEnabled, 'is_active' => 1];
            }
            return null;
        }

        public function fetchAll(string $sql, array $params = []): array
        {
            if (str_contains($sql, 'FROM forward_routes fr')) {
                return $this->availabilityRows;
            }
            return [];
        }

        public function execute(string $sql, array $params = []): int { return 0; }
        public function insert(string $table, array $row): int { return 1; }
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

    function failPublish(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    function assertPublishSame(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            failPublish($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    function callPublishPrivate(object $instance, string $method, mixed ...$args): mixed
    {
        $reflection = new ReflectionMethod($instance, $method);
        $reflection->setAccessible(true);
        return $reflection->invoke($instance, ...$args);
    }

    $toml451 = "line = 451\n";
    $toml453 = "line = 453\n";
    $hash451 = hash('sha256', $toml451);
    $hash453 = hash('sha256', $toml453);
    $envPath = tempnam(sys_get_temp_dir(), 'lw-dedicated-publish-');
    if ($envPath === false) {
        failPublish('unable to create temp env');
    }
    file_put_contents($envPath, implode("\n", [
        'DEFAULT_TLS_CERT_REF=default-edge-cert',
        'LEIGOD_LINE_TOML_451=' . base64_encode($toml451),
        'LEIGOD_LINE_TOML_453=' . base64_encode($toml453),
        'LEIGOD_SESSION_JSON_B64=' . base64_encode('{"session":"supersecret"}'),
        '',
    ]));

    $db = new Database();
    $base = [
        'route_id' => 9,
        'node_id' => 12,
        'node_name' => 'sha-node-12',
        'node_public_ip' => '203.0.113.12',
        'node_status' => 'active',
        'line_is_active' => 1,
        'service_state' => 'active',
        'tcp_status' => 'healthy',
        'tcp_connect_ms' => 10,
        'udp_status' => 'unknown',
        'checked_at' => gmdate('Y-m-d H:i:s'),
        'membership_sort_order' => 10,
    ];
    $db->availabilityRows = [
        array_replace($base, ['combination_id' => 10, 'combination_sort_order' => 10, 'line_id' => 451, 'config_sha256' => $hash451, 'health_config_sha256' => $hash451]),
        array_replace($base, ['combination_id' => 11, 'combination_sort_order' => 20, 'line_id' => 453, 'config_sha256' => $hash453, 'health_config_sha256' => $hash453]),
    ];

    $service = new ProvisioningService(new Config($envPath), $db);
    $forward = [
        'id' => 47,
        'forward_route_id' => 9,
        'forward_combination_id' => 10,
        'client_mode' => 'single',
        'forward_combination_runtime_preference' => 'single',
        'bond_ingress_mode' => 'plain_tcp_mux',
        'backend_mode' => 'leigod_mode',
        'public_port' => 443,
        'path_prefix' => 'dedicated-route',
        'sni_host' => 'edge.example.com',
        'cdn_host' => 'edge.example.com',
        'filter_profile' => 'off',
        'transport_mode' => 'tcp',
        'bond_sidecar_point_id' => null,
        'target_host' => '162.245.221.78',
        'target_port' => 80,
    ];
    $node = ['id' => 12, 'node_group_key' => 'shanghai-alive', 'agent_config_json' => null];
    $payload = callPublishPrivate($service, 'publishTaskPayloadForForward', $forward, $node, 316);

    assertPublishSame('race', $payload['leigod_forward']['mode'] ?? null, 'relay mode');
    assertPublishSame('connect_latency', $payload['leigod_forward']['selection_signal'] ?? null, 'default race signal');
    if (array_key_exists('throughput_min_bytes', $payload['leigod_forward'] ?? [])) {
        failPublish('default race unexpectedly publishes throughput policy');
    }
    assertPublishSame([451, 453], array_column($payload['leigod_forward']['candidates'] ?? [], 'line_id'), 'ordered line candidates');
    assertPublishSame('127.0.0.1:48316', $payload['target'] ?? null, 'slave runtime target');
    assertPublishSame(48316, $payload['leigod_forward']['relay_port'] ?? null, 'relay port');
    assertPublishSame('162.245.221.78:80', $payload['leigod_forward']['target'] ?? null, 'real relay target');
    if (array_key_exists('toml', $payload['leigod_forward']['candidates'][0] ?? [])) {
        failPublish('candidate payload exposes TOML');
    }
    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '';
    if (str_contains(strtolower($encoded), 'session') || str_contains($encoded, 'supersecret')) {
        failPublish('publish payload exposes session material');
    }
    $fingerprint = callPublishPrivate($service, 'desiredEndpointPayloadFingerprint', $forward, $node, $payload);
    $changedPayload = $payload;
    $changedPayload['leigod_forward']['candidates'][1]['tcp_status'] = 'unhealthy';
    $changedFingerprint = callPublishPrivate($service, 'desiredEndpointPayloadFingerprint', $forward, $node, $changedPayload);
    if ($fingerprint === $changedFingerprint) {
        failPublish('material candidate health change did not change the publish fingerprint');
    }

    file_put_contents($envPath, implode("\n", [
        'LEIGOD_RACE_SELECTION_SIGNAL=throughput_ewma',
        'LEIGOD_RACE_THROUGHPUT_MIN_BYTES=1048576',
        'LEIGOD_RACE_THROUGHPUT_MIN_SECONDS=2',
        'LEIGOD_RACE_THROUGHPUT_EWMA_ALPHA=0.25',
        'LEIGOD_RACE_THROUGHPUT_EXPLORE_EVERY_CONNECTIONS=20',
        'LEIGOD_RACE_THROUGHPUT_SAMPLE_TTL_SECONDS=1800',
        'LEIGOD_RACE_THROUGHPUT_STALL_INTERVAL_SECONDS=1',
        '',
    ]), FILE_APPEND);
    $throughputService = new ProvisioningService(new Config($envPath), $db);
    $throughputPayload = callPublishPrivate($throughputService, 'publishTaskPayloadForForward', $forward, $node, 316);
    $expectedThroughputPolicy = [
        'selection_signal' => 'throughput_ewma',
        'throughput_min_bytes' => 1048576,
        'throughput_min_seconds' => 2.0,
        'throughput_ewma_alpha' => 0.25,
        'throughput_explore_every_connections' => 20,
        'throughput_sample_ttl_seconds' => 1800.0,
        'throughput_stall_interval_seconds' => 1.0,
    ];
    foreach ($expectedThroughputPolicy as $key => $value) {
        assertPublishSame($value, $throughputPayload['leigod_forward'][$key] ?? null, 'throughput policy ' . $key);
    }

    $db->raceEnabled = 0;
    $singlePayload = callPublishPrivate($throughputService, 'publishTaskPayloadForForward', $forward, $node, 316);
    assertPublishSame('single', $singlePayload['leigod_forward']['mode'] ?? null, 'non-race relay mode');
    foreach (array_keys($expectedThroughputPolicy) as $key) {
        if (array_key_exists($key, $singlePayload['leigod_forward'] ?? [])) {
            failPublish('single relay unexpectedly publishes race policy key ' . $key);
        }
    }

    @unlink($envPath);
    echo "ok\n";
}
