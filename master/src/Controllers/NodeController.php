<?php
declare(strict_types=1);

namespace Leikwan\Master\Controllers;

use Leikwan\Master\Http\Request;
use Leikwan\Master\Http\Response;
use Leikwan\Master\Support\ApiException;
use Leikwan\Master\Support\Auth;
use Leikwan\Master\Support\ProvisioningService;

final class NodeController extends BaseController
{
    public function __construct(
        \Leikwan\Master\Config $config,
        \Leikwan\Master\Database\Database $db,
        private ProvisioningService $provisioning,
    ) {
        parent::__construct($config, $db);
    }

    public function register(Request $request, array $params): Response
    {
        $body = is_array($request->body()) ? $request->body() : [];
        $token = $request->header('x-enrollment-token') ?? (string) $request->input('enrollment_token', '');
        if ($token === '') {
            throw new ApiException('node_enrollment_failed', 401);
        }

        // Try global enrollment token first
        $expected = (string) $this->config->get('NODE_ENROLLMENT_TOKEN', '');
        $byosToken = null;
        if ($expected !== '' && hash_equals($expected, $token)) {
            // Platform enrollment — global token matched
        } else {
            // Try BYOS per-service enrollment token
            $byosToken = $this->db->fetchOne(
                'SELECT * FROM node_enrollment_tokens WHERE token = :token LIMIT 1',
                ['token' => $token]
            );
            if ($byosToken === null) {
                throw new ApiException('node_enrollment_failed', 401);
            }
            if ($byosToken['used_at'] !== null) {
                throw new ApiException('enrollment_token_already_used', 422);
            }
            if (strtotime((string) $byosToken['expires_at']) < time()) {
                throw new ApiException('enrollment_token_expired', 422);
            }
        }

        $groupKey = (string) ($request->input('group_key', 'default'));
        $credentialProfileId = (int) $request->input('credential_profile_id', 0);
        $credentialProfile = null;
        if ($credentialProfileId > 0) {
            $credentialProfile = $this->db->fetchOne('SELECT * FROM credential_profiles WHERE id = :id LIMIT 1', ['id' => $credentialProfileId]);
            if ($credentialProfile === null) {
                throw new ApiException('credential_profile_not_found', 404);
            }
        }
        $group = $this->db->fetchOne('SELECT * FROM node_groups WHERE group_key = :group_key LIMIT 1', [
            'group_key' => $groupKey,
        ]);
        if ($group === null) {
            $groupId = $this->db->insert('node_groups', [
                'group_key' => $groupKey,
                'name' => $groupKey,
                'created_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $group = $this->db->fetchOne('SELECT * FROM node_groups WHERE id = :id', ['id' => $groupId]);
        }

        $apiToken = bin2hex(random_bytes(24));
        $agentVersion = (string) $request->input('agent_version', '');
        $capabilities = $this->mergeCapabilityConfig([], $body);
        $supportsUdp = $this->boolInput($body, 'supports_udp', true);
        $supportsFiltering = $this->boolInput($body, 'supports_filtering', false);
        $nodeId = $this->db->insert('nodes', [
            'group_id' => $group['id'],
            'credential_profile_id' => $credentialProfile['id'] ?? null,
            'name' => (string) $request->input('name', 'node-' . substr($apiToken, 0, 8)),
            'region' => (string) $request->input('region', 'unknown'),
            'public_ip' => (string) $request->input('public_ip', ''),
            'private_ip' => (string) $request->input('private_ip', ''),
            'machine_id' => trim((string) $request->input('machine_id', '')) ?: null,
            'api_token' => $apiToken,
            'agent_version' => $agentVersion !== '' ? $agentVersion : null,
            'status' => 'active',
            'current_load' => 0,
            'supports_udp' => (int) $supportsUdp,
            'supports_filtering' => (int) $supportsFiltering,
            'agent_config_json' => $capabilities !== [] ? json_encode($capabilities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'owner_service_id' => $byosToken !== null ? (int) $byosToken['service_id'] : null,
            'enrollment_type' => $byosToken !== null ? 'user_enrolled' : 'platform',
            'last_heartbeat_at' => gmdate('Y-m-d H:i:s'),
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        if ($byosToken !== null) {
            $this->db->execute(
                'UPDATE node_enrollment_tokens SET used_at = :used_at, used_by_node_id = :node_id WHERE id = :id',
                ['used_at' => gmdate('Y-m-d H:i:s'), 'node_id' => $nodeId, 'id' => $byosToken['id']]
            );
        }

        return $this->json([
            'ok' => true,
            'node_id' => $nodeId,
            'api_token' => $apiToken,
            'master_url' => (string) ($this->config->get('AGENT_API_URL', $this->config->get('APP_URL', '')) ?? ''),
        ], 201);
    }

    public function heartbeat(Request $request, array $params): Response
    {
        $node = Auth::requireNode($request, $this->db);
        $body = is_array($request->body()) ? $request->body() : [];
        $currentLoad = (int) $request->input('current_load', 0);
        $status = (string) $request->input('status', 'active');
        $cpu = (float) $request->input('cpu_usage', 0);
        $memory = (float) $request->input('memory_usage', 0);
        $disk = (float) $request->input('disk_usage', 0);
        $agentVersion = (string) $request->input('agent_version', '');
        $name = trim((string) $request->input('name', (string) ($node['name'] ?? '')));
        $region = trim((string) $request->input('region', (string) ($node['region'] ?? 'unknown')));
        $publicIp = trim((string) $request->input('public_ip', (string) ($node['public_ip'] ?? '')));
        $privateIp = trim((string) $request->input('private_ip', (string) ($node['private_ip'] ?? '')));
        $machineId = trim((string) $request->input('machine_id', (string) ($node['machine_id'] ?? '')));
        $groupKey = trim((string) $request->input('group_key', ''));
        $groupId = $node['group_id'] ?? null;
        if ($groupKey !== '') {
            $group = $this->db->fetchOne('SELECT id FROM node_groups WHERE group_key = :group_key LIMIT 1', [
                'group_key' => $groupKey,
            ]);
            if ($group !== null) {
                $groupId = $group['id'];
            }
        }
        $existingAgentConfig = is_string($node['agent_config_json'] ?? null) ? json_decode((string) $node['agent_config_json'], true) : [];
        if (!is_array($existingAgentConfig)) {
            $existingAgentConfig = [];
        }
        $mergedCapabilities = $this->mergeCapabilityConfig($existingAgentConfig, $body);
        $supportsUdp = $this->boolInput($body, 'supports_udp', (bool) ($node['supports_udp'] ?? true));
        $supportsFiltering = $this->boolInput($body, 'supports_filtering', (bool) ($node['supports_filtering'] ?? false));
        // Regression sentinel: node_heartbeat_republish_gate
        // Node heartbeats are frequent; only republish endpoint payloads when
        // node identity/capability inputs change, plus a bounded force interval
        // that goes through republishActiveEndpointsForNode and therefore bypasses
        // reconcileForwardEndpoints' active-endpoint skip.
        $republishCapabilityState = $mergedCapabilities;
        unset($republishCapabilityState['last_endpoint_republish_fingerprint'], $republishCapabilityState['last_endpoint_republish_at']);
        $existingRepublishFingerprint = trim((string) ($existingAgentConfig['last_endpoint_republish_fingerprint'] ?? ''));
        $existingRepublishAt = (int) ($existingAgentConfig['last_endpoint_republish_at'] ?? 0);
        $republishFingerprint = sha1(json_encode([
            'group_id' => (int) ($groupId ?? 0),
            'status' => $status,
            'agent_version' => $agentVersion,
            'public_ip' => $publicIp,
            'private_ip' => $privateIp,
            'machine_id' => $machineId !== '' ? $machineId : (($node['machine_id'] ?? null) !== '' ? $node['machine_id'] : null),
            'supports_udp' => (int) $supportsUdp,
            'supports_filtering' => (int) $supportsFiltering,
            'capabilities' => $republishCapabilityState,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        $republishForceIntervalSeconds = max(60, (int) ($this->config->getInt('NODE_REPUBLISH_FORCE_INTERVAL_SECONDS', 600) ?? 600));
        $now = time();
        $shouldRepublishNodeEndpoints = ($republishFingerprint !== $existingRepublishFingerprint)
            || ($existingRepublishAt <= 0)
            || (($now - $existingRepublishAt) >= $republishForceIntervalSeconds);
        $mergedCapabilities['last_endpoint_republish_fingerprint'] = $republishFingerprint;
        $mergedCapabilities['last_endpoint_republish_at'] = $shouldRepublishNodeEndpoints ? $now : $existingRepublishAt;

        $this->db->execute(
            'UPDATE nodes
             SET group_id = :group_id,
                 name = :name,
                 region = :region,
                 public_ip = :public_ip,
                 private_ip = :private_ip,
                 machine_id = :machine_id,
                 status = :status,
                 agent_version = :agent_version,
                 current_load = :current_load,
                 supports_udp = :supports_udp,
                 supports_filtering = :supports_filtering,
                 agent_config_json = :agent_config_json,
                 last_heartbeat_at = :last_heartbeat_at,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'id' => $node['id'],
                'group_id' => $groupId,
                'name' => $name !== '' ? $name : (string) ($node['name'] ?? ''),
                'region' => $region !== '' ? $region : (string) ($node['region'] ?? 'unknown'),
                'public_ip' => $publicIp,
                'private_ip' => $privateIp,
                'machine_id' => $machineId !== '' ? $machineId : (($node['machine_id'] ?? null) !== '' ? $node['machine_id'] : null),
                'status' => $status,
                'agent_version' => $agentVersion !== '' ? $agentVersion : ($node['agent_version'] ?? null),
                'current_load' => $currentLoad,
                'supports_udp' => (int) $supportsUdp,
                'supports_filtering' => (int) $supportsFiltering,
                'agent_config_json' => $mergedCapabilities !== [] ? json_encode($mergedCapabilities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'last_heartbeat_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]
        );

        $this->db->insert('node_heartbeats', [
            'node_id' => $node['id'],
            'status' => $status,
            'cpu_usage' => $cpu,
            'memory_usage' => $memory,
            'disk_usage' => $disk,
            'payload_json' => json_encode($request->body(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        // Heartbeat resilience: the reconcile/republish provisioning calls below run
        // inline on every heartbeat. They issue UPDATEs against provision_tasks that can
        // deadlock under concurrent heartbeats (SQLSTATE 40001). Previously an uncaught
        // throw here bubbled out as an HTTP 500, the agent skipped its task pull/ack
        // cycle, and the fleet froze. Each call is now isolated: a failure is logged and
        // the heartbeat still returns the node's normal management response.
        $nodeId = (int) $node['id'];
        if ($groupKey !== '') {
            $this->guardProvisioning($nodeId, 'reconcileActiveForwardsForGroup', function () use ($groupKey): void {
                $this->provisioning->reconcileActiveForwardsForGroup($groupKey);
            });
        } elseif (is_array($group ?? null) && !empty($group['group_key'])) {
            $this->guardProvisioning($nodeId, 'reconcileActiveForwardsForGroup', function () use ($group): void {
                $this->provisioning->reconcileActiveForwardsForGroup((string) $group['group_key']);
            });
        }
        if (array_key_exists('donation_self_check', $body) && is_array($body['donation_self_check'])) {
            $this->guardProvisioning($nodeId, 'recordDonationSelfCheck', function () use ($nodeId, $body): void {
                $this->provisioning->recordDonationSelfCheck($nodeId, $body);
            });
        }
        $this->guardProvisioning($nodeId, 'applyForwardChainRemediationSignals', function () use ($nodeId, $mergedCapabilities): void {
            $this->provisioning->applyForwardChainRemediationSignals($nodeId, $mergedCapabilities);
        });
        if ($shouldRepublishNodeEndpoints && (!array_key_exists('forward_chain_remediation', $body) || !is_array($body['forward_chain_remediation']))) {
            $this->guardProvisioning($nodeId, 'republishActiveEndpointsForNode', function () use ($nodeId): void {
                $this->provisioning->republishActiveEndpointsForNode($nodeId);
            });
        }

        return $this->json(['ok' => true]);
    }

    /**
     * Run one inline provisioning step in isolation. Any \Throwable (e.g. a DB
     * deadlock raised by queueTask's collapse UPDATE) is logged and swallowed so
     * the heartbeat still returns the node's task/management response instead of
     * 500ing and stalling the agent's pull/ack cycle.
     */
    protected function guardProvisioning(int $nodeId, string $step, callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $throwable) {
            error_log(sprintf(
                '[node-heartbeat] node=%d %s failed: %s @ %s:%d',
                $nodeId,
                $step,
                $throwable->getMessage(),
                $throwable->getFile(),
                $throwable->getLine()
            ));
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function boolInput(array $body, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $body)) {
            return $default;
        }

        $value = $body[$key];
        if (is_bool($value)) {
            return $value;
        }
        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return false;
        }

        return !in_array($normalized, ['0', 'false', 'no', 'off'], true);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $agentConfig
     * @return array<string, mixed>
     */
    private function authoritativeAgentConfig(array $node, array $agentConfig, string $groupKey): array
    {
        $snapshot = array_fill_keys([
            'master_url',
            'name',
            'region',
            'group_key',
            'public_ip',
            'private_ip',
            'supports_udp',
            'supports_filtering',
            'supports_mptcp',
            'supports_quic',
            'supports_bond_hybrid',
            'supports_sidecar_quic',
            'bond_protocols',
            'hybrid_modes',
            'wireguard_public_key',
            'wireguard_listen_port',
            'wireguard_fallback_tcp_port',
            'tls_mode',
            'tls_domain',
            'tls_email',
            'tls_wildcard',
            'tls_cert_path',
            'tls_key_path',
            'landing_page_path',
            'tls_cert_pem',
            'tls_key_pem',
            'cloudflare_email',
            'cloudflare_global_key',
            'backend_host',
            'backend_port_range_start',
            'backend_port_range_end',
            'mux_listen_hosts',
            'relay_quic_keepalive_seconds',
            'relay_quic_max_idle_seconds',
            'relay_quic_prefetch_connections',
            'relay_quic_initial_mtu',
            'relay_quic_min_mtu',
            'relay_quic_mtu_upper_bound',
            'relay_quic_mtu_discovery_interval_seconds',
            'relay_quic_mtu_black_hole_cooldown_seconds',
            'relay_quic_mtu_minimum_change',
            'poll_interval_seconds',
            'heartbeat_interval_seconds',
            'usage_interval_seconds',
            'log_level',
            'udp_uot_sidepath',
            'forward_chain_remediation',
        ], null);

        foreach (array_keys($snapshot) as $key) {
            if (array_key_exists($key, $agentConfig)) {
                $snapshot[$key] = $agentConfig[$key];
            }
        }

        $snapshot['master_url'] = $this->agentBaseUrl();
        $snapshot['name'] = (string) ($node['name'] ?? '');
        $snapshot['region'] = (string) ($node['region'] ?? 'unknown');
        $snapshot['group_key'] = $groupKey;
        $snapshot['public_ip'] = (string) ($node['public_ip'] ?? '');
        $snapshot['private_ip'] = (string) ($node['private_ip'] ?? '');
        $snapshot['cloudflare_email'] = array_key_exists('cloudflare_email', $agentConfig) ? $agentConfig['cloudflare_email'] : null;
        $snapshot['cloudflare_global_key'] = array_key_exists('cloudflare_global_key', $agentConfig) ? $agentConfig['cloudflare_global_key'] : null;

        return $snapshot;
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function mergeCapabilityConfig(array $existing, array $body): array
    {
        $config = $existing;
        $boolKeys = [
            'supports_udp',
            'supports_filtering',
            'supports_mptcp',
            'supports_quic',
            'supports_bond_hybrid',
            'supports_sidecar_quic',
        ];
        foreach ($boolKeys as $key) {
            if (array_key_exists($key, $body)) {
                $config[$key] = (bool) $body[$key];
            }
        }

        foreach (['bond_protocols', 'hybrid_modes'] as $key) {
            if (!array_key_exists($key, $body) || !is_array($body[$key])) {
                continue;
            }
            $config[$key] = array_values(array_map(
                static fn(mixed $value): string => trim((string) $value),
                array_filter($body[$key], static fn(mixed $value): bool => trim((string) $value) !== '')
            ));
        }

        foreach (['wireguard_public_key'] as $key) {
            if (array_key_exists($key, $body)) {
                $value = trim((string) ($body[$key] ?? ''));
                if ($value !== '') {
                    $config[$key] = $value;
                }
            }
        }
        if (array_key_exists('forward_chain_remediation', $body) && is_array($body['forward_chain_remediation'])) {
            $config['forward_chain_remediation'] = $body['forward_chain_remediation'];
        }
        foreach (['wireguard_listen_port', 'wireguard_fallback_tcp_port'] as $key) {
            if (array_key_exists($key, $body) && $body[$key] !== null && $body[$key] !== '') {
                $config[$key] = max(0, (int) $body[$key]);
            }
        }

        return $config;
    }

    public function reportUsage(Request $request, array $params): Response
    {
        $node = Auth::requireNode($request, $this->db);
        $entries = is_array($request->input('entries', [])) ? $request->input('entries', []) : [];
        return $this->json([
            'ok' => true,
            'result' => $this->provisioning->applyUsageReport($node, $entries),
        ]);
    }

    public function pullTasks(Request $request, array $params): Response
    {
        $node = Auth::requireNode($request, $this->db);
        $this->reclaimExpiredProvisionTaskLeases((int) $node['id']);
        $limit = min(100, max(1, (int) $request->input('limit', $this->config->getInt('MAX_TASK_BATCH', 20))));
        $tasks = $this->db->fetchAll(
            'SELECT *
             FROM provision_tasks
             WHERE node_id = :node_id
               AND status = :status
             ORDER BY id ASC
             LIMIT ' . $limit,
            [
                'node_id' => $node['id'],
                'status' => 'pending',
            ]
        );

        $decodedTasks = array_map(static function (array $task): array {
            $task['payload'] = json_decode((string) $task['payload_json'], true) ?: [];
            unset($task['payload_json']);
            return $task;
        }, $tasks);

        $groupKey = '';
        if (!empty($node['group_id'])) {
            $group = $this->db->fetchOne('SELECT group_key FROM node_groups WHERE id = :id LIMIT 1', [
                'id' => $node['group_id'],
            ]);
            $groupKey = (string) ($group['group_key'] ?? '');
        }
        $agentConfig = is_string($node['agent_config_json'] ?? null) ? json_decode((string) $node['agent_config_json'], true) : [];
        if (!is_array($agentConfig)) {
            $agentConfig = [];
        }
        if (!empty($node['credential_profile_id'])) {
            $profile = $this->db->fetchOne('SELECT * FROM credential_profiles WHERE id = :id LIMIT 1', [
                'id' => $node['credential_profile_id'],
            ]);
            if (is_array($profile)) {
                $profileConfig = is_string($profile['config_json'] ?? null) ? json_decode((string) $profile['config_json'], true) : [];
                if (is_array($profileConfig) && (string) ($profile['provider'] ?? '') === 'cloudflare_global_key') {
                    $agentConfig = array_merge($agentConfig, [
                        'cloudflare_email' => (string) ($profileConfig['cloudflare_email'] ?? ''),
                        'cloudflare_global_key' => (string) ($profileConfig['cloudflare_global_key'] ?? ''),
                    ]);
                }
            }
        }
        $agentConfig = $this->applyPanelTlsSync($agentConfig);
        $agentConfig = $this->applyManagedSslCertificate($agentConfig, $node);
        $slaveAgent = $this->artifactMetadata('slave_agent');
        $slaveInstaller = $this->artifactMetadata('slave_installer');
        $updateRequestToken = trim((string) ($node['agent_update_request_token'] ?? ''));
        if ($updateRequestToken === '') {
            $updateRequestToken = $this->autoUpdateTokenForVersion((string) ($node['agent_version'] ?? ''), 'slave_agent', 'auto-slave-agent');
        }
        $agentConfig = $this->authoritativeAgentConfig($node, $agentConfig, $groupKey);
        $agentConfig['udp_uot_sidepath'] = $this->provisioning->udpUotSidepathConfigForNode(
            (int) ($node['id'] ?? 0),
            is_array($agentConfig['udp_uot_sidepath'] ?? null) ? $agentConfig['udp_uot_sidepath'] : []
        );

        $uplinks = $this->provisioning->slaveSidecarUplinkManagementForNode((int) $node['id'])['uplinks'] ?? [];
        $management = [
            'agent_config_json' => $agentConfig,
            'agent_update_request_token' => $updateRequestToken,
            'agent_update_requested_at' => (string) ($node['agent_update_requested_at'] ?? ''),
            'desired_agent_version' => (string) ($slaveAgent['version'] ?? ''),
            'desired_agent_sha256' => (string) ($slaveAgent['sha256'] ?? ''),
            'desired_installer_version' => (string) ($slaveInstaller['version'] ?? ''),
            'desired_installer_sha256' => (string) ($slaveInstaller['sha256'] ?? ''),
            'sidecar_static_uplinks' => $uplinks,
        ];
        // Deliver the shared LeiGod account session only to nodes that actually run a leigod line.
        $usesLeigod = false;
        foreach ((array) $uplinks as $uplink) {
            if (!is_array($uplink)) {
                continue;
            }
            if ((string) ($uplink['outer_transport'] ?? '') === 'leigod' || (int) ($uplink['leigod_line_id'] ?? 0) > 0) {
                $usesLeigod = true;
                break;
            }
        }
        if ($usesLeigod) {
            $leigodSession = $this->provisioning->leigodSharedSessionJson();
            if ($leigodSession !== '') {
                $management['leigod_session_json'] = $leigodSession;
            }
        }

        return $this->json([
            'ok' => true,
            'tasks' => $decodedTasks,
            'management' => $management,
        ]);
    }

    private function reclaimExpiredProvisionTaskLeases(int $nodeId): void
    {
        if ($nodeId <= 0) {
            return;
        }

        $now = gmdate('Y-m-d H:i:s');
        $this->db->execute(
            'UPDATE provision_tasks
             SET status = :pending_status,
                 lease_expires_at = NULL,
                 attempt_count = GREATEST(attempt_count - 1, 0),
                 updated_at = :updated_at,
                 error_message = :error_message
             WHERE node_id = :node_id
               AND status = :running_status
               AND lease_expires_at IS NOT NULL
               AND lease_expires_at < :now',
            [
                'node_id' => $nodeId,
                'pending_status' => 'pending',
                'running_status' => 'running',
                'now' => $now,
                'updated_at' => $now,
                'error_message' => 'lease expired; reclaimed for retry',
            ]
        );
    }

    public function ackTask(Request $request, array $params): Response
    {
        $node = Auth::requireNode($request, $this->db);
        $taskId = $this->idParam($params);
        $updated = $this->db->execute(
            'UPDATE provision_tasks
             SET status = :status,
                 lease_expires_at = :lease_expires_at,
                 attempt_count = attempt_count + 1,
                 updated_at = :updated_at
             WHERE id = :id AND node_id = :node_id',
            [
                'id' => $taskId,
                'node_id' => $node['id'],
                'status' => 'running',
                'lease_expires_at' => gmdate('Y-m-d H:i:s', time() + 300),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]
        );
        if ($updated === 0) {
            throw new ApiException('task_not_found', 404);
        }

        return $this->json(['ok' => true]);
    }

    public function taskResult(Request $request, array $params): Response
    {
        $node = Auth::requireNode($request, $this->db);
        $taskId = $this->idParam($params);
        $task = $this->db->fetchOne('SELECT * FROM provision_tasks WHERE id = :id AND node_id = :node_id LIMIT 1', [
            'id' => $taskId,
            'node_id' => $node['id'],
        ]);
        if ($task === null) {
            throw new ApiException('task_not_found', 404);
        }

        $success = (bool) $request->input('success', false);
        $resultPayload = is_array($request->input('result', [])) ? $request->input('result', []) : [];
        $errorMessage = (string) $request->input('error_message', '');

        $this->db->execute(
            'UPDATE provision_tasks
             SET status = :status,
                 result_json = :result_json,
                 error_message = :error_message,
                 lease_expires_at = NULL,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'id' => $taskId,
                'status' => $success ? 'completed' : 'failed',
                'result_json' => json_encode($resultPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'error_message' => $errorMessage !== '' ? $errorMessage : null,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]
        );

        $endpointStatus = match ((string) $task['action']) {
            'publish', 'resume' => $success ? 'active' : 'error',
            'suspend' => $success ? 'suspended' : 'error',
            'destroy' => $success ? 'deleted' : 'error',
            default => $success ? 'active' : 'error',
        };

        $this->db->execute(
            'UPDATE service_endpoints
             SET health_status = :status,
                 last_error = :last_error,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'id' => $task['endpoint_id'],
                'status' => $endpointStatus,
                'last_error' => $success ? null : $errorMessage,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]
        );

        return $this->json(['ok' => true]);
    }

    public function listNodes(Request $request, array $params): Response
    {
        Auth::requireAdmin($request, $this->config);
        $nodes = $this->db->fetchAll(
            'SELECT n.id, n.name, n.public_ip, n.private_ip, n.machine_id, n.region, n.status, n.agent_version, n.current_load, n.last_heartbeat_at, g.group_key
             FROM nodes n
             LEFT JOIN node_groups g ON g.id = n.group_id
             ORDER BY n.last_heartbeat_at DESC
             LIMIT 200'
        );

        return $this->json([
            'ok' => true,
            'nodes' => $nodes,
        ]);
    }

    public function selfDuplicates(Request $request, array $params): Response
    {
        $node = Auth::requireNode($request, $this->db);
        $currentNode = $this->loadNodeWithGroup((int) $node['id']) ?? $node;
        $duplicates = $this->findDuplicateNodes($currentNode);

        return $this->json([
            'ok' => true,
            'current_node' => $this->nodeSummary($currentNode),
            'duplicates' => $duplicates,
            'recommended_delete_node_ids' => array_values(array_map(
                static fn(array $candidate): int => (int) $candidate['id'],
                $duplicates
            )),
        ]);
    }

    public function deduplicate(Request $request, array $params): Response
    {
        $node = Auth::requireNode($request, $this->db);
        $currentNode = $this->loadNodeWithGroup((int) $node['id']) ?? $node;
        $duplicates = $this->findDuplicateNodes($currentNode);
        $requestedIds = array_values(array_filter(array_map(
            static fn(mixed $value): int => (int) $value,
            is_array($request->input('node_ids', [])) ? $request->input('node_ids', []) : []
        ), static fn(int $value): bool => $value > 0));

        if ($requestedIds !== []) {
            $requestedLookup = array_fill_keys($requestedIds, true);
            $duplicates = array_values(array_filter(
                $duplicates,
                static fn(array $candidate): bool => isset($requestedLookup[(int) $candidate['id']])
            ));
        }

        if ($duplicates === []) {
            return $this->json([
                'ok' => true,
                'kept_node_id' => (int) $currentNode['id'],
                'deleted_node_ids' => [],
                'reassigned' => [
                    'service_endpoints' => 0,
                    'provision_tasks' => 0,
                    'node_heartbeats' => 0,
                    'traffic_usage_reassigned' => 0,
                    'traffic_usage_merged' => 0,
                ],
                'duplicates' => [],
            ]);
        }

        $result = $this->db->transaction(function (\Leikwan\Master\Database\Database $db) use ($currentNode, $duplicates): array {
            $deletedNodeIds = [];
            $reassigned = [
                'service_endpoints' => 0,
                'provision_tasks' => 0,
                'node_heartbeats' => 0,
                'traffic_usage_reassigned' => 0,
                'traffic_usage_merged' => 0,
            ];

            foreach ($duplicates as $duplicate) {
                $sourceId = (int) $duplicate['id'];
                $targetId = (int) $currentNode['id'];
                if ($sourceId <= 0 || $sourceId === $targetId) {
                    continue;
                }

                $reassigned['service_endpoints'] += $db->execute(
                    'UPDATE service_endpoints SET node_id = :target_id WHERE node_id = :source_id',
                    ['target_id' => $targetId, 'source_id' => $sourceId]
                );
                $reassigned['provision_tasks'] += $db->execute(
                    'UPDATE provision_tasks SET node_id = :target_id WHERE node_id = :source_id',
                    ['target_id' => $targetId, 'source_id' => $sourceId]
                );
                $reassigned['node_heartbeats'] += $db->execute(
                    'UPDATE node_heartbeats SET node_id = :target_id WHERE node_id = :source_id',
                    ['target_id' => $targetId, 'source_id' => $sourceId]
                );

                $usageRows = $db->fetchAll(
                    'SELECT id, service_id, endpoint_id, period_start, bytes_in, bytes_out
                     FROM traffic_usage_hourly
                     WHERE node_id = :source_id
                     ORDER BY id ASC',
                    ['source_id' => $sourceId]
                );
                foreach ($usageRows as $usageRow) {
                    $existing = $db->fetchOne(
                        'SELECT id, bytes_in, bytes_out
                         FROM traffic_usage_hourly
                         WHERE node_id = :target_id
                           AND service_id = :service_id
                           AND endpoint_id = :endpoint_id
                           AND period_start = :period_start
                         LIMIT 1',
                        [
                            'target_id' => $targetId,
                            'service_id' => $usageRow['service_id'],
                            'endpoint_id' => $usageRow['endpoint_id'],
                            'period_start' => $usageRow['period_start'],
                        ]
                    );
                    if ($existing !== null) {
                        $db->execute(
                            'UPDATE traffic_usage_hourly
                             SET bytes_in = :bytes_in,
                                 bytes_out = :bytes_out,
                                 updated_at = :updated_at
                             WHERE id = :id',
                            [
                                'id' => $existing['id'],
                                'bytes_in' => (int) $existing['bytes_in'] + (int) $usageRow['bytes_in'],
                                'bytes_out' => (int) $existing['bytes_out'] + (int) $usageRow['bytes_out'],
                                'updated_at' => gmdate('Y-m-d H:i:s'),
                            ]
                        );
                        $db->execute('DELETE FROM traffic_usage_hourly WHERE id = :id', ['id' => $usageRow['id']]);
                        $reassigned['traffic_usage_merged']++;
                        continue;
                    }

                    $db->execute(
                        'UPDATE traffic_usage_hourly
                         SET node_id = :target_id,
                             updated_at = :updated_at
                         WHERE id = :id',
                        [
                            'id' => $usageRow['id'],
                            'target_id' => $targetId,
                            'updated_at' => gmdate('Y-m-d H:i:s'),
                        ]
                    );
                    $reassigned['traffic_usage_reassigned']++;
                }

                $db->execute('DELETE FROM nodes WHERE id = :id', ['id' => $sourceId]);
                $deletedNodeIds[] = $sourceId;
            }

            return [
                'deleted_node_ids' => $deletedNodeIds,
                'reassigned' => $reassigned,
            ];
        });

        return $this->json([
            'ok' => true,
            'kept_node_id' => (int) $currentNode['id'],
            'deleted_node_ids' => $result['deleted_node_ids'],
            'reassigned' => $result['reassigned'],
            'duplicates' => $duplicates,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadNodeWithGroup(int $nodeId): ?array
    {
        return $this->db->fetchOne(
            'SELECT n.*, g.group_key, g.name AS group_name
             FROM nodes n
             LEFT JOIN node_groups g ON g.id = n.group_id
             WHERE n.id = :id
             LIMIT 1',
            ['id' => $nodeId]
        );
    }

    /**
     * @param array<string, mixed> $currentNode
     * @return array<int, array<string, mixed>>
     */
    private function findDuplicateNodes(array $currentNode): array
    {
        $candidates = $this->db->fetchAll(
            'SELECT n.*, g.group_key, g.name AS group_name
             FROM nodes n
             LEFT JOIN node_groups g ON g.id = n.group_id
             WHERE n.id <> :id
             ORDER BY n.updated_at DESC, n.id DESC',
            ['id' => $currentNode['id']]
        );

        $duplicates = [];
        foreach ($candidates as $candidate) {
            $signals = $this->duplicateSignals($currentNode, $candidate);
            if ($signals === []) {
                continue;
            }

            $summary = $this->nodeSummary($candidate);
            $summary['match_signals'] = $signals;
            $summary['reference_counts'] = $this->nodeReferenceCounts((int) $candidate['id']);
            $summary['recommended_action'] = 'delete';
            $duplicates[] = $summary;
        }

        return $duplicates;
    }

    /**
     * @param array<string, mixed> $currentNode
     * @param array<string, mixed> $candidate
     * @return array<int, string>
     */
    private function duplicateSignals(array $currentNode, array $candidate): array
    {
        $currentMachineId = trim((string) ($currentNode['machine_id'] ?? ''));
        $candidateMachineId = trim((string) ($candidate['machine_id'] ?? ''));
        $currentPublicIp = trim((string) ($currentNode['public_ip'] ?? ''));
        $candidatePublicIp = trim((string) ($candidate['public_ip'] ?? ''));
        $currentPrivateIp = trim((string) ($currentNode['private_ip'] ?? ''));
        $candidatePrivateIp = trim((string) ($candidate['private_ip'] ?? ''));
        $currentName = strtolower(trim((string) ($currentNode['name'] ?? '')));
        $candidateName = strtolower(trim((string) ($candidate['name'] ?? '')));
        $sameGroup = (int) ($currentNode['group_id'] ?? 0) > 0
            && (int) ($candidate['group_id'] ?? 0) > 0
            && (int) $currentNode['group_id'] === (int) $candidate['group_id'];

        $signals = [];
        if ($currentMachineId !== '' && $candidateMachineId !== '' && hash_equals($currentMachineId, $candidateMachineId)) {
            $signals[] = 'machine_id';
        }

        $samePublicIp = $currentPublicIp !== '' && $candidatePublicIp !== '' && hash_equals($currentPublicIp, $candidatePublicIp);
        $samePrivateIp = $currentPrivateIp !== '' && $candidatePrivateIp !== '' && hash_equals($currentPrivateIp, $candidatePrivateIp);
        if ($samePublicIp && $samePrivateIp) {
            $signals[] = 'public_ip+private_ip';
        }

        $sameName = $currentName !== '' && $candidateName !== '' && $currentName === $candidateName;
        if ($sameName && ($samePublicIp || $samePrivateIp) && ($sameGroup || ($samePublicIp && $samePrivateIp))) {
            $signals[] = $samePublicIp ? 'name+public_ip' : 'name+private_ip';
        }

        return array_values(array_unique($signals));
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function nodeSummary(array $node): array
    {
        return [
            'id' => (int) ($node['id'] ?? 0),
            'name' => (string) ($node['name'] ?? ''),
            'group_key' => (string) ($node['group_key'] ?? ''),
            'group_name' => (string) ($node['group_name'] ?? ''),
            'region' => (string) ($node['region'] ?? ''),
            'public_ip' => (string) ($node['public_ip'] ?? ''),
            'private_ip' => (string) ($node['private_ip'] ?? ''),
            'machine_id' => (string) ($node['machine_id'] ?? ''),
            'status' => (string) ($node['status'] ?? ''),
            'agent_version' => (string) ($node['agent_version'] ?? ''),
            'last_heartbeat_at' => (string) ($node['last_heartbeat_at'] ?? ''),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function nodeReferenceCounts(int $nodeId): array
    {
        $serviceEndpoints = $this->db->fetchOne('SELECT COUNT(*) AS aggregate FROM service_endpoints WHERE node_id = :node_id', ['node_id' => $nodeId]);
        $provisionTasks = $this->db->fetchOne('SELECT COUNT(*) AS aggregate FROM provision_tasks WHERE node_id = :node_id', ['node_id' => $nodeId]);
        $heartbeats = $this->db->fetchOne('SELECT COUNT(*) AS aggregate FROM node_heartbeats WHERE node_id = :node_id', ['node_id' => $nodeId]);
        $trafficUsage = $this->db->fetchOne('SELECT COUNT(*) AS aggregate FROM traffic_usage_hourly WHERE node_id = :node_id', ['node_id' => $nodeId]);

        return [
            'service_endpoints' => (int) ($serviceEndpoints['aggregate'] ?? 0),
            'provision_tasks' => (int) ($provisionTasks['aggregate'] ?? 0),
            'node_heartbeats' => (int) ($heartbeats['aggregate'] ?? 0),
            'traffic_usage_hourly' => (int) ($trafficUsage['aggregate'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $agentConfig
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function applyManagedSslCertificate(array $agentConfig, array $node): array
    {
        $sslCertificateId = (int) ($node['ssl_certificate_id'] ?? 0);
        if ($sslCertificateId <= 0) {
            return $agentConfig;
        }

        $certificate = $this->db->fetchOne('SELECT * FROM ssl_certificates WHERE id = :id LIMIT 1', ['id' => $sslCertificateId]);
        if (!is_array($certificate)) {
            return $agentConfig;
        }

        [$certPem, $keyPem] = $this->resolveManagedSslCertificateMaterial($certificate);
        if ($certPem === null || $keyPem === null) {
            return $agentConfig;
        }

        $agentConfig['tls_mode'] = 'existing';
        if (trim((string) ($agentConfig['tls_cert_path'] ?? '')) === '') {
            $agentConfig['tls_cert_path'] = '/etc/wstunnel/ssl/server.crt';
        }
        if (trim((string) ($agentConfig['tls_key_path'] ?? '')) === '') {
            $agentConfig['tls_key_path'] = '/etc/wstunnel/ssl/server.key';
        }
        $agentConfig['tls_cert_pem'] = $certPem;
        $agentConfig['tls_key_pem'] = $keyPem;

        return $agentConfig;
    }

    /**
     * @param array<string, mixed> $certificate
     * @return array{0:?string,1:?string}
     */
    private function resolveManagedSslCertificateMaterial(array $certificate): array
    {
        $sourceType = trim((string) ($certificate['source_type'] ?? 'uploaded'));
        if ($sourceType === 'uploaded') {
            $certPem = trim((string) ($certificate['cert_pem'] ?? ''));
            $keyPem = trim((string) ($certificate['key_pem'] ?? ''));
            return $certPem !== '' && $keyPem !== '' ? [$certPem, $keyPem] : [null, null];
        }

        $sourceConfig = is_string($certificate['source_config_json'] ?? null) ? json_decode((string) $certificate['source_config_json'], true) : [];
        if (!is_array($sourceConfig)) {
            $sourceConfig = [];
        }

        $certPath = trim((string) ($sourceConfig['cert_path'] ?? ''));
        $keyPath = trim((string) ($sourceConfig['key_path'] ?? ''));
        if ($sourceType === 'panel_letsencrypt' && ($certPath === '' || $keyPath === '')) {
            [$defaultCertPath, $defaultKeyPath] = $this->defaultPanelTlsPaths((string) ($sourceConfig['domain'] ?? ''));
            if ($certPath === '') {
                $certPath = $defaultCertPath;
            }
            if ($keyPath === '') {
                $keyPath = $defaultKeyPath;
            }
        }
        if ($certPath === '' || $keyPath === '') {
            return [null, null];
        }

        return [
            $this->readFileIfAvailable($certPath),
            $this->readFileIfAvailable($keyPath),
        ];
    }

    /**
     * @param array<string, mixed> $agentConfig
     * @return array<string, mixed>
     */
    private function applyPanelTlsSync(array $agentConfig): array
    {
        $panelTlsSyncDomain = trim((string) ($agentConfig['panel_tls_sync_domain'] ?? ''));
        $panelTlsCertSourcePath = trim((string) ($agentConfig['panel_tls_cert_source_path'] ?? ''));
        $panelTlsKeySourcePath = trim((string) ($agentConfig['panel_tls_key_source_path'] ?? ''));

        if ($panelTlsSyncDomain !== '' && ($panelTlsCertSourcePath === '' || $panelTlsKeySourcePath === '')) {
            [$defaultPanelTlsCertPath, $defaultPanelTlsKeyPath] = $this->defaultPanelTlsPaths($panelTlsSyncDomain);
            if ($panelTlsCertSourcePath === '') {
                $panelTlsCertSourcePath = $defaultPanelTlsCertPath;
            }
            if ($panelTlsKeySourcePath === '') {
                $panelTlsKeySourcePath = $defaultPanelTlsKeyPath;
            }
        }

        if ($panelTlsCertSourcePath === '' || $panelTlsKeySourcePath === '') {
            return $agentConfig;
        }

        $panelCertPem = $this->readFileIfAvailable($panelTlsCertSourcePath);
        $panelKeyPem = $this->readFileIfAvailable($panelTlsKeySourcePath);
        if ($panelCertPem === null || $panelKeyPem === null) {
            return $agentConfig;
        }

        $agentConfig['tls_mode'] = 'existing';
        if (trim((string) ($agentConfig['tls_cert_path'] ?? '')) === '') {
            $agentConfig['tls_cert_path'] = '/etc/wstunnel/ssl/server.crt';
        }
        if (trim((string) ($agentConfig['tls_key_path'] ?? '')) === '') {
            $agentConfig['tls_key_path'] = '/etc/wstunnel/ssl/server.key';
        }
        $agentConfig['tls_cert_pem'] = $panelCertPem;
        $agentConfig['tls_key_pem'] = $panelKeyPem;

        return $agentConfig;
    }

    private function readFileIfAvailable(string $path): ?string
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if (!is_string($content) || trim($content) === '') {
            return null;
        }

        return $content;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function defaultPanelTlsPaths(string $domain): array
    {
        $normalized = trim($domain);
        if (str_starts_with($normalized, '*.')) {
            $normalized = substr($normalized, 2);
        }

        return [
            '/etc/letsencrypt/live/' . $normalized . '/fullchain.pem',
            '/etc/letsencrypt/live/' . $normalized . '/privkey.pem',
        ];
    }
}
