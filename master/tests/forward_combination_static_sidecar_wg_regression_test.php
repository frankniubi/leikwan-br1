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
            $normalized = $this->normalizeSql($sql);
            if (str_contains($normalized, 'FROM forward_combinations fc')) {
                foreach ($this->tables['forward_combinations'] ?? [] as $combo) {
                    if ((int) ($combo['id'] ?? 0) === (int) ($params['id'] ?? $params['forward_combination_id'] ?? 0)) {
                        return $this->withSidecar($combo);
                    }
                }
                return null;
            }
            if (str_contains($normalized, 'FROM sidecar_points')) {
                return $this->findById('sidecar_points', (int) ($params['id'] ?? 0));
            }
            if (str_contains($normalized, 'FROM cf_location_groups')) {
                return $this->findById('cf_location_groups', (int) ($params['id'] ?? 0));
            }
            if (str_contains($normalized, 'FROM nodes')) {
                return $this->findById('nodes', (int) ($params['id'] ?? $params['node_id'] ?? 0));
            }
            if (str_contains($normalized, 'SELECT client_token FROM services WHERE id = :id LIMIT 1')) {
                $row = $this->findById('services', (int) ($params['id'] ?? 0));
                return $row === null ? null : ['client_token' => $row['client_token'] ?? ''];
            }
            if (str_contains($normalized, 'SELECT agent_config_json FROM clients WHERE id = :id LIMIT 1')) {
                $row = $this->findById('clients', (int) ($params['id'] ?? 0));
                return $row === null ? null : ['agent_config_json' => $row['agent_config_json'] ?? null];
            }
            return null;
        }

        /** @param array<string, mixed> $params */
        public function fetchAll(string $sql, array $params = []): array
        {
            $normalized = $this->normalizeSql($sql);
            if (str_contains($normalized, 'FROM forward_combinations fc')) {
                $pointId = (int) ($params['point_id'] ?? 0);
                $rows = [];
                foreach ($this->tables['forward_combinations'] ?? [] as $combo) {
                    if ($pointId > 0 && (int) ($combo['sidecar_point_id'] ?? 0) !== $pointId) {
                        continue;
                    }
                    if ((int) ($combo['is_active'] ?? 0) !== 1) {
                        continue;
                    }
                    $rows[] = $this->withSidecar($combo);
                }
                return $rows;
            }
            if (str_contains($normalized, 'FROM forward_combination_slaves fcs')) {
                $comboId = (int) ($params['forward_combination_id'] ?? 0);
                $nodeFilter = (int) ($params['node_id'] ?? 0);
                $rows = [];
                foreach ($this->tables['forward_combination_slaves'] ?? [] as $pin) {
                    if ($comboId > 0 && (int) ($pin['forward_combination_id'] ?? 0) !== $comboId) {
                        continue;
                    }
                    if ($nodeFilter > 0 && (int) ($pin['node_id'] ?? 0) !== $nodeFilter) {
                        continue;
                    }
                    if ((int) ($pin['is_active'] ?? 0) !== 1) {
                        continue;
                    }
                    $node = $this->findById('nodes', (int) ($pin['node_id'] ?? 0));
                    if ($node === null) {
                        continue;
                    }
                    if (str_contains($normalized, 'INNER JOIN forward_combinations fc')) {
                        $combo = $this->findById('forward_combinations', (int) ($pin['forward_combination_id'] ?? 0));
                        if ($combo !== null) {
                            $rows[] = $this->withSidecar($combo);
                        }
                        continue;
                    }
                    $group = $this->findById('node_groups', (int) ($node['group_id'] ?? 0));
                    $rows[] = array_merge($node, [
                        'node_group_key' => (string) ($group['group_key'] ?? ''),
                        'pin_sort_order' => (int) ($pin['sort_order'] ?? 0),
                        'forward_combination_id' => (int) ($pin['forward_combination_id'] ?? 0),
                    ]);
                }
                return $rows;
            }
            if (str_contains($normalized, 'FROM forward_combination_helper_groups')) {
                return [];
            }
            if (str_contains($normalized, 'FROM cf_location_group_cidrs')) {
                return $this->tables['cf_location_group_cidrs'] ?? [];
            }
            if (str_contains($normalized, 'SELECT id FROM nodes ORDER BY id ASC')) {
                return array_map(
                    static fn(array $row): array => ['id' => (int) ($row['id'] ?? 0)],
                    $this->tables['nodes'] ?? []
                );
            }
            return [];
        }

        private function withSidecar(array $combo): array
        {
            $point = $this->findById('sidecar_points', (int) ($combo['sidecar_point_id'] ?? 0)) ?? [];
            return array_merge($combo, [
                'sidecar_point_name' => (string) ($point['name'] ?? ''),
                'sidecar_host' => (string) ($point['sidecar_host'] ?? ''),
                'sidecar_agent_config_json' => $point['agent_config_json'] ?? null,
                'sidecar_listen_port' => (int) ($point['listen_port'] ?? 0),
                'sidecar_bond_listen_port' => (int) ($point['bond_listen_port'] ?? 0),
                'sidecar_mptcp_listen_port' => (int) ($point['mptcp_listen_port'] ?? 0),
                'sidecar_cf_listen_port' => (int) ($point['cf_listen_port'] ?? 0),
            ]);
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

        private function normalizeSql(string $sql): string
        {
            return preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
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
    use Leikwan\Master\Support\ApiException;
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

    $envPath = tempnam(sys_get_temp_dir(), 'lw-static-uplink-env-');
    if ($envPath === false) {
        fail('failed to create temp env');
    }
    file_put_contents($envPath, "DEFAULT_TLS_CERT_REF=default-edge-cert\nBOND_TOKEN_SECRET=test-token-secret\nMPTCP_SLAVE_PRECONNECT=true\nSTATIC_SIDECAR_SHARED_WG_POINT_IDS=10\n");

    $db = new Database();
    $db->seed('services', [['id' => 6, 'status' => 'active', 'client_token' => 'service-token']]);
    $db->seed('clients', [['id' => 3, 'agent_config_json' => json_encode(['wireguard_public_key' => 'client-pub'], JSON_UNESCAPED_SLASHES)]]);
    $db->seed('sidecar_points', [[
        'id' => 4,
        'name' => 'szhk-ixp1',
        'sidecar_host' => '138.252.162.120',
        'listen_port' => 9000,
        'bond_listen_port' => 9000,
        'mptcp_listen_port' => 14969,
        'cf_listen_port' => 2053,
        'agent_config_json' => json_encode([
            'wireguard_public_key' => 'sidecar-pub',
            'wireguard_listen_port' => 14969,
            'wireguard_port_range_end' => 14999,
            'cf_sni_host' => 'hk.example.com',
        ], JSON_UNESCAPED_SLASHES),
    ]]);
    $db->seed('node_groups', [['id' => 10, 'group_key' => 'sha-tencent']]);
    $db->seed('nodes', [[
        'id' => 19,
        'group_id' => 10,
        'name' => 'sha-tencent-19',
        'status' => 'active',
        'public_ip' => '111.229.217.7',
        'agent_version' => '2026.05.28.1',
        'agent_config_json' => json_encode([
            'wireguard_public_key' => 'slave19-pub',
            'wireguard_listen_port' => 7000,
        ], JSON_UNESCAPED_SLASHES),
    ]]);
    $db->seed('forward_combinations', [[
        'id' => 2,
        'name' => 'SH-HK',
        'tenant_label' => 'SH-HK',
        'node_group_key' => 'sha-tencent',
        'backend_mode' => 'current_mode',
        'sidecar_point_id' => 4,
        'client_mode' => 'bond',
        'runtime_preference' => 'single',
        'helper_group_cap' => 2,
        'is_active' => 1,
        'sort_order' => 10,
    ]]);
    $db->seed('forward_combination_slaves', [[
        'id' => 1,
        'forward_combination_id' => 2,
        'node_id' => 19,
        'selection_kind' => 'admin_pin',
        'sort_order' => 10,
        'is_active' => 1,
    ]]);

    $service = new ProvisioningService(new Config($envPath), $db);

    $sidecarManagement = $service->slaveSidecarUplinkManagementForPoint(4);
    $sidecarUplinks = $sidecarManagement['uplinks'] ?? [];
    if (count($sidecarUplinks) !== 2) {
        fail('expected sidecar management to pre-create two static lanes, got ' . count($sidecarUplinks));
    }
    $interfaces = array_column($sidecarUplinks, 'interface_name');
    sort($interfaces);
    if ($interfaces !== ['lws4c2n19b', 'lws4c2n19p']) {
        fail('expected static lws interface names, got ' . json_encode($interfaces));
    }
    foreach ($sidecarUplinks as $uplink) {
        if (str_starts_with((string) $uplink['interface_name'], 'lw-sc-')) {
            fail('legacy lw-sc interface leaked into static management');
        }
        if (($uplink['forward_combination_id'] ?? null) !== 2 || ($uplink['node_id'] ?? null) !== 19) {
            fail('static uplink missing combination/node identity');
        }
        if (($uplink['outer_transport'] ?? null) !== 'udp') {
            fail('normal mode must use direct UDP WG to CNIX sidecar');
        }
        $listenPort = (int) ($uplink['listen_port'] ?? 0);
        if ($listenPort <= 14969 || $listenPort > 14999) {
            fail('static sidecar WG listen port out of configured range: ' . $listenPort);
        }
    }

    $slaveManagement = $service->slaveSidecarUplinkManagementForNode(19);
    $slaveUplinks = $slaveManagement['uplinks'] ?? [];
    if (count($slaveUplinks) !== 2) {
        fail('expected node management to receive two static lanes, got ' . count($slaveUplinks));
    }
    $primary = null;
    foreach ($slaveUplinks as $uplink) {
        if (($uplink['lane'] ?? '') === 'primary') {
            $primary = $uplink;
        }
    }
    if (!is_array($primary)) {
        fail('expected primary static lane in node management');
    }
    if (($primary['sidecar_endpoint_host'] ?? null) !== '138.252.162.120') {
        fail('normal static WG must dial sidecar CNIX IP, got ' . var_export($primary['sidecar_endpoint_host'] ?? null, true));
    }
    $primaryLocalListenPort = (int) ($primary['local_listen_port'] ?? 0);
    if ($primaryLocalListenPort < 43000 || $primaryLocalListenPort > 44999) {
        fail('slave static WG must publish a stable local listen port, got ' . var_export($primary['local_listen_port'] ?? null, true));
    }
    if (($primary['sidecar_peer_public_key'] ?? null) !== 'sidecar-pub') {
        fail('slave static lane missing sidecar public key');
    }
    if (!($primary['watchdog']['enabled'] ?? false)) {
        fail('static lane must opt into watchdog');
    }

    $db->seed('forward_combinations', [[
        'id' => 3,
        'name' => 'CF-HK',
        'tenant_label' => 'CF-HK',
        'node_group_key' => 'sha-tencent',
        'backend_mode' => 'cf_mode',
        'sidecar_point_id' => 4,
        'cf_location_group_id' => 5,
        'client_mode' => 'bond',
        'runtime_preference' => 'single',
        'helper_group_cap' => 2,
        'is_active' => 1,
        'sort_order' => 10,
    ]]);
    $db->seed('cf_location_groups', [[
        'id' => 5,
        'name' => 'cf-tokyo',
        'region_code' => 'nrt',
        'probe_mode' => 'tcp',
        'cache_ttl_seconds' => 90,
        'switch_improvement_ms' => 7,
        'max_candidates' => 4,
    ]]);
    $db->seed('cf_location_group_cidrs', [[
        'id' => 1,
        'group_id' => 5,
        'cidr' => '162.159.39.0/24',
        'priority' => 10,
        'is_active' => 1,
    ]]);
    $db->seed('forward_combination_slaves', [[
        'id' => 2,
        'forward_combination_id' => 3,
        'node_id' => 19,
        'selection_kind' => 'admin_pin',
        'sort_order' => 10,
        'is_active' => 1,
    ]]);
    $cfSlaveManagement = $service->slaveSidecarUplinkManagementForNode(19);
    $cfSlaveUplinks = $cfSlaveManagement['uplinks'] ?? [];
    if (count($cfSlaveUplinks) !== 2) {
        fail('expected CF node management to receive two static lanes, got ' . count($cfSlaveUplinks));
    }
    foreach ($cfSlaveUplinks as $uplink) {
        if (($uplink['outer_transport'] ?? null) !== 'wstunnel_udp') {
            fail('CF static WG must use Cloudflare/WSS optimized transport, got ' . var_export($uplink['outer_transport'] ?? null, true));
        }
        if (($uplink['cf_dial_host'] ?? null) !== '138.252.162.120') {
            fail('CF static WG must dial the sidecar CF host through optimized transport metadata');
        }
        if (($uplink['sidecar_endpoint_host'] ?? null) !== '127.0.0.1') {
            fail('CF static WG must point WireGuard to local wstunnel UDP');
        }
        if ((int) ($uplink['local_wstunnel_udp_port'] ?? 0) !== (int) ($uplink['local_listen_port'] ?? -1)) {
            fail('CF static WG local wstunnel UDP port must share the stable slave listen port');
        }
        if (!is_array($uplink['cf_mode'] ?? null) || (int) ($uplink['cf_mode']['location_group_id'] ?? 0) !== 5) {
            fail('CF static WG must carry CF location selection metadata');
        }
        if (($uplink['cf_mode']['cidrs'] ?? []) !== ['162.159.39.0/24']) {
            fail('CF static WG must publish location CIDRs for slave best-IP selection');
        }
        if (!($uplink['cf_mode']['allow_probe_target_failures'] ?? false)) {
            fail('CF static WG best-IP selection must tolerate endpoint ping instability and rank by CF path latency');
        }
        if ((int) ($uplink['cf_mode']['probe_samples'] ?? 0) !== 1 || (int) ($uplink['cf_mode']['min_success_samples'] ?? 0) !== 1) {
            fail('CF static WG best-IP selection should use lightweight one-sample probes for static slave uplinks');
        }
    }

    $db->seed('forward_combinations', [[
        'id' => 2,
        'name' => 'SH-HK',
        'tenant_label' => 'SH-HK',
        'node_group_key' => 'sha-tencent',
        'backend_mode' => 'current_mode',
        'sidecar_point_id' => 4,
        'client_mode' => 'bond',
        'runtime_preference' => 'single',
        'helper_group_cap' => 2,
        'is_active' => 1,
        'sort_order' => 10,
    ]]);
    $db->seed('forward_combination_slaves', [[
        'id' => 1,
        'forward_combination_id' => 2,
        'node_id' => 19,
        'selection_kind' => 'admin_pin',
        'sort_order' => 10,
        'is_active' => 1,
    ]]);

    $forward = [
        'id' => 59,
        'service_id' => 6,
        'client_token' => 'service-token',
        'line_client_id' => 3,
        'client_mode' => 'bond',
        'forward_combination_id' => 2,
        'bond_ingress_mode' => 'plain_tcp_mux',
        'backend_mode' => 'current_mode',
        'public_port' => 7000,
        'path_prefix' => 'f59',
        'sni_host' => '',
        'cdn_host' => '',
        'filter_profile' => 'off',
        'transport_mode' => 'tcp+udp',
        'bond_sidecar_point_id' => 4,
        'bond_remote_host' => '138.252.162.120',
        'bond_remote_port' => 9000,
        'target_host' => '138.252.162.120',
        'target_port' => 9000,
    ];
    $node = $db->fetchOne('SELECT * FROM nodes WHERE id = :id LIMIT 1', ['id' => 19]);
    $payload = call_private($service, 'publishTaskPayloadForForward', $forward, $node, 571);
    if (!is_array($payload['sidecar_uplink'] ?? null) || !($payload['sidecar_uplink']['enabled'] ?? false)) {
        fail('expected publish payload to select static sidecar uplink');
    }
    $relayTarget = (string) ($payload['sidecar_uplink']['relay_target'] ?? '');
    if ($relayTarget === '' || !str_ends_with($relayTarget, ':9000')) {
        fail('expected bond relay target over static WG sidecar address port 9000, got ' . var_export($relayTarget, true));
    }
    if (($payload['target'] ?? '') !== $relayTarget) {
        fail('expected slave backend target to use static WG relay_target, got target=' . var_export($payload['target'] ?? null, true) . ' relay=' . $relayTarget);
    }
    if (!is_array($payload['sidecar_uplink_selector'] ?? null) || !($payload['sidecar_uplink_selector']['enabled'] ?? false)) {
        fail('expected publish payload to enable static sidecar lane selector');
    }
    $selector = $payload['sidecar_uplink_selector'];
    if ((string) ($selector['strategy'] ?? '') !== 'wg_allowed_ip_ping') {
        fail('expected selector to use WG allowed-IP ping strategy, got ' . var_export($selector['strategy'] ?? null, true));
    }
    if ((int) ($selector['sidecar_service_port'] ?? 0) !== 9000) {
        fail('expected selector to target sidecar service port 9000, got ' . var_export($selector['sidecar_service_port'] ?? null, true));
    }
    if ((int) ($selector['switch_improvement_ms'] ?? 0) !== 3) {
        fail('expected selector default hysteresis to switch when active WG is 3ms worse, got ' . var_export($selector['switch_improvement_ms'] ?? null, true));
    }
    $candidates = $selector['candidates'] ?? null;
    if (!is_array($candidates) || count($candidates) !== 2) {
        fail('expected selector to receive primary+backup candidates, got ' . json_encode($candidates));
    }
    $candidateLanes = array_values(array_map(static fn(array $candidate): string => (string) ($candidate['lane'] ?? ''), $candidates));
    sort($candidateLanes);
    if ($candidateLanes !== ['backup', 'primary']) {
        fail('expected selector candidate lanes primary+backup, got ' . json_encode($candidateLanes));
    }
    foreach ($candidates as $candidate) {
        if ((string) ($candidate['ping_interface'] ?? '') !== (string) ($candidate['interface_name'] ?? '')) {
            fail('selector candidate must ping through its WG interface');
        }
        if ((string) ($candidate['ping_address'] ?? '') !== (string) ($candidate['peer_address'] ?? '')) {
            fail('selector candidate must ping the sidecar allowed IP');
        }
        if ((int) ($candidate['sidecar_port'] ?? 0) !== 9000) {
            fail('selector candidate must connect to sidecar service port 9000');
        }
    }

    $mptcpForward = $forward;
    $mptcpForward['client_mode'] = 'mptcp';
    $mptcpForward['runtime_preference'] = 'mptcp_only';
    $mptcpForward['bond_ingress_mode'] = 'wss_path_mux';
    $mptcpPayload = call_private($service, 'publishTaskPayloadForForward', $mptcpForward, $node, 572);
    $mptcpRelayTarget = (string) ($mptcpPayload['sidecar_uplink']['relay_target'] ?? '');
    if ($mptcpRelayTarget === '' || !str_ends_with($mptcpRelayTarget, ':14969')) {
        fail('expected mptcp relay target over static WG sidecar address port 14969, got ' . var_export($mptcpRelayTarget, true));
    }
    if (($mptcpPayload['target'] ?? '') !== $mptcpRelayTarget) {
        fail('expected mptcp slave backend target to use static WG relay_target, got target=' . var_export($mptcpPayload['target'] ?? null, true) . ' relay=' . $mptcpRelayTarget);
    }
    if ((int) ($mptcpPayload['sidecar_uplink_selector']['sidecar_service_port'] ?? 0) !== 14969) {
        fail('expected mptcp selector to target sidecar service port 14969, got ' . var_export($mptcpPayload['sidecar_uplink_selector']['sidecar_service_port'] ?? null, true));
    }
    foreach (($mptcpPayload['sidecar_uplink_selector']['candidates'] ?? []) as $candidate) {
        if ((int) ($candidate['sidecar_port'] ?? 0) !== 14969) {
            fail('mptcp selector candidate must connect to sidecar mptcp service port 14969');
        }
    }

    $db->seed('sidecar_points', [[
        'id' => 10,
        'name' => 'yt-hk-a',
        'sidecar_host' => 'hk-sidecar.example',
        'listen_port' => 18501,
        'bond_listen_port' => 18501,
        'mptcp_listen_port' => 18502,
        'cf_listen_port' => 18510,
        'agent_config_json' => json_encode([
            'wireguard_public_key' => 'sidecar10-pub',
            'wireguard_listen_port' => 18502,
            'wireguard_port_range_end' => 18509,
            'single_race_via_sidecar' => true,
        ], JSON_UNESCAPED_SLASHES),
    ]]);
    $db->seed('nodes', [[
        'id' => 2,
        'group_id' => 10,
        'name' => 'sha-tencent-2',
        'status' => 'active',
        'public_ip' => '111.231.3.220',
        'agent_version' => '2026.08.04.1',
        'agent_config_json' => json_encode(['wireguard_public_key' => 'slave2-pub'], JSON_UNESCAPED_SLASHES),
    ]]);
    $db->seed('forward_combinations', [[
        'id' => 13,
        'name' => 'sha-tencent-cnix-hkg',
        'tenant_label' => 'HK',
        'node_group_key' => 'sha-tencent',
        'backend_mode' => 'current_mode',
        'sidecar_point_id' => 10,
        'client_mode' => 'bond',
        'runtime_preference' => 'single',
        'helper_group_cap' => 4,
        'bond_ingress_mode' => 'plain_tcp_mux',
        'is_active' => 1,
        'sort_order' => 10,
    ]]);
    $db->seed('forward_combination_slaves', [[
        'id' => 1,
        'forward_combination_id' => 13,
        'node_id' => 2,
        'selection_kind' => 'admin_pin',
        'sort_order' => 10,
        'is_active' => 1,
    ]]);
    $sharedForward = $forward;
    $sharedForward['id'] = 80;
    $sharedForward['forward_combination_id'] = 13;
    $sharedForward['bond_sidecar_point_id'] = 10;
    $sharedForward['bond_remote_host'] = 'hk-sidecar.example';
    $sharedForward['bond_remote_port'] = 18501;
    $sharedForward['bond_sidecar_host'] = 'hk-sidecar.example';
    $sharedForward['bond_sidecar_port'] = 18501;
    $sharedForward['mptcp_sidecar_port'] = 18502;
    $sharedForward['bond_sidecar_point_agent_config_json'] = json_encode([
        'wireguard_public_key' => 'sidecar10-pub',
        'wireguard_listen_port' => 18502,
        'wireguard_port_range_end' => 18509,
        'single_race_via_sidecar' => true,
    ], JSON_UNESCAPED_SLASHES);
    $sharedForward['target_host'] = 'v4-hkt-b.mcbar.cc';
    $sharedForward['target_port'] = 5201;
    $sharedNode = $db->fetchOne('SELECT * FROM nodes WHERE id = :id LIMIT 1', ['id' => 2]);
    $sharedPayload = call_private($service, 'publishTaskPayloadForForward', $sharedForward, $sharedNode, 580);
    $sharedUplink = $sharedPayload['sidecar_uplink'] ?? null;
    if (!is_array($sharedUplink) || ($sharedUplink['lane'] ?? '') !== 'shared') {
        fail('shared point publish payload must select the canonical shared uplink');
    }
    $sharedRelayTarget = (string) ($sharedUplink['relay_target'] ?? '');
    if (!str_starts_with($sharedRelayTarget, '10.240.') || !str_ends_with($sharedRelayTarget, ':18501')) {
        fail('shared point publish payload must target the private WG relay, got ' . var_export($sharedRelayTarget, true));
    }
    if (($sharedPayload['target'] ?? '') !== $sharedRelayTarget) {
        fail('shared point publish target must use the private WG relay_target');
    }
    foreach (['single', 'race'] as $frontendMode) {
        $frontendForward = $sharedForward;
        $frontendForward['id'] = $frontendMode === 'single' ? 88 : 89;
        $frontendForward['client_mode'] = $frontendMode;
        $frontendPayload = call_private(
            $service,
            'publishTaskPayloadForForward',
            $frontendForward,
            $sharedNode,
            $frontendMode === 'single' ? 588 : 589
        );
        if (($frontendPayload['sidecar_required'] ?? false) !== true) {
            fail($frontendMode . ' sidecar frontend must require the configured sidecar');
        }
        if (($frontendPayload['aggregation_enabled'] ?? true) !== false) {
            fail($frontendMode . ' sidecar frontend must remain non-aggregating');
        }
        if (($frontendPayload['publish_bond_runtime'] ?? false) !== true) {
            fail($frontendMode . ' sidecar frontend must publish the bond carrier runtime');
        }
        if (($frontendPayload['active_family'] ?? '') !== 'sidecar_aggregation'
            || ($frontendPayload['target_contract'] ?? '') !== 'sidecar_aggregation') {
            fail($frontendMode . ' sidecar frontend must retain the compatible sidecar contract family');
        }
        if (($frontendPayload['ingress_protocol'] ?? '') !== 'plain_tcp_mux'
            || ($frontendPayload['backend_protocol'] ?? '') !== 'tcp_relay') {
            fail($frontendMode . ' sidecar frontend must use authenticated plain TCP ingress');
        }
        if (trim((string) ($frontendPayload['ingress_auth_token'] ?? '')) === '') {
            fail($frontendMode . ' sidecar frontend must publish an ingress auth token');
        }
        $frontendRelayTarget = (string) ($frontendPayload['sidecar_uplink']['relay_target'] ?? '');
        if (!str_starts_with($frontendRelayTarget, '10.240.') || !str_ends_with($frontendRelayTarget, ':18501')) {
            fail($frontendMode . ' sidecar frontend must target the shared private WG bond relay');
        }
        if (($frontendPayload['target'] ?? '') !== $frontendRelayTarget) {
            fail($frontendMode . ' sidecar frontend slave target must be the private WG relay');
        }

        $rawFrontendForward = $frontendForward;
        unset($rawFrontendForward['bond_sidecar_point_agent_config_json']);
        $rawFrontendPayload = call_private(
            $service,
            'publishTaskPayloadForForward',
            $rawFrontendForward,
            $sharedNode,
            $frontendMode === 'single' ? 688 : 689
        );
        if (($rawFrontendPayload['sidecar_required'] ?? false) !== true
            || ($rawFrontendPayload['publish_bond_runtime'] ?? false) !== true) {
            fail($frontendMode . ' raw persisted forward must resolve its gated point config before first publish');
        }

        $uncataloguedFrontendForward = $rawFrontendForward;
        $uncataloguedFrontendForward['forward_combination_id'] = null;
        $uncataloguedFrontendPayload = call_private(
            $service,
            'publishTaskPayloadForForward',
            $uncataloguedFrontendForward,
            $sharedNode,
            $frontendMode === 'single' ? 788 : 789
        );
        $uncataloguedUplink = $uncataloguedFrontendPayload['sidecar_uplink'] ?? null;
        if (!is_array($uncataloguedUplink)
            || ($uncataloguedUplink['wireguard_peer_mode'] ?? '') !== 'shared'
            || (int) ($uncataloguedUplink['forward_combination_id'] ?? -1) !== 0) {
            fail($frontendMode . ' uncatalogued frontend must reuse the point-scoped shared WG peer');
        }
        if (($uncataloguedFrontendPayload['target'] ?? '') !== ($uncataloguedUplink['relay_target'] ?? '')) {
            fail($frontendMode . ' uncatalogued frontend must publish through the shared WG relay');
        }

        $frontendBonding = call_private($service, 'buildBondClientConfig', $frontendForward, [[
            'id' => $frontendMode === 'single' ? 588 : 589,
            'host' => '111.231.3.220',
            'public_port' => 7000,
        ]]);
        if (($frontendBonding['frontend_mode'] ?? '') !== $frontendMode
            || ($frontendBonding['strategy_locked'] ?? false) !== true) {
            fail($frontendMode . ' client contract must lock the bond frontend strategy');
        }
    }

    $ungatedSingle = $sharedForward;
    $ungatedSingle['id'] = 87;
    $ungatedSingle['client_mode'] = 'single';
    $ungatedSingle['bond_sidecar_point_agent_config_json'] = json_encode([
        'wireguard_public_key' => 'sidecar10-pub',
        'wireguard_listen_port' => 18502,
        'wireguard_port_range_end' => 18509,
        'single_race_via_sidecar' => false,
    ], JSON_UNESCAPED_SLASHES);
    $ungatedPayload = call_private($service, 'publishTaskPayloadForForward', $ungatedSingle, $sharedNode, 587);
    if (($ungatedPayload['publish_bond_runtime'] ?? true) !== false
        || ($ungatedPayload['sidecar_required'] ?? true) !== false
        || ($ungatedPayload['target'] ?? '') !== 'v4-hkt-b.mcbar.cc:5201') {
        fail('single forward without the point gate must retain the direct legacy runtime');
    }
    $sharedPort443 = $sharedForward;
    $sharedPort443['public_port'] = 443;
    try {
        call_private($service, 'publishTaskPayloadForForward', $sharedPort443, $sharedNode, 581);
        fail('allowlisted shared point must reject public port 443');
    } catch (ApiException $exception) {
        if ($exception->getMessage() !== 'shared_sidecar_public_port_forbidden') {
            throw $exception;
        }
    }
    $validSharedManagement = $service->slaveSidecarUplinkManagementForPoint(10);
    if (($validSharedManagement['authoritative'] ?? false) !== true || count($validSharedManagement['uplinks'] ?? []) !== 1) {
        fail('valid shared point management must be authoritative with one peer');
    }
    $db->seed('sidecar_points', [[
        'id' => 10,
        'name' => 'yt-hk-a',
        'sidecar_host' => 'hk-sidecar.example',
        'listen_port' => 18501,
        'bond_listen_port' => 18501,
        'mptcp_listen_port' => 18502,
        'cf_listen_port' => 18510,
        'agent_config_json' => json_encode(['wireguard_public_key' => 'sidecar10-pub'], JSON_UNESCAPED_SLASHES),
    ]]);
    $invalidSharedManagement = $service->slaveSidecarUplinkManagementForPoint(10);
    if (($invalidSharedManagement['authoritative'] ?? true) !== false) {
        fail('invalid shared point management must be non-authoritative so agents preserve the active interface');
    }
    try {
        call_private($service, 'publishTaskPayloadForForward', $sharedForward, $sharedNode, 582);
        fail('invalid shared point must not fall back to the public sidecar target');
    } catch (ApiException $exception) {
        if ($exception->getMessage() !== 'shared_sidecar_uplink_unavailable') {
            throw $exception;
        }
    }

    echo "ok\n";
}
