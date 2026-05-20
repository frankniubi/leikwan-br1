<?php
declare(strict_types=1);

namespace Leikwan\Master\Controllers;

use Leikwan\Master\Http\Request;
use Leikwan\Master\Http\Response;
use Leikwan\Master\Support\Auth;
use Leikwan\Master\Support\BondLegReputationService;
use Leikwan\Master\Support\ClientOnlineSessionService;
use Leikwan\Master\Support\DesktopClientSessionService;
use Leikwan\Master\Support\ProvisioningService;

final class ClientAgentController extends BaseController
{
    public function __construct(
        \Leikwan\Master\Config $config,
        \Leikwan\Master\Database\Database $db,
        private ProvisioningService $provisioning,
    ) {
        parent::__construct($config, $db);
    }

    public function pullConfigs(Request $request, array $params): Response
    {
        $client = Auth::requireClientAgent($request, $this->db);
        $deviceSessionService = new ClientOnlineSessionService($this->db);
        $deviceId = $deviceSessionService->enforceFromRequest($client, $request, 'pull');
        $deviceSession = $deviceSessionService->activeSessionForClientAndDevice((int) $client['id'], $deviceId);
        $forwards = $this->provisioning->assignedForwardConfigsForClient((int) $client['id'], $deviceSession);
        $clientAgent = $this->artifactMetadata('client_agent');
        $clientAgentInstaller = $this->artifactMetadata('client_agent_installer');
        $bondBinary = $this->artifactMetadata('leikwan_bond_linux_amd64');
        $mptcpRuntime = $this->artifactMetadata('mptcp_client_runtime');
        $cfwgLatencyWatchdog = $this->artifactMetadata('cfwg_latency_watchdog');
        $desiredBondSha256 = (string) ($bondBinary['binary_sha256'] ?? $bondBinary['sha256'] ?? '');
        $updateRequestToken = trim((string) ($client['agent_update_request_token'] ?? ''));
        if ($updateRequestToken === '') {
            $updateRequestToken = $this->autoUpdateTokenForVersion((string) ($client['agent_version'] ?? ''), 'client_agent', 'auto-client-agent');
        }

        return $this->json([
            'ok' => true,
            'client' => [
                'id' => (int) $client['id'],
                'name' => (string) $client['name'],
                'display_host' => (string) $client['display_host'],
                'group_id' => $client['group_id'] !== null ? (int) $client['group_id'] : null,
                'diag_request_token' => (string) ($client['last_diag_request_token'] ?? ''),
                'diag_status' => (string) ($client['last_diag_status'] ?? ''),
                'agent_config_json' => $this->authoritativeAgentConfig($client),
                'agent_update_request_token' => $updateRequestToken,
                'agent_update_requested_at' => (string) ($client['agent_update_requested_at'] ?? ''),
                'desired_agent_version' => (string) ($clientAgent['version'] ?? ''),
                'desired_agent_sha256' => (string) ($clientAgent['sha256'] ?? ''),
                'desired_installer_version' => (string) ($clientAgentInstaller['version'] ?? ''),
                'desired_installer_sha256' => (string) ($clientAgentInstaller['sha256'] ?? ''),
                'desired_bond_version' => (string) ($bondBinary['version'] ?? ''),
                'desired_bond_sha256' => $desiredBondSha256,
                'desired_mptcp_runtime_version' => (string) ($mptcpRuntime['version'] ?? ''),
                'desired_mptcp_runtime_sha256' => (string) ($mptcpRuntime['sha256'] ?? ''),
                'desired_cfwg_watchdog_version' => (string) ($cfwgLatencyWatchdog['version'] ?? ''),
                'desired_cfwg_watchdog_sha256' => (string) ($cfwgLatencyWatchdog['sha256'] ?? ''),
                'online_device_limit' => max(1, (int) ($client['online_device_limit'] ?? 1)),
                'active_online_devices' => $deviceSessionService->activeCountForClient((int) $client['id']),
                'current_device_id' => $deviceId,
            ],
            'forwards' => $forwards,
        ]);
    }

    public function heartbeat(Request $request, array $params): Response
    {
        $client = Auth::requireClientAgent($request, $this->db);
        $deviceSessionService = new ClientOnlineSessionService($this->db);
        $deviceId = $deviceSessionService->deviceIdFromRequest($request);
        $deviceSessionService->recordAndEnforce($client, $deviceId, $deviceSessionService->deviceMetadataFromRequest($request), 'heartbeat');
        $usageBytesIn = max(0, (int) $request->input('usage_bytes_in', 0));
        $usageBytesOut = max(0, (int) $request->input('usage_bytes_out', 0));
        $existingAgentConfig = is_string($client['agent_config_json'] ?? null)
            ? json_decode((string) $client['agent_config_json'], true)
            : [];
        if (!is_array($existingAgentConfig)) {
            $existingAgentConfig = [];
        }
        $runtimeState = $this->mergeRuntimeState($existingAgentConfig, [
            'supports_mptcp' => $request->input('supports_mptcp'),
            'wireguard_public_key' => $request->input('wireguard_public_key'),
            'cfwg_watchdog_sha256' => $request->input('cfwg_watchdog_sha256'),
            'wireguard_listen_port' => $request->input('wireguard_listen_port'),
            'wireguard_fallback_tcp_port' => $request->input('wireguard_fallback_tcp_port'),
        ]);
        $this->db->execute(
            'UPDATE clients
             SET agent_version = :agent_version,
                 agent_config_json = :agent_config_json,
                 last_sync_at = :last_sync_at,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'id' => $client['id'],
                'agent_version' => (string) $request->input('agent_version', ''),
                'agent_config_json' => $runtimeState !== [] ? json_encode($runtimeState, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'last_sync_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]
        );
        $reputation = $request->input('bond_leg_reputation', []);
        if (is_array($reputation) && $reputation !== []) {
            (new BondLegReputationService($this->db))->ingestClientSnapshots(
                (int) $client['id'],
                is_array($reputation['forwards'] ?? null) ? $reputation['forwards'] : []
            );
        }
        $this->provisioning->recordAgentUsage('client', (int) $client['id'], $usageBytesIn, $usageBytesOut);

        return $this->json(['ok' => true]);
    }

    public function diagnosticsResult(Request $request, array $params): Response
    {
        $client = Auth::requireClientAgent($request, $this->db);
        $requestToken = (string) $request->input('request_token', '');
        $results = is_array($request->input('results', [])) ? $request->input('results', []) : [];
        $error = (string) $request->input('error', '');

        $this->db->execute(
            'UPDATE clients
             SET last_diag_status = :status,
                 last_diag_run_at = :run_at,
                 last_diag_result_json = :result_json,
                 last_diag_error = :error,
                 updated_at = :updated_at
             WHERE id = :id AND (last_diag_request_token = :request_token OR :request_token = \'\')',
            [
                'id' => $client['id'],
                'status' => $error === '' ? 'ok' : 'error',
                'run_at' => gmdate('Y-m-d H:i:s'),
                'result_json' => json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'error' => $error !== '' ? $error : null,
                'updated_at' => gmdate('Y-m-d H:i:s'),
                'request_token' => $requestToken,
            ]
        );

        return $this->json(['ok' => true]);
    }

    public function desktopLogin(Request $request, array $params): Response
    {
        $email = strtolower(trim((string) $request->input('email', '')));
        $password = (string) $request->input('password', '');

        if ($email === '' || $password === '') {
            return $this->json(['ok' => false, 'error' => 'email and password required'], 400);
        }

        $user = $this->db->fetchOne('SELECT * FROM users WHERE email = :email LIMIT 1', ['email' => $email]);

        if (
            $user === null ||
            (string) $user['status'] !== 'active' ||
            !is_string($user['password_hash']) ||
            $user['password_hash'] === '' ||
            !password_verify($password, (string) $user['password_hash'])
        ) {
            return $this->json(['ok' => false, 'error' => 'invalid credentials'], 401);
        }

        // Find clients owned by this user
        $clients = $this->db->fetchAll(
            'SELECT id, name, agent_token FROM clients WHERE owner_user_id = :user_id AND status = :status',
            ['user_id' => $user['id'], 'status' => 'active']
        );

        if (empty($clients)) {
            return $this->json(['ok' => false, 'error' => 'no clients assigned to this account'], 403);
        }

        $sessionService = new DesktopClientSessionService($this->db, $this->config);
        $session = $sessionService->issueForUser((int) $user['id'], (string) $user['email'], (string) ($user['name'] ?? $user['email']));

        return $this->json([
            'ok' => true,
            'session_token' => (string) $session['session_token'],
            'user_id' => (int) $user['id'],
            'username' => (string) ($user['name'] ?? $user['email']),
            'email' => (string) $user['email'],
            'clients' => array_map(fn($c) => [
                'id' => (int) $c['id'],
                'name' => (string) $c['name'],
            ], $clients),
        ]);
    }

    public function selectDesktopClient(Request $request, array $params): Response
    {
        $sessionToken = trim((string) $request->input('session_token', ''));
        $clientId = (int) $request->input('client_id', 0);

        if ($sessionToken === '' || $clientId <= 0) {
            return $this->json(['ok' => false, 'error' => 'session_token and client_id required'], 400);
        }

        $sessionService = new DesktopClientSessionService($this->db, $this->config);
        $session = $sessionService->findActive($sessionToken);
        if ($session === null) {
            return $this->json(['ok' => false, 'error' => 'invalid desktop session'], 401);
        }

        $client = $this->db->fetchOne(
            'SELECT id, name, agent_token FROM clients WHERE id = :id AND owner_user_id = :user_id AND status = :status LIMIT 1',
            ['id' => $clientId, 'user_id' => $session['user_id'], 'status' => 'active']
        );

        if ($client === null) {
            return $this->json(['ok' => false, 'error' => 'client not found'], 404);
        }

        return $this->json([
            'ok' => true,
            'client_id' => (int) $client['id'],
            'client_name' => (string) $client['name'],
            'agent_token' => (string) $client['agent_token'],
        ]);
    }

    /**
     * @param array<string, mixed> $client
     * @return array<string, mixed>
     */
    private function authoritativeAgentConfig(array $client): array
    {
        $stored = is_string($client['agent_config_json'] ?? null)
            ? json_decode((string) $client['agent_config_json'], true)
            : [];
        if (!is_array($stored)) {
            $stored = [];
        }

        $managedKeys = [
            'state_dir',
            'poll_interval_seconds',
            'health_check_interval_seconds',
            'log_level',
            'udp_uot_sidepath',
            'cfwg_latency_watchdog',
        ];
        $snapshot = array_fill_keys(array_merge(['master_url'], $managedKeys), null);
        $snapshot['master_url'] = $this->agentBaseUrl();
        $snapshot['artifact_base_url'] = $this->artifactBaseUrl();

        foreach ($managedKeys as $key) {
            if (array_key_exists($key, $stored)) {
                $snapshot[$key] = $stored[$key];
            }
        }
        $snapshot['cfwg_latency_watchdog'] = $this->mergeCfwgLatencyWatchdogDefaults(
            is_array($snapshot['cfwg_latency_watchdog']) ? $snapshot['cfwg_latency_watchdog'] : []
        );

        return $snapshot;
    }

    /**
     * @param array<string, mixed> $stored
     * @return array<string, mixed>
     */
    private function mergeCfwgLatencyWatchdogDefaults(array $stored): array
    {
        $defaults = $this->defaultCfwgLatencyWatchdogConfig();
        foreach ($defaults as $key => $value) {
            if (!array_key_exists($key, $stored)) {
                $stored[$key] = $value;
            }
        }
        if (!is_array($stored['baseline_rtt_ms'] ?? null)) {
            $stored['baseline_rtt_ms'] = $defaults['baseline_rtt_ms'];
        } else {
            foreach ($defaults['baseline_rtt_ms'] as $forwardId => $rttMs) {
                if (!array_key_exists((string) $forwardId, $stored['baseline_rtt_ms'])) {
                    $stored['baseline_rtt_ms'][(string) $forwardId] = $rttMs;
                }
            }
        }
        if (!is_array($stored['restart_actions'] ?? null) || $stored['restart_actions'] === []) {
            $stored['restart_actions'] = $defaults['restart_actions'];
        }
        return $stored;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultCfwgLatencyWatchdogConfig(): array
    {
        $enabled = $this->config->getBool('CFWG_LATENCY_WATCHDOG_ENABLED', true);
        $forwardIds = [];
        foreach (explode(',', (string) ($this->config->get('CFWG_LATENCY_WATCHDOG_FORWARD_IDS', '47') ?? '47')) as $rawForwardId) {
            $forwardId = (int) trim($rawForwardId);
            if ($forwardId > 0 && !in_array($forwardId, $forwardIds, true)) {
                $forwardIds[] = $forwardId;
            }
        }
        $baselineRtt = [];
        foreach (explode(',', (string) ($this->config->get('CFWG_LATENCY_WATCHDOG_BASELINES', '47:31') ?? '47:31')) as $rawPair) {
            $parts = array_map('trim', explode(':', $rawPair, 2));
            if (count($parts) !== 2) {
                continue;
            }
            $forwardId = (int) $parts[0];
            $rttMs = (float) $parts[1];
            if ($forwardId > 0 && $rttMs > 0) {
                $baselineRtt[(string) $forwardId] = $rttMs;
            }
        }

        return [
            'enabled' => $enabled,
            'forward_ids' => $forwardIds,
            'probe_interval_seconds' => $this->config->getInt('CFWG_LATENCY_WATCHDOG_PROBE_INTERVAL_SECONDS', 5),
            'window_samples' => $this->config->getInt('CFWG_LATENCY_WATCHDOG_WINDOW_SAMPLES', 3),
            'dwell_windows' => $this->config->getInt('CFWG_LATENCY_WATCHDOG_DWELL_WINDOWS', 2),
            'critical_rtt_ms' => $this->config->getInt('CFWG_LATENCY_WATCHDOG_CRITICAL_RTT_MS', 55),
            'critical_rtt_delta_ms' => $this->config->getInt('CFWG_LATENCY_WATCHDOG_CRITICAL_RTT_DELTA_MS', 10),
            'baseline_rtt_ms' => $baselineRtt,
            'relative_degradation_factor' => (float) ($this->config->get('CFWG_LATENCY_WATCHDOG_RELATIVE_FACTOR', '1.35') ?? '1.35'),
            'min_relative_degradation_ms' => $this->config->getInt('CFWG_LATENCY_WATCHDOG_MIN_RELATIVE_DELTA_MS', 8),
            'critical_loss_pct' => (float) ($this->config->get('CFWG_LATENCY_WATCHDOG_CRITICAL_LOSS_PCT', '0.34') ?? '0.34'),
            'cooldown_seconds' => $this->config->getInt('CFWG_LATENCY_WATCHDOG_COOLDOWN_SECONDS', 240),
            'ping_timeout_seconds' => $this->config->getInt('CFWG_LATENCY_WATCHDOG_PING_TIMEOUT_SECONDS', 1),
            'restart_actions' => ['wstunnel', 'wireguard'],
            'state_write_interval_seconds' => $this->config->getInt('CFWG_LATENCY_WATCHDOG_STATE_WRITE_INTERVAL_SECONDS', 15),
        ];
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function mergeRuntimeState(array $existing, array $input): array
    {
        $merged = $existing;
        foreach (['supports_mptcp'] as $boolKey) {
            if (array_key_exists($boolKey, $input) && $input[$boolKey] !== null && $input[$boolKey] !== '') {
                $merged[$boolKey] = !in_array(strtolower(trim((string) $input[$boolKey])), ['0', 'false', 'no', 'off'], true);
            }
        }
        foreach (['wireguard_public_key', 'cfwg_watchdog_sha256'] as $stringKey) {
            if (array_key_exists($stringKey, $input)) {
                $value = trim((string) ($input[$stringKey] ?? ''));
                if ($value !== '') {
                    $merged[$stringKey] = $value;
                }
            }
        }
        foreach (['wireguard_listen_port', 'wireguard_fallback_tcp_port'] as $intKey) {
            if (array_key_exists($intKey, $input) && $input[$intKey] !== null && $input[$intKey] !== '') {
                $merged[$intKey] = max(0, (int) $input[$intKey]);
            }
        }
        return $merged;
    }
}
