<?php
declare(strict_types=1);

namespace Leikwan\Master\Controllers;

use Leikwan\Master\Http\Request;
use Leikwan\Master\Http\Response;
use Leikwan\Master\Support\Auth;
use Leikwan\Master\Support\AppSettings;
use Leikwan\Master\Support\ApiException;
use Leikwan\Master\Support\AndroidEnrollmentCodeService;
use Leikwan\Master\Support\BondLegReputationService;
use Leikwan\Master\Support\ClientOnlineSessionService;
use Leikwan\Master\Support\DeviceControlService;
use Leikwan\Master\Support\DesktopClientSessionService;
use Leikwan\Master\Support\LeigodTokenBroker;
use Leikwan\Master\Support\LeigodTokenProvider;
use Leikwan\Master\Support\ProvisioningService;
use Leikwan\Master\Support\UltraClientStatusService;
use Leikwan\Master\Support\UltraInventoryService;
use Leikwan\Master\Support\UltraLeaseService;
use Leikwan\Master\Support\UltraMode;
use Leikwan\Master\Support\UltraMaterialService;
use Leikwan\Master\Support\TrustedClientIpResolver;

final class ClientAgentController extends BaseController
{
    private LeigodTokenProvider $ultraTokenProvider;
    private UltraMaterialService $ultraMaterials;
    private UltraLeaseService $ultraLeases;
    private UltraClientStatusService $ultraClientStatus;
    private DeviceControlService $deviceControls;
    private AndroidEnrollmentCodeService $enrollmentCodes;

    public function __construct(
        \Leikwan\Master\Config $config,
        \Leikwan\Master\Database\Database $db,
        private ProvisioningService $provisioning,
        ?LeigodTokenProvider $leigodTokenProvider = null,
        ?UltraMaterialService $ultraMaterials = null,
        ?UltraClientStatusService $ultraClientStatus = null,
        ?UltraLeaseService $ultraLeases = null,
        ?DeviceControlService $deviceControls = null,
        ?AndroidEnrollmentCodeService $enrollmentCodes = null,
    ) {
        parent::__construct($config, $db);
        $this->ultraMaterials = $ultraMaterials
            ?? new UltraMaterialService($config, $db, new AppSettings($db));
        $this->ultraTokenProvider = $leigodTokenProvider
            ?? new LeigodTokenBroker($config, new AppSettings($db), $this->ultraMaterials);
        $this->ultraLeases = $ultraLeases ?? new UltraLeaseService($config, $db, $this->ultraMaterials);
        $this->ultraClientStatus = $ultraClientStatus ?? new UltraClientStatusService($config, $db);
        $this->deviceControls = $deviceControls ?? DeviceControlService::fromConfig($config, $db);
        $this->enrollmentCodes = $enrollmentCodes ?? new AndroidEnrollmentCodeService($db);
    }

    public function deviceChallenge(Request $request, array $params): Response
    {
        $client = Auth::requireClientAgent($request, $this->db);
        $this->requireAndroidRequest($request);
        $this->assertRequestedClientId($client, $request);
        $challenge = $this->deviceControls->issueChallenge($client, $this->requestedDeviceId($request));
        return $this->noStoreJson(['ok' => true] + $challenge);
    }

    public function enrollDevice(Request $request, array $params): Response
    {
        $client = Auth::requireClientAgent($request, $this->db);
        $this->requireAndroidRequest($request);
        $this->assertRequestedClientId($client, $request);
        $proof = $request->input('attestation_proof', []);
        if (!is_array($proof)) {
            throw new ApiException('attestation_proof_invalid', 422);
        }
        $enrollment = $this->deviceControls->enroll(
            $client,
            $this->requestedDeviceId($request),
            (string) $request->input('nonce', ''),
            $proof,
        );
        $this->clientOnlineSessions()->enforceFromRequest($client, $request, 'pull');
        return $this->noStoreJson([
            'ok' => true,
            'client_id' => (int) $client['id'],
        ] + $enrollment);
    }

    public function acknowledgeDevice(Request $request, array $params): Response
    {
        $client = Auth::requireClientAgent($request, $this->db);
        $this->requireAndroidRequest($request);
        $this->deviceControls->acknowledge(
            $client,
            $this->requestedDeviceId($request),
            max(0, (int) $request->input('command_version', 0)),
        );
        return $this->noStoreJson(['ok' => true]);
    }

    public function enrollmentCodeChallenge(Request $request, array $params): Response
    {
        $this->requireAndroidRequest($request);
        $client = $this->activeClientForEnrollmentCode((string) $request->input('enrollment_code', ''));
        $challenge = $this->deviceControls->issueChallenge($client, $this->requestedDeviceId($request));
        return $this->noStoreJson([
            'ok' => true,
            'client_id' => (int) $client['id'],
        ] + $challenge);
    }

    public function enrollmentCodeEnroll(Request $request, array $params): Response
    {
        $this->requireAndroidRequest($request);
        $code = (string) $request->input('enrollment_code', '');
        $proof = $request->input('attestation_proof', []);
        if (!is_array($proof)) {
            throw new ApiException('attestation_proof_invalid', 422);
        }
        [$client, $enrollment] = $this->db->transaction(function () use ($code, $request, $proof): array {
            $codeRow = $this->enrollmentCodes->findActive($code);
            if ($codeRow === null) {
                throw new ApiException('android_enrollment_code_invalid', 403);
            }
            $client = $this->activeClientForEnrollmentCodeRow($codeRow);
            $enrollment = $this->deviceControls->enroll(
                $client,
                $this->requestedDeviceId($request),
                (string) $request->input('nonce', ''),
                $proof,
            );
            if ($this->enrollmentCodes->consume($code) === null) {
                throw new ApiException('android_enrollment_code_invalid', 403);
            }
            return [$client, $enrollment];
        });
        $this->clientOnlineSessions()->enforceFromRequest($client, $request, 'pull');
        return $this->noStoreJson([
            'ok' => true,
            'client_id' => (int) $client['id'],
            'agent_token' => (string) $client['agent_token'],
        ] + $enrollment);
    }

    public function resolveUltraMaterials(Request $request, array $params): Response
    {
        $client = Auth::requireClientAgent($request, $this->db);
        [$deviceSessions, $deviceId] = $this->enforceClientRequest($client, $request, 'pull');
        $deviceSession = $deviceSessions->activeSessionForClientAndDevice((int) $client['id'], $deviceId);
        $assigned = $this->provisioning->assignedForwardConfigsForClient((int) $client['id'], $deviceSession);
        $allowed = [];
        foreach ($assigned as $forward) {
            if (is_array($forward)
                && UltraMode::isBackendMode($forward['backend_mode'] ?? '')
                && (string) ($forward['service_status'] ?? '') === 'active') {
                $allowed[(int) ($forward['forward_id'] ?? 0)] = true;
            }
        }
        $resolved = [];
        $requests = $request->input('forwards', []);
        foreach (array_slice(is_array($requests) ? $requests : [], 0, 32) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $forwardId = (int) ($item['forward_id'] ?? 0);
            $token = trim((string) ($item['token'] ?? ''));
            if ($forwardId <= 0 || $token === '' || !isset($allowed[$forwardId])) {
                continue;
            }
            $material = $this->ultraMaterials->resolveClientToken(
                $token,
                (int) $client['id'],
                $deviceId,
                $forwardId,
                []
            );
            if ($material !== []) {
                $resolved[] = $material;
            }
        }
        return $this->json(['ok' => true, 'materials' => $resolved])->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Issue one-shot Ultra v2 leases after re-reading the Panel policy. The
     * provider candidate is resolved server-side and is never persisted in a
     * client config or returned as a reusable material collection.
     */
    public function resolveUltraLease(Request $request, array $params): Response
    {
        $client = Auth::requireClientAgent($request, $this->db);
        [$deviceSessions, $deviceId] = $this->enforceClientRequest($client, $request, 'lease');
        $deviceSession = $deviceSessions->activeSessionForClientAndDevice((int) $client['id'], $deviceId);
        $assigned = $this->provisioning->assignedForwardConfigsForClient((int) $client['id'], $deviceSession);
        $assignedById = [];
        foreach (is_array($assigned) ? $assigned : [] as $forward) {
            if (is_array($forward)) {
                $assignedById[(int) ($forward['forward_id'] ?? 0)] = $forward;
            }
        }
        $forwards = $request->input('forwards', []);
        if (!is_array($forwards) || $forwards === []) {
            $forwards = [$request->body()];
        }
        $forwards = array_slice(array_values($forwards), 0, 4);
        $granted = $this->attachUltraAccessGrants($assigned, (int) $client['id'], $deviceId, true);
        $grantsById = [];
        foreach ($granted as $grantedForward) {
            if (!is_array($grantedForward)) {
                continue;
            }
            $grantedId = (int) ($grantedForward['forward_id'] ?? 0);
            if ($grantedId > 0 && is_array($grantedForward[UltraMode::ACCESS_FIELD] ?? null)) {
                $grantsById[$grantedId] = $grantedForward[UltraMode::ACCESS_FIELD];
            }
        }
        $leases = [];
        $lastError = 'lease_scope_invalid';
        foreach ($forwards as $item) {
            if (!is_array($item)) {
                continue;
            }
            $forwardId = (int) ($item['forward_id'] ?? 0);
            $forward = $assignedById[$forwardId] ?? null;
            $grant = $grantsById[$forwardId] ?? null;
            if (!is_array($forward) || !is_array($grant)) {
                $lastError = 'policy_unavailable';
                continue;
            }
            $ticket = trim((string) ($item['session_ticket'] ?? ''));
            if ($ticket === '' || !hash_equals($ticket, (string) ($grant['session_ticket'] ?? ''))) {
                $lastError = 'lease_scope_invalid';
                continue;
            }
            $policy = [
                'forward_id' => $forwardId,
                'device_hash' => hash('sha256', $deviceId),
                'service_state' => (string) ($forward['service_status'] ?? 'suspended'),
                'policy_generation' => (string) ($grant['policy_generation'] ?? ''),
                'target' => trim((string) ($forward['target'] ?? '')),
                'transport' => (string) ($grant['transport'] ?? $forward['transport'] ?? 'tcp+udp'),
                'rate_limit_mbps' => (int) ($grant['rate_limit_mbps'] ?? $forward['speed_limit_mbps'] ?? 0),
                'quota_remaining_bytes' => (int) ($grant['quota_remaining_bytes'] ?? 0),
                'expires_at' => (string) ($grant['expires_at'] ?? ''),
            ];
            $result = $this->ultraLeases->issueLease($item, (int) $client['id'], $deviceId, $policy);
            if (($result['ok'] ?? false) === true && is_array($result['lease'] ?? null)) {
                $leases[] = $result['lease'];
                continue;
            }
            $lastError = (string) ($result['error'] ?? $lastError);
        }
        if ($leases === []) {
            return $this->json(['ok' => false, 'error' => $lastError], 409)->withHeaders([
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
                'Pragma' => 'no-cache',
            ]);
        }
        return $this->json(['ok' => true, 'leases' => $leases])->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    public function pullConfigs(Request $request, array $params): Response
    {
        $client = Auth::requireClientAgent($request, $this->db);
        [$deviceSessionService, $deviceId] = $this->enforceClientRequest($client, $request, 'pull');
        $deviceSession = $deviceSessionService->activeSessionForClientAndDevice((int) $client['id'], $deviceId);

        $sinceRevision = trim((string) $request->input('since_revision', ''));
        $waitSeconds = max(0, min(25, (int) $request->input('wait_seconds', 0)));

        $snapshot = $this->stableConfigSnapshot((int) $client['id'], $deviceSession, $client);
        $configRevision = (string) $snapshot['config_revision'];
        $forceApply = (bool) $snapshot['force_apply'];

        // Bounded long-poll: only re-serve when the stable config actually changed.
        if ($sinceRevision !== '' && hash_equals($sinceRevision, $configRevision) && !$forceApply && $waitSeconds > 0) {
            $deadline = microtime(true) + $waitSeconds;
            while (microtime(true) < $deadline) {
                usleep(500000);
                $snapshot = $this->stableConfigSnapshot((int) $client['id'], $deviceSession, $client);
                if (!hash_equals($sinceRevision, (string) $snapshot['config_revision']) || (bool) $snapshot['force_apply']) {
                    break;
                }
            }
        }

        if ($sinceRevision !== '' && hash_equals($sinceRevision, (string) $snapshot['config_revision']) && !(bool) $snapshot['force_apply']) {
            return new Response(304, [
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
                'Pragma' => 'no-cache',
            ], '');
        }

        $forwards = $snapshot['forwards'];
        $agentConfig = $snapshot['agent_config'];
        $clientAgent = $snapshot['client_agent'];
        $clientAgentInstaller = $snapshot['client_agent_installer'];
        $installForwardRuntime = $snapshot['install_forward_runtime'];
        $bondBinary = $snapshot['bond_binary'];
        $mptcpRuntime = $snapshot['mptcp_runtime'];
        $cfwgLatencyWatchdog = $snapshot['cfwg_latency_watchdog'];
        $desiredBondSha256 = (string) $snapshot['desired_bond_sha256'];
        $updateRequestToken = (string) $snapshot['update_request_token'];
        $linuxProfileRuntime = $snapshot['linux_profile_runtime'];

        $platform = strtolower(trim((string) ($request->header('x-device-platform') ?? '')));
        $forwards = $this->attachUltraAccessGrants(
            $forwards,
            (int) $client['id'],
            $deviceId,
            str_starts_with($platform, 'windows') || str_starts_with($platform, 'linux')
        );

        $response = $this->json([
            'ok' => true,
            'config_revision' => (string) $snapshot['config_revision'],
            'forward_revision' => (string) $snapshot['forward_revision'],
            'force_apply' => (bool) $snapshot['force_apply'],
            'next_poll_after_ms' => (int) $snapshot['next_poll_after_ms'],
            'client' => [
                'id' => (int) $client['id'],
                'name' => (string) $client['name'],
                'display_host' => (string) $client['display_host'],
                'group_id' => $client['group_id'] !== null ? (int) $client['group_id'] : null,
                'diag_request_token' => (string) ($client['last_diag_request_token'] ?? ''),
                'diag_status' => (string) ($client['last_diag_status'] ?? ''),
                'agent_config_json' => $agentConfig,
                'agent_update_request_token' => $updateRequestToken,
                'agent_update_requested_at' => (string) ($client['agent_update_requested_at'] ?? ''),
                'desired_agent_version' => (string) ($clientAgent['version'] ?? ''),
                'desired_agent_sha256' => (string) ($clientAgent['sha256'] ?? ''),
                'desired_installer_version' => (string) ($clientAgentInstaller['version'] ?? ''),
                'desired_installer_sha256' => (string) ($clientAgentInstaller['sha256'] ?? ''),
                'desired_install_forward_runtime_version' => (string) ($installForwardRuntime['version'] ?? ''),
                'desired_install_forward_runtime_sha256' => (string) ($installForwardRuntime['sha256'] ?? ''),
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
            'linux_profile_runtime' => $linuxProfileRuntime,
        ]);
        return $response->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Reads the stable (grant-free) client config and derives the revision
     * hashes used for long-poll / 304. Ephemeral Ultra grant material is added
     * later and is intentionally excluded from the revision.
     *
     * @param array<string, mixed>|null $deviceSession
     * @param array<string, mixed> $client
     * @return array<string, mixed>
     */
    private function stableConfigSnapshot(int $clientId, ?array $deviceSession, array $client): array
    {
        $forwards = $this->provisioning->assignedForwardConfigsForClient($clientId, $deviceSession);
        $clientAgent = $this->artifactMetadata('client_agent');
        $clientAgentInstaller = $this->artifactMetadata('client_agent_installer');
        $installForwardRuntime = $this->artifactMetadata('install_forward_runtime');
        $bondBinary = $this->artifactMetadata('leikwan_bond_linux_amd64');
        $mptcpRuntime = $this->artifactMetadata('mptcp_client_runtime');
        $cfwgLatencyWatchdog = $this->artifactMetadata('cfwg_latency_watchdog');
        $desiredBondSha256 = (string) ($bondBinary['binary_sha256'] ?? $bondBinary['sha256'] ?? '');
        $updateRequestToken = trim((string) ($client['agent_update_request_token'] ?? ''));
        if ($updateRequestToken === '') {
            $updateRequestToken = $this->autoUpdateTokenForVersion((string) ($client['agent_version'] ?? ''), 'client_agent', 'auto-client-agent');
        }

        $agentConfig = $this->authoritativeAgentConfig($client);
        $agentConfig['udp_uot_sidepath'] = $this->provisioning->udpUotSidepathConfigForAssignedClientForwards(
            is_array($forwards) ? $forwards : [],
            is_array($agentConfig['udp_uot_sidepath'] ?? null) ? $agentConfig['udp_uot_sidepath'] : []
        );
        $agentConfig['tcp_accel_sidepath'] = $this->provisioning->tcpAccelSidepathConfigForAssignedClientForwards(
            is_array($forwards) ? $forwards : [],
            is_array($agentConfig['tcp_accel_sidepath'] ?? null) ? $agentConfig['tcp_accel_sidepath'] : []
        );
        $linuxProfileRuntime = null;
        $runtimePlatformScope = (string) ($client['runtime_platform_scope'] ?? 'all');
        if (in_array($runtimePlatformScope, ['all', 'linux'], true)) {
            $linuxProfileRuntime = $this->provisioning->linuxProfileRuntimeForClient($client, is_array($forwards) ? $forwards : []);
        }

        $forwardRevision = self::forwardRevision($forwards);
        $configRevision = self::configRevision($forwardRevision, $agentConfig, [
            'client_agent' => $clientAgent,
            'client_agent_installer' => $clientAgentInstaller,
            'install_forward_runtime' => $installForwardRuntime,
            'leikwan_bond_linux_amd64' => $bondBinary,
            'mptcp_client_runtime' => $mptcpRuntime,
            'cfwg_latency_watchdog' => $cfwgLatencyWatchdog,
        ]);

        return [
            'forwards' => $forwards,
            'agent_config' => $agentConfig,
            'linux_profile_runtime' => $linuxProfileRuntime,
            'client_agent' => $clientAgent,
            'client_agent_installer' => $clientAgentInstaller,
            'install_forward_runtime' => $installForwardRuntime,
            'bond_binary' => $bondBinary,
            'mptcp_runtime' => $mptcpRuntime,
            'cfwg_latency_watchdog' => $cfwgLatencyWatchdog,
            'desired_bond_sha256' => $desiredBondSha256,
            'update_request_token' => $updateRequestToken,
            'forward_revision' => $forwardRevision,
            'config_revision' => $configRevision,
            'force_apply' => false,
            'next_poll_after_ms' => 30000,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $forwards
     */
    public static function forwardRevision(array $forwards): string
    {
        $projection = [];
        foreach ($forwards as $forward) {
            if (!is_array($forward)) {
                continue;
            }
            $projection[] = [
                'forward_id' => (int) ($forward['forward_id'] ?? 0),
                'backend_mode' => (string) ($forward['backend_mode'] ?? ''),
                'target' => (string) ($forward['target'] ?? ''),
                'listen_port' => (int) ($forward['listen_port'] ?? 0),
                'target_contract' => (string) ($forward['target_contract'] ?? ''),
                'contract_version' => (int) ($forward['contract_version'] ?? 1),
                'mode' => (string) ($forward['mode'] ?? ''),
                'transport' => (string) ($forward['transport'] ?? ''),
                'service_status' => (string) ($forward['service_status'] ?? ''),
                'speed_limit_mbps' => (int) ($forward['speed_limit_mbps'] ?? 0),
                'quota_remaining_bytes' => (int) ($forward['quota_remaining_bytes'] ?? 0),
            ];
        }
        return hash('sha256', (string) json_encode($projection, JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string, mixed> $agentConfig
     * @param array<string, array<string, mixed>> $artifactVersions
     */
    public static function configRevision(string $forwardRevision, array $agentConfig, array $artifactVersions): string
    {
        $artifacts = [];
        foreach ($artifactVersions as $key => $artifact) {
            $artifacts[$key] = [
                'version' => (string) ($artifact['version'] ?? ''),
                'sha256' => (string) ($artifact['sha256'] ?? $artifact['binary_sha256'] ?? ''),
            ];
        }
        return hash('sha256', (string) json_encode([
            'forward_revision' => $forwardRevision,
            'agent_config' => $agentConfig,
            'artifacts' => $artifacts,
        ], JSON_UNESCAPED_SLASHES));
    }

    /**
     * Attach only normalized, short-lived broker grants to active ultra
     * forwards. Legacy leigod_mode and current_mode forwards do not enter
     * this path. Broker failures fail closed for the grant, not for unrelated
     * client configuration.
     *
     * @param array<int, array<string, mixed>> $forwards
     * @return array<int, array<string, mixed>>
     */
    private function attachUltraAccessGrants(array $forwards, int $clientId, string $deviceId, bool $policyV2 = false): array
    {
        $brokerForwards = $forwards;
        if (isset($this->provisioning)) {
            $metadata = $this->provisioning->ultraLineMetadataForAssignedForwards($forwards);
            foreach ($brokerForwards as $index => $forward) {
                if (!is_array($forward)) {
                    continue;
                }
                $forwardId = (string) ((int) ($forward['forward_id'] ?? 0));
                if (!isset($metadata[$forwardId])) {
                    continue;
                }
                $brokerForwards[$index]['_ultra_line_ids'] = $metadata[$forwardId]['line_ids'];
                $brokerForwards[$index]['_ultra_lines'] = $metadata[$forwardId]['lines'];
                $brokerForwards[$index]['_ultra_line_direction'] = $metadata[$forwardId]['line_direction'];
                $brokerForwards[$index]['_ultra_destination_zone'] = $metadata[$forwardId]['destination_zone'];
                $brokerForwards[$index]['_ultra_allowed_ingress_zones'] = $metadata[$forwardId]['allowed_ingress_zones'];
                $brokerForwards[$index]['_ultra_fallback_policy'] = $metadata[$forwardId]['fallback_policy'];
            }
        }
        try {
            $grants = $this->ultraTokenProvider->grantsForClientForwards($clientId, $deviceId, $brokerForwards, $policyV2);
        } catch (\Throwable) {
            return $policyV2 ? $this->scrubUltraForwards($forwards) : $forwards;
        }
        if ($grants === []) {
            return $policyV2 ? $this->scrubUltraForwards($forwards) : $forwards;
        }

        $zoneAliases = [];
        try {
            foreach ((new UltraInventoryService($this->config, $this->db))->activeZones('ingress') as $zone) {
                $code = strtolower(trim((string) ($zone['zone_code'] ?? '')));
                $aliases = is_array($zone['aliases'] ?? null) ? array_values(array_map('strval', $zone['aliases'])) : [];
                if ($code !== '' && $code !== 'unknown' && $aliases !== []) {
                    $zoneAliases[$code] = array_slice($aliases, 0, 16);
                }
            }
        } catch (\Throwable) {
            $zoneAliases = [];
        }

        foreach ($forwards as $index => $forward) {
            if (!is_array($forward)
                || !UltraMode::isBackendMode($forward['backend_mode'] ?? '')
                || (string) ($forward['service_status'] ?? '') !== 'active') {
                continue;
            }
            if ($policyV2) {
                $forwards[$index] = $this->scrubUltraClientForward($forward);
            }
            $forwardId = (string) ((int) ($forward['forward_id'] ?? 0));
            $grant = $grants[$forwardId] ?? null;
            $validGrant = $policyV2
                ? is_array($grant)
                    && ($grant['protocol'] ?? '') === UltraMode::POLICY_PROTOCOL
                    && trim((string) ($grant['session_ticket'] ?? '')) !== ''
                : is_array($grant)
                    && ($grant['protocol'] ?? '') === UltraMode::CAPABILITY_PROTOCOL
                    && trim((string) ($grant['token'] ?? '')) !== '';
            if (!$validGrant) {
                continue;
            }
            if (!$policyV2) {
                $grant['zone_aliases'] = $zoneAliases;
            }
            $forwards[$index][UltraMode::ACCESS_FIELD] = $grant;
        }
        return $forwards;
    }

    /** @param array<string,mixed> $forward @return array<string,mixed> */
    private function scrubUltraClientForward(array $forward): array
    {
        foreach ([
            'client_token',
            'line_client_id', 'line_client_name', 'line_client_host',
            'line_client_group_id', 'line_client_group_name', 'line_client_group_ids',
            'line_client_groups', 'line_group_mode', 'line_client_agent_config_json',
            'forward_combination_direction', 'forward_combination_label',
            'forward_combination_name', 'bond_sidecar_point_id', 'bond_sidecar_point_name',
            'bond_sidecar_host', 'bond_sidecar_port', 'mptcp_sidecar_port',
            'bond_sidecar_cf_listen_port', 'bond_sidecar_cf_sni_host',
            'bond_sidecar_approved_upstream_realizations', 'bond_sidecar_point_agent_config_json',
            'bond_remote_host', 'bond_remote_port', 'cf_location_group_id',
            '_ultra_line_ids', '_ultra_lines', '_ultra_line_direction',
            '_ultra_destination_zone', '_ultra_allowed_ingress_zones', '_ultra_fallback_policy',
        ] as $field) {
            unset($forward[$field]);
        }
        return $forward;
    }

    /** @param array<int,array<string,mixed>> $forwards @return array<int,array<string,mixed>> */
    private function scrubUltraForwards(array $forwards): array
    {
        foreach ($forwards as $index => $forward) {
            if (is_array($forward) && UltraMode::isBackendMode($forward['backend_mode'] ?? '')) {
                $forwards[$index] = $this->scrubUltraClientForward($forward);
            }
        }
        return $forwards;
    }

    public function heartbeat(Request $request, array $params): Response
    {
        $client = Auth::requireClientAgent($request, $this->db);
        [$deviceSessionService, $deviceId] = $this->enforceClientRequest($client, $request, 'heartbeat');
        $ultraStatuses = $request->input('ultra_status', []);
        if (is_array($ultraStatuses)) {
            try {
                $this->ultraClientStatus->ingest((int) $client['id'], $deviceId, $ultraStatuses);
            } catch (\Throwable) {
                // Runtime status is observational and must not interrupt the data-plane heartbeat.
            }
        }
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

        // Regression sentinel: client_heartbeat_republish_on_wireguard_identity_change
        // Client heartbeats are frequent, so only republish assigned forwards when the
        // client's WG identity changes, with a periodic safety-net force interval.
        $existingFingerprint = trim((string) ($existingAgentConfig['last_heartbeat_fingerprint'] ?? ''));
        $existingFingerprintAt = (int) ($existingAgentConfig['last_heartbeat_republish_at'] ?? 0);
        $forceIntervalSeconds = max(60, (int) ($this->config->getInt('CLIENT_REPUBLISH_FORCE_INTERVAL_SECONDS', 600) ?? 600));
        $wgFingerprintInputs = [
            (string) ($runtimeState['wireguard_public_key'] ?? ''),
            (string) ((int) ($runtimeState['wireguard_listen_port'] ?? 0)),
            (string) ((int) ($runtimeState['wireguard_fallback_tcp_port'] ?? 0)),
        ];
        $wgFingerprint = sha1(implode(':', $wgFingerprintInputs));
        $now = time();
        $shouldRepublish = ($wgFingerprint !== $existingFingerprint)
            || ($existingFingerprintAt <= 0)
            || (($now - $existingFingerprintAt) >= $forceIntervalSeconds);
        $runtimeState['last_heartbeat_fingerprint'] = $wgFingerprint;
        if ($shouldRepublish) {
            $runtimeState['last_heartbeat_republish_at'] = $now;
        } elseif ($existingFingerprintAt > 0) {
            $runtimeState['last_heartbeat_republish_at'] = $existingFingerprintAt;
        }

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
        $ultraUsage = $request->input('ultra_usage', []);
        $ultraActions = [];
        if (is_array($ultraUsage)) {
            foreach (array_slice($ultraUsage, 0, 32) as $usage) {
                if (!is_array($usage)) {
                    continue;
                }
                try {
                    $action = $this->provisioning->recordUltraUsage((int) $client['id'], $deviceId, $usage);
                    if (is_array($action)
                        && (int) ($action['forward_id'] ?? 0) > 0
                        && (string) ($action['action'] ?? '') === 'stop') {
                        $ultraActions[(int) $action['forward_id']] = [
                            'forward_id' => (int) $action['forward_id'],
                            'policy_generation' => (string) ($action['policy_generation'] ?? ''),
                            'action' => 'stop',
                            'reason' => (string) ($action['reason'] ?? 'policy'),
                        ];
                    }
                } catch (\Throwable) {
                    // Usage accounting is retried by the client and must not
                    // interrupt the ordinary heartbeat response.
                }
            }
        }
        if ($shouldRepublish) {
            $this->provisioning->republishActiveForwardsForClient((int) $client['id']);
        }

        return $this->json(['ok' => true, 'ultra_actions' => array_values($ultraActions)]);
    }

    public function diagnosticsResult(Request $request, array $params): Response
    {
        $client = Auth::requireClientAgent($request, $this->db);
        $this->enforceClientRequest($client, $request, 'pull');
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
     * @return array{0:ClientOnlineSessionService,1:string}
     */
    private function enforceClientRequest(array $client, Request $request, string $touchType): array
    {
        if ($this->isAndroidRequest($request)) {
            $this->deviceControls->requireActive($client, $request);
        }
        $sessions = $this->clientOnlineSessions();
        return [$sessions, $sessions->enforceFromRequest($client, $request, $touchType)];
    }

    private function clientOnlineSessions(): ClientOnlineSessionService
    {
        return new ClientOnlineSessionService(
            $this->db,
            new TrustedClientIpResolver((string) ($this->config->get('TRUSTED_PROXY_CIDRS', '') ?? '')),
        );
    }

    private function isAndroidRequest(Request $request): bool
    {
        return str_starts_with(strtolower(trim((string) ($request->header('x-device-platform') ?? ''))), 'android');
    }

    private function requireAndroidRequest(Request $request): void
    {
        if (!$this->isAndroidRequest($request)) {
            throw new ApiException('android_platform_required', 403);
        }
    }

    /** @param array<string, mixed> $client */
    private function assertRequestedClientId(array $client, Request $request): void
    {
        $requestedClientId = (int) $request->input('client_id', 0);
        if ($requestedClientId <= 0 || $requestedClientId !== (int) ($client['id'] ?? 0)
            || (string) ($client['status'] ?? '') !== 'active') {
            throw new ApiException('client_id_mismatch', 403);
        }
    }

    private function requestedDeviceId(Request $request): string
    {
        $header = trim((string) ($request->header('x-device-id') ?? ''));
        $body = trim((string) $request->input('device_id', ''));
        if ($header !== '' && $body !== '' && !hash_equals($header, $body)) {
            throw new ApiException('device_id_invalid', 422);
        }
        return $header !== '' ? $header : $body;
    }

    /** @return array<string, mixed> */
    private function activeClientForEnrollmentCode(string $code): array
    {
        $codeRow = $this->enrollmentCodes->findActive($code);
        if ($codeRow === null) {
            throw new ApiException('android_enrollment_code_invalid', 403);
        }
        return $this->activeClientForEnrollmentCodeRow($codeRow);
    }

    /** @param array<string, mixed> $codeRow @return array<string, mixed> */
    private function activeClientForEnrollmentCodeRow(array $codeRow): array
    {
        $client = $this->db->fetchOne(
            'SELECT * FROM clients WHERE id = :id AND status = :status LIMIT 1',
            [
                'id' => (int) ($codeRow['client_id'] ?? 0),
                'status' => 'active',
            ]
        );
        if ($client === null) {
            throw new ApiException('android_enrollment_code_invalid', 403);
        }
        return $client;
    }

    /** @param array<string, mixed> $payload */
    private function noStoreJson(array $payload, int $status = 200): Response
    {
        return $this->json($payload, $status)->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
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
            'panel_mirror_urls',
            'udp_uot_sidepath',
            'tcp_accel_sidepath',
            'cfwg_latency_watchdog',
            'runtime_refresh',
            'cfwg_edge_pins',
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
        foreach (explode(',', (string) ($this->config->get('CFWG_LATENCY_WATCHDOG_FORWARD_IDS', '') ?? '')) as $rawForwardId) {
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
