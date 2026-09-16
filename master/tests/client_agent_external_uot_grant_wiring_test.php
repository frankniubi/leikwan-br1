<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<int, array<string, mixed>> */
        public array $queries = [];

        /** @param array<string, mixed> $params @return array<int, array<string, mixed>> */
        public function fetchAll(string $sql, array $params = []): array
        {
            $this->queries[] = $sql;
            if (str_contains($sql, 'FROM forwards f') && str_contains($sql, 'external_uot_sidecar_target')) {
                return [[
                    'forward_id' => 7,
                    'ultra_destination_zone' => 'tokyo',
                    'ultra_allowed_ingress_zones_json' => '["shanghai"]',
                    'ultra_fallback_policy' => 'detected_then_any',
                    'external_uot_enabled' => 1,
                    'external_uot_sidecar_target' => '185.220.238.153:7443',
                    'external_uot_token' => 'metadata-uot-token',
                    'external_uot_queue_packets' => 512,
                    'external_uot_keepalive_seconds' => 15,
                    'external_uot_reconnect_grace_seconds' => 45,
                    'external_uot_frame_payload_max' => 4096,
                    'ultra_line_id' => 10,
                    'sort_order' => 10,
                    'source_id' => 'source-a',
                    'line_id' => 453,
                    'access_class' => 'native_udp',
                    'native_udp_state' => 'verified',
                    'native_socks5_state' => 'unknown',
                    'route_class' => 'direct',
                    'pricing_class' => 'free',
                    'pricing_evidence' => '',
                    'dispatch_class' => 'normal',
                    'ingress_zone' => 'shanghai',
                    'destination_zone' => 'tokyo',
                ]];
            }
            return [];
        }

        /** @param array<string, mixed> $params @return array<string, mixed>|null */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            $this->queries[] = $sql;
            return null;
        }

        /** @param array<string, mixed> $params */
        public function execute(string $sql, array $params = []): int
        {
            $this->queries[] = $sql;
            return 0;
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
    use Leikwan\Master\Controllers\ClientAgentController;
    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Support\LeigodTokenProvider;
    use Leikwan\Master\Support\ProvisioningService;
    use Leikwan\Master\Support\UltraMode;

    function failGrantWiring(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    /**
     * Mirrors the real broker boundary: whatever the controller hands over is
     * recorded, then echoed back inside a short-lived policy-v2 grant.
     */
    final class RecordingUltraTokenProvider implements LeigodTokenProvider
    {
        /** @var array<string, array<string, mixed>> */
        public array $seen = [];
        public bool $sawPolicyV2 = false;
        public bool $issueGrants = true;

        public function grantsForClientForwards(int $clientId, string $deviceId, array $forwards, bool $policyV2 = false): array
        {
            $this->sawPolicyV2 = $policyV2;
            $this->seen = [];
            foreach ($forwards as $forward) {
                if (!is_array($forward)) {
                    continue;
                }
                $this->seen[(string) ((int) ($forward['forward_id'] ?? 0))] = $forward;
            }
            if (!$this->issueGrants) {
                return [];
            }

            $grants = [];
            foreach ($this->seen as $id => $forward) {
                $grant = [
                    'protocol' => $policyV2 ? UltraMode::POLICY_PROTOCOL : UltraMode::CAPABILITY_PROTOCOL,
                    'session_ticket' => 'ticket-' . $id,
                    'token' => 'capability-' . $id,
                    'expires_at' => '2026-09-16T12:00:00Z',
                    'policy_generation' => str_repeat('b', 64),
                ];
                $external = $forward['external_uot'] ?? null;
                if (is_array($external)) {
                    // The real broker normalizes the contract and pins it to the
                    // forward target before the client ever sees it.
                    $normalized = $external;
                    $normalized['target_digest'] = hash('sha256', (string) ($forward['target'] ?? ''));
                    $grant['external_uot'] = $normalized;
                }
                $grants[$id] = $grant;
            }
            return $grants;
        }
    }

    $envPath = tempnam(sys_get_temp_dir(), 'lw-external-uot-grant-');
    if ($envPath === false) {
        failGrantWiring('failed to create test config');
    }
    file_put_contents($envPath, "DEFAULT_TLS_CERT_REF=default-edge-cert\n");

    $config = new Config($envPath);
    $db = new Database();
    $provider = new RecordingUltraTokenProvider();

    $reflection = new ReflectionClass(ClientAgentController::class);
    $controller = $reflection->newInstanceWithoutConstructor();
    foreach ([
        'config' => $config,
        'db' => $db,
        'provisioning' => new ProvisioningService($config, $db),
        'ultraTokenProvider' => $provider,
    ] as $property => $value) {
        $reflection->getProperty($property)->setValue($controller, $value);
    }

    $attach = new ReflectionMethod(ClientAgentController::class, 'attachUltraAccessGrants');
    $forward = [
        'forward_id' => 7,
        'backend_mode' => 'ultra',
        'service_status' => 'active',
        'target' => '203.0.113.9:443',
        'listen_port' => 10001,
        'forward_combination_direction' => 'Shanghai to Japan',
        'forward_combination_external_uot_enabled' => 1,
        'forward_combination_external_uot_revision' => str_repeat('a', 64),
    ];

    $result = $attach->invoke($controller, [$forward], 71, 'device-7', true);

    $brokered = $provider->seen['7']['external_uot'] ?? null;
    if (!is_array($brokered)) {
        failGrantWiring('external UoT contract from the forward combination never reached the token broker');
    }
    if (($brokered['sidecar_target'] ?? '') !== '185.220.238.153:7443'
        || ($brokered['token'] ?? '') !== 'metadata-uot-token'
        || (int) ($brokered['queue_packets'] ?? 0) !== 512
        || (int) ($brokered['frame_payload_max'] ?? 0) !== 4096) {
        failGrantWiring('external UoT contract was passed to the broker incompletely');
    }
    if (!$provider->sawPolicyV2) {
        failGrantWiring('policy v2 flag was not forwarded to the token broker');
    }

    $grant = $result[0][UltraMode::ACCESS_FIELD] ?? null;
    if (!is_array($grant)
        || ($grant['protocol'] ?? '') !== UltraMode::POLICY_PROTOCOL
        || ($grant['external_uot']['token'] ?? '') !== 'metadata-uot-token'
        || ($grant['external_uot']['target_digest'] ?? '') !== hash('sha256', '203.0.113.9:443')) {
        failGrantWiring('external UoT contract was not delivered inside the short-lived Ultra grant');
    }

    foreach (['external_uot', '_ultra_lines', '_ultra_line_ids', '_ultra_destination_zone', '_ultra_fallback_policy'] as $leak) {
        if (array_key_exists($leak, $result[0])) {
            failGrantWiring('scrubbed client forward leaked internal field: ' . $leak);
        }
    }
    if (($result[0]['target'] ?? '') !== '203.0.113.9:443' || (int) ($result[0]['listen_port'] ?? 0) !== 10001) {
        failGrantWiring('scrubbing dropped the client-visible forward contract');
    }

    // A failing grant must not remove the sidecar contract from the broker
    // request, and must not leak the secret into the ordinary config either.
    $provider->issueGrants = false;
    $withoutGrant = $attach->invoke($controller, [$forward], 71, 'device-7', true);
    if (array_key_exists(UltraMode::ACCESS_FIELD, $withoutGrant[0])) {
        failGrantWiring('ultra_access was attached without a valid grant');
    }
    if (($provider->seen['7']['external_uot']['token'] ?? '') !== 'metadata-uot-token') {
        failGrantWiring('external UoT contract stopped reaching the broker when no grant was issued');
    }
    if (array_key_exists('external_uot', $withoutGrant[0])) {
        failGrantWiring('external UoT secret leaked into the grant-free client config');
    }

    // A forward combination without a configured sidecar must stay untouched.
    $plainForward = $forward;
    $plainForward['forward_id'] = 8;
    $provider->issueGrants = true;
    $plainResult = $attach->invoke($controller, [$plainForward], 71, 'device-7', true);
    if (array_key_exists('external_uot', $provider->seen['8'] ?? [])) {
        failGrantWiring('forward without an external UoT sidecar was given a sidecar contract');
    }
    if (array_key_exists('external_uot', $plainResult[0][UltraMode::ACCESS_FIELD] ?? [])) {
        failGrantWiring('grant for a native-UDP forward carried an unexpected external UoT contract');
    }

    @unlink($envPath);
    echo "ok\n";
}

