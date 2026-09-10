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

    $envPath = tempnam(sys_get_temp_dir(), 'lw-mptcp-bond-payload-env-');
    if ($envPath === false) {
        fail('failed to create temp env file');
    }
    file_put_contents($envPath, "DEFAULT_TLS_CERT_REF=default-edge-cert\nBOND_TOKEN_SECRET=test-token-secret\n");

    $db = new Database();
    $db->seed('services', [
        ['id' => 2, 'client_token' => 'service-token'],
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
        'bond_ingress_mode' => 'plain_tcp_mux',
        'backend_mode' => 'cf_mode',
        'public_port' => 7000,
        'path_prefix' => '07289d1f5ed25399',
        'sni_host' => 'nrt1.nbwly.com',
        'cdn_host' => 'nrt1.nbwly.com',
        'filter_profile' => 'off',
        'transport_mode' => 'tcp',
        'bond_remote_host' => '185.220.238.143',
        'bond_remote_port' => 8898,
        'bond_sidecar_host' => '185.220.238.143',
        'bond_sidecar_port' => 8899,
        'mptcp_sidecar_port' => 8898,
        'bond_sidecar_point_id' => 5,
        'bond_sidecar_point_agent_config_json' => json_encode([
            'wireguard_public_key' => 'sidecar-public-key',
            'wireguard_listen_port' => 14969,
            'cf_sni_host' => 'nrt1.nbwly.com',
        ], JSON_UNESCAPED_SLASHES),
        'cf_location_group_id' => 1,
        'target_host' => '185.220.238.143',
        'target_port' => 5201,
    ];
    $node = [
        'id' => 12,
        'agent_version' => '2026.05.09.2',
        'agent_config_json' => json_encode([
            'wireguard_public_key' => 'slave-public-key',
            'wireguard_listen_port' => 7000,
            'wireguard_outer_transport' => 'udp',
        ], JSON_UNESCAPED_SLASHES),
    ];

    $payload = call_private($service, 'publishTaskPayloadForForward', $forward, $node, 316);

    if (($payload['ingress_protocol'] ?? null) !== 'plain_tcp_mux') {
        fail('expected mptcp preferred bond fallback slave payload to publish plain_tcp_mux, got ' . var_export($payload['ingress_protocol'] ?? null, true));
    }
    if (trim((string) ($payload['ingress_auth_token'] ?? '')) === '') {
        fail('expected mptcp preferred bond fallback slave payload to include ingress auth token');
    }
    if (($payload['target'] ?? null) !== '127.0.0.1:8899') {
        fail('expected cf_mode bond fallback slave target 127.0.0.1:8899, got ' . var_export($payload['target'] ?? null, true));
    }
    if (!is_array($payload['mptcp_underlay'] ?? null) || !($payload['mptcp_underlay']['enabled'] ?? false)) {
        fail('expected payload to keep mptcp underlay while publishing bond fallback listener');
    }

    echo "ok\n";
}
