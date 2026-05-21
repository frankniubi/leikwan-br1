<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<string, array<int, array<string, mixed>>> */
        private array $tables = [];

        /** @param array<int, array<string, mixed>> $rows */
        public function seed(string $table, array $rows): void
        {
            $this->tables[$table] = array_values($rows);
        }

        /** @param array<string, mixed> $params */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            $normalized = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
            if (str_contains($normalized, 'SELECT client_token FROM services WHERE id = :id LIMIT 1')) {
                $row = $this->findById('services', (int) ($params['id'] ?? 0));
                return $row === null ? null : ['client_token' => $row['client_token'] ?? ''];
            }
            if (str_contains($normalized, 'SELECT * FROM cf_location_groups WHERE id = :id LIMIT 1')) {
                return $this->findById('cf_location_groups', (int) ($params['id'] ?? 0));
            }
            if (str_contains($normalized, 'SELECT agent_config_json FROM clients WHERE id = :id LIMIT 1')) {
                $row = $this->findById('clients', (int) ($params['id'] ?? 0));
                return $row === null ? null : ['agent_config_json' => $row['agent_config_json'] ?? null];
            }
            if (str_contains($normalized, 'SELECT agent_config_json, sidecar_host, listen_port, bond_listen_port, mptcp_listen_port FROM sidecar_points WHERE id = :id LIMIT 1')) {
                return $this->findById('sidecar_points', (int) ($params['id'] ?? 0));
            }
            if (str_contains($normalized, 'SELECT sidecar_host, listen_port, bond_listen_port, mptcp_listen_port, cf_listen_port, agent_config_json FROM sidecar_points WHERE id = :id LIMIT 1')) {
                return $this->findById('sidecar_points', (int) ($params['id'] ?? 0));
            }
            return null;
        }

        /** @param array<string, mixed> $params */
        public function fetchAll(string $sql, array $params = []): array
        {
            $normalized = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
            if (str_contains($normalized, 'FROM cf_location_group_cidrs WHERE group_id = :group_id')) {
                return $this->tables['cf_location_group_cidrs'] ?? [];
            }
            return [];
        }

        private function findById(string $table, int $id): ?array
        {
            foreach ($this->tables[$table] ?? [] as $row) {
                if ((int) ($row['id'] ?? 0) === $id) {
                    return $row;
                }
            }
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

    $envPath = tempnam(sys_get_temp_dir(), 'lw-cfwg-relay-contract-env-');
    if ($envPath === false) {
        fail('failed to create temp env file');
    }
    file_put_contents($envPath, "DEFAULT_TLS_CERT_REF=default-edge-cert\nBOND_TOKEN_SECRET=test-token-secret\n");

    $db = new Database();
    $db->seed('services', [
        ['id' => 2, 'client_token' => 'service-token', 'status' => 'active'],
    ]);
    $db->seed('clients', [
        [
            'id' => 3,
            'agent_config_json' => json_encode(['wireguard_public_key' => 'client-public-key'], JSON_UNESCAPED_SLASHES),
        ],
    ]);
    $db->seed('sidecar_points', [
        [
            'id' => 5,
            'sidecar_host' => '185.220.238.143',
            'listen_port' => 8899,
            'bond_listen_port' => 8899,
            'mptcp_listen_port' => 8898,
            'cf_listen_port' => 2053,
            'agent_config_json' => json_encode([
                'wireguard_public_key' => 'sidecar-public-key',
                'wireguard_listen_port' => 14969,
                'cf_sni_host' => 'nrt1.nbwly.com',
            ], JSON_UNESCAPED_SLASHES),
        ],
    ]);
    $db->seed('cf_location_groups', [
        ['id' => 1, 'name' => 'JP', 'region_code' => 'JP', 'probe_mode' => 'tcp', 'cache_ttl_seconds' => 120, 'switch_improvement_ms' => 15, 'max_candidates' => 32],
    ]);
    $db->seed('cf_location_group_cidrs', [
        ['cidr' => '162.159.39.0/24', 'priority' => 10, 'is_active' => 1],
    ]);

    $service = new ProvisioningService(new Config($envPath), $db);
    $forward = [
        'id' => 47,
        'service_id' => 2,
        'client_token' => 'service-token',
        'line_client_id' => 3,
        'client_mode' => 'mptcp',
        'forward_combination_runtime_preference' => 'mptcp_preferred_bond_fallback',
        'forward_combination_allow_private_self_leg' => 1,
        'forward_combination_self_leg_cf_transport' => 'cf_wg',
        'runtime_preference' => 'mptcp_preferred_bond_fallback',
        'self_leg_cf_transport' => 'cf_wg',
        'bond_ingress_mode' => 'plain_tcp_mux',
        'backend_mode' => 'cf_mode',
        'public_port' => 7443,
        'path_prefix' => '07289d1f5ed25399',
        'sni_host' => 'nrt1.nbwly.com',
        'sni' => 'nrt1.nbwly.com',
        'cdn_host' => 'nrt1.nbwly.com',
        'filter_profile' => 'off',
        'transport_mode' => 'tcp',
        'bond_remote_host' => '185.220.238.143',
        'bond_remote_port' => 8898,
        'bond_sidecar_host' => '185.220.238.143',
        'bond_sidecar_port' => 8899,
        'mptcp_sidecar_port' => 8898,
        'bond_sidecar_cf_listen_port' => 2053,
        'bond_sidecar_point_id' => 5,
        'bond_sidecar_point_agent_config_json' => json_encode([
            'wireguard_public_key' => 'sidecar-public-key',
            'wireguard_listen_port' => 14969,
            'cf_sni_host' => 'nrt1.nbwly.com',
        ], JSON_UNESCAPED_SLASHES),
        'line_client_agent_config_json' => json_encode(['wireguard_public_key' => 'client-public-key'], JSON_UNESCAPED_SLASHES),
        'cf_location_group_id' => 1,
        'target_host' => '185.220.238.143',
        'target_port' => 5201,
    ];
    $relayCapableNode = [
        'id' => 12,
        'agent_version' => '2026.05.22.0',
        'public_ip' => '47.116.11.144',
        'resolved_public_host' => '47.116.11.144',
        'agent_config_json' => json_encode([
            'wireguard_public_key' => 'slave-public-key',
            'wireguard_listen_port' => 7443,
            'wireguard_outer_transport' => 'udp',
            'supports_cfwg_relay' => true,
        ], JSON_UNESCAPED_SLASHES),
    ];

    $payload = call_private($service, 'publishTaskPayloadForForward', $forward, $relayCapableNode, 316);
    $relay = $payload['cfwg_relay'] ?? null;
    if (!is_array($relay) || ($relay['enabled'] ?? null) !== true) {
        fail('expected relay-capable slave publish payload to include enabled cfwg_relay');
    }
    $slavePath = trim((string) ($relay['slave_path_prefix'] ?? ''));
    if ($slavePath === '') {
        fail('expected generated cfwg relay slave_path_prefix');
    }
    if (str_contains(strtolower($slavePath), 'cfwg') || str_contains(strtolower($slavePath), 'wg') || str_contains(strtolower($slavePath), 'vpn')) {
        fail('expected camouflage path without internal tunnel naming, got ' . $slavePath);
    }
    if (($relay['path_algorithm'] ?? null) !== 'cfwg-relay-v1') {
        fail('expected relay path algorithm marker');
    }
    if (($relay['cf_path_prefix'] ?? null) !== '07289d1f5ed25399.cfwg') {
        fail('expected relay to target existing direct .cfwg sidecar path');
    }

    $clientConfig = $forward;
    $clientConfig['forward_id'] = 47;
    $clientConfig['self_leg_cf_transport'] = 'cf_wg';
    $clientConfig['bond_sidecar_wireguard_listen_port'] = 14969;
    $clientConfig['endpoints'] = [[
        'endpoint_id' => 316,
        'node_id' => 12,
        'node_agent_version' => '2026.05.22.0',
        'host' => '47.116.11.144',
        'port' => 7443,
        'cdn_host' => 'nrt1.nbwly.com',
        'supports_cfwg_relay' => true,
    ]];
    $candidate = call_private($service, 'buildSelfLegCandidate', $clientConfig, [
        'id' => 3,
        'agent_config_json' => json_encode(['wireguard_public_key' => 'client-public-key'], JSON_UNESCAPED_SLASHES),
    ], [
        'id' => 99,
        'device_id' => 'device-1',
    ]);
    if (!is_array($candidate)) {
        fail('expected self-leg candidate');
    }
    $clientRelay = $candidate['self_leg_wg']['slave_relay'] ?? null;
    if (!is_array($clientRelay) || ($clientRelay['enabled'] ?? null) !== true) {
        fail('expected client config self_leg_wg.slave_relay');
    }
    if (($clientRelay['slave_path_prefix'] ?? null) !== $slavePath) {
        fail('expected panel to publish the same relay path to client and slave');
    }
    if ((int) ($clientRelay['slave_port'] ?? 0) !== 7443) {
        fail('expected relay slave_port to use slave endpoint public port in 7000-9000 range');
    }

    $clientConfig['endpoints'][0]['endpoint_id'] = 999;
    $candidateWithNewEndpointId = call_private($service, 'buildSelfLegCandidate', $clientConfig, [
        'id' => 3,
        'agent_config_json' => json_encode(['wireguard_public_key' => 'client-public-key'], JSON_UNESCAPED_SLASHES),
    ], [
        'id' => 99,
        'device_id' => 'device-1',
    ]);
    if (($candidateWithNewEndpointId['self_leg_wg']['slave_relay']['slave_path_prefix'] ?? null) !== $slavePath) {
        fail('expected relay path to be stable across service endpoint id churn');
    }

    $oldNode = $relayCapableNode;
    $oldNode['agent_version'] = '2026.05.21.3';
    $oldNode['agent_config_json'] = json_encode(['supports_cfwg_relay' => false], JSON_UNESCAPED_SLASHES);
    $oldPayload = call_private($service, 'publishTaskPayloadForForward', $forward, $oldNode, 316);
    if (array_key_exists('cfwg_relay', $oldPayload)) {
        fail('expected old/non-capable slave agent payload to omit cfwg_relay');
    }

    echo "ok\n";
}
