<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<int, array<string, mixed>> */
        public array $localLeases = [];
        /** @var array<int, array<string, mixed>> */
        public array $candidateRows = [];
        /** @var array<int, array<string, mixed>> */
        public array $clientCatalogRows = [];
        /** @var array<string, mixed>|null */
        public ?array $egressProfile = null;
        /** @var array<int, array<string, mixed>> */
        public array $endpoints = [];
        /** @var array<string, mixed>|null */
        public ?array $assignedClient = null;
        /** @var array<string, mixed>|null */
        public ?array $assignedForward = null;
        /** @var array<string, mixed>|null */
        public ?array $builtForward = null;
        public bool $failOnSidecarQuery = false;

        public function fetchAll(string $sql, array $params = []): array
        {
            if (str_contains($sql, 'SELECT f.id, f.service_id, s.status AS service_status')) {
                return is_array($this->assignedForward) ? [[
                    'id' => (int) ($this->assignedForward['id'] ?? 0),
                    'service_id' => (int) ($this->assignedForward['service_id'] ?? 0),
                    'service_status' => 'active',
                ]] : [];
            }
            if (str_contains($sql, 'FROM service_endpoints e')) {
                return [];
            }
            if (str_contains($sql, 'hybrid_local_leases')) {
                $approvedIds = [];
                foreach ($params as $key => $value) {
                    if (str_starts_with((string) $key, 'hybrid_catalog_')) {
                        $approvedIds[] = (int) $value;
                    }
                }
                return array_values(array_filter(
                    $this->localLeases,
                    static fn(array $lease): bool => $approvedIds === []
                        || in_array((int) ($lease['catalog_id'] ?? 0), $approvedIds, true)
                ));
            }
            if (str_contains($sql, 'hybrid_local_parallel_catalog_ids')) {
                $catalogIds = array_map('intval', array_values($params));
                return array_values(array_map(
                    static fn(array $row): array => ['catalog_id' => (int) $row['catalog_id']],
                    array_filter(
                        $this->clientCatalogRows,
                        static fn(array $row): bool => in_array((int) ($row['catalog_id'] ?? 0), $catalogIds, true)
                            && (int) ($row['max_parallel_lanes'] ?? 0) >= 2
                            && (int) ($row['max_parallel_lanes'] ?? 0) <= 4
                    )
                ));
            }
            if (str_contains($sql, 'FROM leigod_execution_leases lel')) {
                $candidateIds = [];
                foreach ($params as $key => $value) {
                    if (str_starts_with((string) $key, 'candidate_')) {
                        $candidateIds[] = (int) $value;
                    }
                }
                return array_map(static fn(array $lease): array => [
                    'catalog_id' => (int) $lease['catalog_id'],
                    'line_id' => (int) $lease['physical_line_id'],
                    'config_sha256' => (string) $lease['config_sha256'],
                    'is_active' => 1,
                    'allow_client_execution' => 1,
                    'lease_id' => (string) $lease['lease_id'],
                    'lease_generation' => (int) $lease['lease_generation'],
                    'lease_state' => (string) $lease['lease_state'],
                    'lease_owner_type' => 'client_device',
                    'lease_owner_id' => 20,
                    'lease_owner_device_id' => 'linux-device-a',
                    'max_parallel_lanes' => (int) $lease['max_parallel_lanes'],
                    'lease_ready_deadline_at' => (string) $lease['lease_expires_at'],
                    'lease_expires_at' => (string) $lease['lease_expires_at'],
                ], array_values(array_filter(
                    $this->localLeases,
                    static fn(array $lease): bool => $candidateIds === []
                        || in_array((int) ($lease['catalog_id'] ?? 0), $candidateIds, true)
                )));
            }
            if (str_contains($sql, 'hybrid_client_catalog_ids')) {
                return $this->clientCatalogRows;
            }
            if (str_contains($sql, 'node_leigod_line_health')) {
                return $this->candidateRows;
            }
            return [];
        }

        public function fetchOne(string $sql, array $params = []): ?array
        {
            if ($this->failOnSidecarQuery && str_contains($sql, 'sidecar_points')) {
                throw new \RuntimeException('sidecar query forbidden in linux_hybrid test');
            }
            if (str_contains($sql, 'leigod_egress_profiles')) {
                return $this->egressProfile;
            }
            if (str_contains($sql, 'SELECT * FROM clients WHERE id = :id LIMIT 1')) {
                return $this->assignedClient;
            }
            if (str_contains($sql, 'SELECT * FROM forwards WHERE id = :id LIMIT 1')) {
                return $this->assignedForward;
            }
            if (str_contains($sql, 'SELECT f.*, s.client_token')) {
                return $this->builtForward;
            }
            if (str_contains($sql, 'FROM forward_routes')) {
                return ['id' => (int) ($params['id'] ?? 80), 'is_active' => 1, 'race_enabled' => 0];
            }
            if (str_contains($sql, 'service_endpoints')) {
                return $this->endpoints[(int) ($params['endpoint_id'] ?? $params['id'] ?? 0)] ?? null;
            }
            return null;
        }

        public function execute(string $sql, array $params = []): int { return 0; }
        public function insert(string $table, array $row): int { return 1; }
        public function transaction(callable $callback): mixed { return $callback($this); }
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
    use Leikwan\Master\Support\LeigodHybridRuntimeService;
    use Leikwan\Master\Support\LeigodLineCatalogService;

    /** @return string */
    function hybridBase64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array{service:LeigodHybridRuntimeService,forward:array<string,mixed>,client:array<string,mixed>,session:array<string,mixed>,endpoints:array<int,array<string,mixed>>,db:Database,config:Config,env:string}
     */
    function hybridFixture(array $overrides = []): array
    {
        $localToml = "line = 451\n";
        $nodeTomlA = "line = 452\n";
        $nodeTomlB = "line = 453\n";
        $secretRef = (string) ($overrides['secret_ref'] ?? 'LEIGOD_EGRESS_TEST_SECRET');
        $envPath = tempnam(sys_get_temp_dir(), 'lw-hybrid-');
        if ($envPath === false) {
            throw new RuntimeException('unable to create hybrid env');
        }
        $env = [
            'LEIGOD_LINUX_HYBRID_ENABLED=' . (($overrides['hybrid_gate'] ?? true) ? '1' : '0'),
            'LEIGOD_EXECUTION_LEASES_ENABLED=' . (($overrides['lease_gate'] ?? true) ? '1' : '0'),
            'LEIGOD_LINE_TOML_451=' . base64_encode($localToml),
            'LEIGOD_LINE_TOML_452=' . base64_encode($nodeTomlA),
            'LEIGOD_LINE_TOML_453=' . base64_encode($nodeTomlB),
            'LEIGOD_SESSION_JSON_B64=' . base64_encode('{"session":"hybrid-contract"}'),
        ];
        if (($overrides['secret_present'] ?? true) === true) {
            $env[] = $secretRef . '=egress-secret';
        }
        file_put_contents($envPath, implode("\n", $env) . "\n");
        $config = new Config($envPath);
        $db = new Database();
        $now = time();
        $db->localLeases = ($overrides['local_leases'] ?? true) ? [[
            'catalog_id' => 11,
            'physical_line_id' => 451,
            'catalog_sort_order' => 10,
            'config_sha256' => hash('sha256', $localToml),
            'lease_id' => '00000000-0000-4000-8000-000000000020',
            'lease_generation' => 7,
            'lease_state' => 'active',
            'max_parallel_lanes' => (int) ($overrides['local_max_lanes'] ?? 2),
            'lease_expires_at' => gmdate('Y-m-d H:i:s', $now + 90),
        ]] : [];
        $candidate = static function (int $nodeId, int $lineId, string $toml, string $leaseId) use ($now): array {
            return [
                'route_id' => 0,
                'combination_sort_order' => 10,
                'combination_id' => 100,
                'membership_sort_order' => 10,
                'catalog_sort_order' => $lineId,
                'catalog_id' => $lineId - 440,
                'line_id' => $lineId,
                'config_sha256' => hash('sha256', $toml),
                'line_is_active' => 1,
                'node_id' => $nodeId,
                'node_name' => 'node-' . $nodeId,
                'node_public_ip' => '192.0.2.' . ($nodeId - 100),
                'node_private_ip' => '10.0.0.' . ($nodeId - 100),
                'node_status' => 'active',
                'node_group_key' => 'group-a',
                'health_config_sha256' => hash('sha256', $toml),
                'service_state' => 'active',
                'tcp_status' => 'healthy',
                'udp_status' => 'unknown',
                'checked_at' => gmdate('Y-m-d H:i:s'),
                'lease_id' => $leaseId,
                'lease_generation' => 3,
                'lease_state' => 'active',
                'lease_owner_type' => 'node',
                'lease_owner_id' => $nodeId,
                'max_parallel_lanes' => 1,
                'lease_expires_at' => gmdate('Y-m-d H:i:s', $now + 90),
                'health_lease_id' => $leaseId,
                'health_lease_generation' => 3,
            ];
        };
        $db->candidateRows = [
            $candidate(101, 452, $nodeTomlA, '00000000-0000-4000-8000-000000000101'),
            $candidate(102, 453, $nodeTomlB, '00000000-0000-4000-8000-000000000102'),
        ];
        $defaultClientCatalogRows = [[
            'catalog_id' => 11,
            'line_id' => 451,
            'config_sha256' => hash('sha256', $localToml),
            'is_active' => 1,
            'allow_client_execution' => 1,
            'max_parallel_lanes' => (int) ($overrides['local_max_lanes'] ?? 2),
        ]];
        $db->clientCatalogRows = is_array($overrides['client_catalog_rows'] ?? null)
            ? $overrides['client_catalog_rows']
            : $defaultClientCatalogRows;
        if (($overrides['single_execution_group'] ?? false) === true) {
            $db->candidateRows = [];
        }
        $db->egressProfile = ($overrides['profile_present'] ?? true) ? [
            'id' => 5,
            'profile_key' => 'hk-egress',
            'region' => 'hk',
            'host' => '198.51.100.50',
            'tcp_port' => 7443,
            'secret_ref' => $secretRef,
            'is_enabled' => ($overrides['profile_enabled'] ?? true) ? 1 : 0,
        ] : null;
        $db->endpoints = [
            1 => ['id' => 1, 'node_id' => 101],
            2 => ['id' => 2, 'node_id' => 102],
        ];
        $forward = array_replace([
            'id' => 70,
            'forward_id' => 70,
            'service_id' => 9,
            'backend_mode' => 'leigod_mode',
            'client_mode' => 'bond',
            'mode' => 'bond',
            'transport_mode' => 'tcp',
            'target_host' => 'v4-hkt-b.mcbar.cc',
            'target_port' => 5201,
            'path_prefix' => 'hybrid-70',
            'forward_route_id' => 80,
            'forward_combination_id' => 100,
            'bond_sidecar_point_id' => 99,
        ], is_array($overrides['forward'] ?? null) ? $overrides['forward'] : []);
        $client = ['id' => 20, 'runtime_platform_scope' => 'linux'];
        $artifact = ['version' => '0.2.0', 'sha256' => str_repeat('b', 64)];
        $session = array_replace([
            'id' => 30,
            'client_id' => 20,
            'device_id' => 'linux-device-a',
            'platform' => (string) ($overrides['platform'] ?? 'linux-amd64'),
            'pathd_capability' => [
                'version' => '0.2.0',
                'sha256' => (string) ($overrides['capability_sha'] ?? str_repeat('b', 64)),
            ],
        ], is_array($overrides['session'] ?? null) ? $overrides['session'] : []);
        $endpoints = [
            ['endpoint_id' => 1, 'node_id' => 101, 'host' => '192.0.2.1', 'port' => 8001, 'ingress_auth_token' => 'auth-1'],
            ['endpoint_id' => 2, 'node_id' => 102, 'host' => '192.0.2.2', 'port' => 8002, 'ingress_auth_token' => 'auth-2'],
        ];
        if (($overrides['single_execution_group'] ?? false) === true) {
            $endpoints = [];
        }
        $db->assignedClient = $client + ['group_id' => null];
        $db->assignedForward = $forward + [
            'enabled' => 1,
            'line_client_id' => 20,
            'line_client_group_id' => null,
            'line_client_group_ids_json' => null,
        ];
        $db->builtForward = $db->assignedForward + [
            'client_token' => 'client-token',
            'service_status' => 'active',
            'service_name' => 'hybrid-service',
            'service_bandwidth_limit_mbps' => 0,
            'listen_port' => 8070,
            'public_port' => 8070,
            'transport_mode' => 'tcp',
            'bond_ingress_mode' => 'plain_tcp_mux',
            'bond_remote_host' => '198.51.100.50',
            'bond_remote_port' => 7443,
            'bond_sidecar_point_name' => 'hybrid-sidecar',
            'bond_sidecar_host' => '198.51.100.50',
            'bond_sidecar_legacy_port' => 7443,
            'bond_sidecar_port' => 7443,
            'mptcp_sidecar_port' => 7444,
            'bond_sidecar_cf_listen_port' => 0,
            'bond_sidecar_point_token_secret' => 'bond-secret',
            'bond_sidecar_point_agent_config_json' => '{}',
            'line_client_name' => 'linux-client',
            'line_client_host' => '127.0.0.1',
            'line_client_agent_config_json' => '{}',
            'line_client_group_name' => null,
            'line_speed_limit_mbps' => 0,
            'cf_location_group_id' => null,
            'forward_combination_name' => 'local-only',
            'forward_combination_label' => 'local-only',
            'forward_combination_runtime_preference' => 'bond',
            'forward_combination_allow_private_self_leg' => 0,
            'forward_combination_self_leg_scope' => 'none',
            'forward_combination_self_leg_role' => 'none',
            'forward_combination_helper_group_cap' => 0,
            'forward_combination_helper_escalation_policy' => 'disabled',
            'forward_combination_self_leg_cf_transport' => 'cf_direct',
            'forward_combination_desired_latency_ms' => 0,
            'forward_combination_allow_public_self_leg_donation' => 0,
            'forward_combination_donation_qualify_window_seconds' => 60,
            'forward_combination_donation_revoke_window_seconds' => 30,
            'forward_combination_donation_min_open_ports' => 1,
            'node_group_key' => '',
            'endpoint_mode' => 'slave_group',
            'line_group_mode' => 'selected',
            'forward_chain_id' => null,
            'forward_chain_name' => null,
            'custom_endpoint_ids_json' => null,
        ];
        $tokenBuilder = static function (array $tokenForward, string $secret, string $protocol): string {
            $payload = json_encode([
                'target_host' => (string) ($tokenForward['target_host'] ?? ''),
                'target_port' => (int) ($tokenForward['target_port'] ?? 0),
                'target_protocol' => $protocol,
                'service_id' => (int) ($tokenForward['service_id'] ?? 0),
                'forward_id' => (int) ($tokenForward['id'] ?? $tokenForward['forward_id'] ?? 0),
                'issued_at' => 1_786_000_000,
                'expires_at' => 1_786_003_600,
            ], JSON_UNESCAPED_SLASHES);
            return hybridBase64Url((string) $payload) . '.' . hybridBase64Url(hash_hmac('sha256', (string) $payload, $secret, true));
        };
        $catalog = new LeigodLineCatalogService($config, $db);
        $service = new LeigodHybridRuntimeService($config, $db, $tokenBuilder, $artifact, $catalog);
        return compact('service', 'forward', 'client', 'session', 'endpoints', 'db', 'config') + ['env' => $envPath];
    }
}
