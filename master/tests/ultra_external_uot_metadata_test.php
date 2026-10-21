<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<string, mixed> merged into the fixture row */
        public array $overrides = [];
        /** @var array<int, string> */
        public array $secrets = [];
        public int $secretWrites = 0;

        /** @param array<string, mixed> $params */
        public function execute(string $sql, array $params = []): int
        {
            if (str_contains($sql, 'SET external_uot_hmac_secret') && ($this->secrets[(int) $params['id']] ?? '') === '') {
                $this->secrets[(int) $params['id']] = (string) $params['secret'];
                $this->secretWrites++;
                return 1;
            }
            return 0;
        }

        /** @param array<string, mixed> $params */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            return ['external_uot_hmac_secret' => $this->secrets[(int) ($params['id'] ?? 0)] ?? null];
        }

        /** @param array<string, mixed> $params @return array<int, array<string, mixed>> */
        public function fetchAll(string $sql, array $params = []): array
        {
            if (str_contains($sql, 'FROM forwards f') && str_contains($sql, 'external_uot_sidecar_target')) {
                return [$this->overrides + [
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

    $envPath = tempnam(sys_get_temp_dir(), 'lw-external-uot-metadata-');
    if ($envPath === false) {
        fwrite(STDERR, "failed to create test config\n");
        exit(1);
    }
    file_put_contents($envPath, "DEFAULT_TLS_CERT_REF=default-edge-cert\n");

    $service = new ProvisioningService(new Config($envPath), new Database());
    $metadata = $service->ultraLineMetadataForAssignedForwards([[
        'forward_id' => 7,
        'backend_mode' => 'ultra',
        'service_status' => 'active',
        'forward_combination_direction' => 'Shanghai to Japan',
    ]]);
    $external = $metadata['7']['external_uot'] ?? null;
    if (!is_array($external)
        || ($external['enabled'] ?? false) !== true
        || ($external['sidecar_target'] ?? '') !== '185.220.238.153:7443'
        || ($external['token'] ?? '') !== 'metadata-uot-token'
        || ($external['frame_payload_max'] ?? 0) !== 4096) {
        fwrite(STDERR, "external UoT metadata was not propagated\n");
        exit(1);
    }
    if (array_key_exists('external_uot', $metadata['7']['lines'][0] ?? [])) {
        fwrite(STDERR, "external UoT leaked into line metadata\n");
        exit(1);
    }

    // Plan C3: a combination with an HMAC secret hands out the per-forward
    // token; expected value from `uot-sidecar --print-token --secret <hex>
    // --forward-id 170 --target 1.2.3.4:53`.
    $hmacSecret = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    $eligible = [[
        'forward_id' => 170,
        'backend_mode' => 'ultra',
        'service_status' => 'active',
        'forward_combination_direction' => 'Shanghai to Japan',
    ]];
    $database = new Database();
    $database->overrides = [
        'forward_id' => 170, 'forward_combination_id' => 9, 'target_host' => '1.2.3.4', 'target_port' => 53,
        'external_uot_hmac_secret' => $hmacSecret,
    ];
    $metadata = (new ProvisioningService(new Config($envPath), $database))->ultraLineMetadataForAssignedForwards($eligible);
    if (($metadata['170']['external_uot']['token'] ?? '') !== 'hmac-sha256:9f735a3755e0e022eee79297d7b4bf875c3b3d562e9d43166d2a7f2966f29c04'
        || $database->secretWrites !== 0) {
        fwrite(STDERR, "HMAC token not issued from the stored secret\n");
        exit(1);
    }

    // No secret yet: one is generated and stored once, then reused.
    $database = new Database();
    $database->overrides = ['forward_id' => 170, 'forward_combination_id' => 9, 'target_host' => '1.2.3.4', 'target_port' => 53];
    $provisioning = new ProvisioningService(new Config($envPath), $database);
    $first = $provisioning->ultraLineMetadataForAssignedForwards($eligible)['170']['external_uot']['token'] ?? '';
    $database->overrides['external_uot_hmac_secret'] = $database->secrets[9] ?? null;
    $second = $provisioning->ultraLineMetadataForAssignedForwards($eligible)['170']['external_uot']['token'] ?? '';
    if ($database->secretWrites !== 1 || preg_match('/^[0-9a-f]{64}$/', $database->secrets[9] ?? '') !== 1
        || $first !== \Leikwan\Master\Support\ExternalUotCredentials::token($database->secrets[9], 170, '1.2.3.4:53')
        || $first !== $second) {
        fwrite(STDERR, "lazy HMAC secret not generated exactly once\n");
        exit(1);
    }

    // A target the sidecar could not normalize keeps the legacy static token.
    $database = new Database();
    $database->overrides = ['forward_id' => 170, 'forward_combination_id' => 9, 'target_host' => '', 'target_port' => 0,
        'external_uot_hmac_secret' => $hmacSecret];
    $metadata = (new ProvisioningService(new Config($envPath), $database))->ultraLineMetadataForAssignedForwards($eligible);
    if (($metadata['170']['external_uot']['token'] ?? '') !== 'metadata-uot-token') {
        fwrite(STDERR, "untargeted forward lost its static token\n");
        exit(1);
    }

    @unlink($envPath);
    echo "ok\n";
}
