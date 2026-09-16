<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @param array<string, mixed> $params @return array<int, array<string, mixed>> */
        public function fetchAll(string $sql, array $params = []): array
        {
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

    @unlink($envPath);
    echo "ok\n";
}
