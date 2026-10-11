<?php
declare(strict_types=1);

namespace Leikwan\Master\Controllers;

use Leikwan\Master\Http\Request;
use Leikwan\Master\Http\Response;
use Leikwan\Master\Support\Auth;
use Leikwan\Master\Support\BondLegReputationService;
use Leikwan\Master\Support\ProvisioningService;

final class SidecarAgentController extends BaseController
{
    public function __construct(
        \Leikwan\Master\Config $config,
        \Leikwan\Master\Database\Database $db,
        private ProvisioningService $provisioning,
    ) {
        parent::__construct($config, $db);
    }

    public function heartbeat(Request $request, array $params): Response
    {
        $point = Auth::requireSidecarPointAgent($request, $this->db);
        $body = is_array($request->body()) ? $request->body() : [];
        $bondBinary = $this->artifactMetadata('leikwan_bond_linux_amd64');
        $reportedArchitecture = strtolower(trim((string) $request->input(
            'agent_architecture',
            $request->header('x-device-architecture') ?? ''
        )));
        $agentArchitecture = in_array($reportedArchitecture, ['arm64', 'aarch64', 'armv8', 'armv8l'], true)
            ? 'arm64'
            : 'amd64';
        $reportedRuntimeMode = strtolower(trim((string) $request->input('runtime_binary_mode', '')));
        $runtimeBinaryMode = in_array($reportedRuntimeMode, ['frozen', 'python'], true)
            ? $reportedRuntimeMode
            : '';
        $sidecarAgent = $this->artifactMetadata('sidecar_agent_linux_' . $agentArchitecture);
        if ($sidecarAgent === []) {
            $sidecarAgent = $this->artifactMetadata('sidecar_agent');
        }
        $sidecarRuntime = $this->artifactMetadata('mptcp_sidecar_runtime');
        $sidecarInstaller = $this->artifactMetadata('bond_sidecar_installer');
        $desiredBondSha256 = (string) ($bondBinary['binary_sha256'] ?? $bondBinary['sha256'] ?? '');
        $status = trim((string) $request->input('status', 'ok'));
        $version = trim((string) $request->input('version', ''));
        $error = trim((string) $request->input('error', ''));
        $usageBytesIn = max(0, (int) $request->input('usage_bytes_in', 0));
        $usageBytesOut = max(0, (int) $request->input('usage_bytes_out', 0));
        $existingAgentConfig = is_string($point['agent_config_json'] ?? null)
            ? json_decode((string) $point['agent_config_json'], true)
            : [];
        if (!is_array($existingAgentConfig)) {
            $existingAgentConfig = [];
        }
        $runtimeState = $this->mergeRuntimeState($existingAgentConfig, [
            'supports_mptcp' => $request->input('supports_mptcp'),
            'wireguard_public_key' => $request->input('wireguard_public_key'),
            'wireguard_listen_port' => $request->input('wireguard_listen_port'),
            'wireguard_fallback_tcp_port' => $request->input('wireguard_fallback_tcp_port'),
            'wireguard_peer_state' => $request->input('wireguard_peer_state'),
            'agent_architecture' => $agentArchitecture,
            'runtime_binary_mode' => $runtimeBinaryMode,
            'external_uot' => $request->input('external_uot'),
        ]);
        // Appendix C.6f: consume the stored peer-state and flag one-way return-path failures.
        $peerHealth = $this->evaluateWireguardPeerStates(
            is_array($runtimeState['wireguard_peer_state'] ?? null) ? $runtimeState['wireguard_peer_state'] : []
        );
        $degradedPeers = array_values(array_filter(
            $peerHealth,
            static fn(array $row): bool => ($row['degraded'] ?? false) === true
        ));
        $runtimeState['wireguard_peer_health'] = $peerHealth;
        $runtimeState['wireguard_peer_one_way_degraded'] = $degradedPeers !== [];
        $directCfBackendForwardIds = [];
        $rawDirectCfBackendForwardIds = $runtimeState['cf_direct_backend_forward_ids'] ?? [];
        if (is_array($rawDirectCfBackendForwardIds)) {
            foreach ($rawDirectCfBackendForwardIds as $rawForwardId) {
                $directForwardId = (int) $rawForwardId;
                if ($directForwardId > 0) {
                    $directCfBackendForwardIds[$directForwardId] = true;
                }
            }
        }
        $updateRequestToken = trim((string) ($point['agent_update_request_token'] ?? ''));
        // Regression sentinel: sidecar_explicit_update_token_one_shot
        // agent_update_request_token is a one-shot force refresh. The agent keeps
        // only ONE last_completed_request_token, so a token that stays on the
        // point forever re-arms every time any other token (a persistent
        // auto-release mismatch, or a second agent sharing the state file)
        // completes: explicit -> auto -> explicit ... reinstalling the sidecar
        // every heartbeat (point 7 fra1: ~750 installs/day, ~27 GB/day).
        // Retire it as soon as the agent reports it completed.
        $updateRequestToken = $this->retireCompletedUpdateRequestToken(
            (int) $point['id'],
            $updateRequestToken,
            $request->input('agent_update_state')
        );
        $sidecarAgentUpdatesEnabled = !in_array(strtolower(trim((string) ($runtimeState['sidecar_agent_update_enabled'] ?? ''))), ['0', 'false', 'no', 'off', ''], true);
        $reportedAgentVersion = $version !== '' ? $version : (string) ($point['agent_version'] ?? '');

        // Regression sentinel: sidecar_heartbeat_republish_gate
        // Republish is expensive (re-queues per-forward provision_tasks for every
        // endpoint of every active forward bound to this sidecar). Heartbeats fire
        // every ~30 s, so doing it unconditionally saturates the panel uplink on
        // sites with many forwards. Only republish when the sidecar's WG identity
        // actually changed, with a periodic safety-net force interval.
        $existingFingerprint = trim((string) ($existingAgentConfig['last_heartbeat_fingerprint'] ?? ''));
        $existingFingerprintAt = (int) ($existingAgentConfig['last_heartbeat_republish_at'] ?? 0);
        $forceIntervalSeconds = max(60, (int) ($this->config->getInt('SIDECAR_REPUBLISH_FORCE_INTERVAL_SECONDS', 600) ?? 600));
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
            'UPDATE sidecar_points
             SET agent_version = :agent_version,
                 agent_config_json = :agent_config_json,
                 last_heartbeat_at = :last_heartbeat_at,
                 last_status = :last_status,
                 last_error = :last_error,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'id' => (int) $point['id'],
                'agent_version' => $version !== '' ? $version : null,
                'agent_config_json' => $runtimeState !== [] ? json_encode($runtimeState, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'last_heartbeat_at' => gmdate('Y-m-d H:i:s'),
                'last_status' => $status !== '' ? $status : 'ok',
                'last_error' => $error !== '' ? $error : null,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]
        );
        $reputation = $request->input('bond_leg_reputation', []);
        if (is_array($reputation) && $reputation !== []) {
            (new BondLegReputationService($this->db))->ingestSidecarSnapshot((int) $point['id'], $reputation);
        }
        if (array_key_exists('donation_reverse_probe', $body) && is_array($body['donation_reverse_probe'])) {
            $this->provisioning->recordDonationReverseProbe((int) $point['id'], $body);
        }
        $this->provisioning->recordAgentUsage('sidecar', (int) $point['id'], $usageBytesIn, $usageBytesOut);
        if ($shouldRepublish) {
            $this->provisioning->republishActiveForwardsForSidecarPoint((int) $point['id']);
        }
        $mptcpTemporarilyDisabled = $this->config->getBool('TEMP_DISABLE_MPTCP_RUNTIME', true);
        $mptcpManagement = $mptcpTemporarilyDisabled
            ? []
            : $this->provisioning->mptcpSidecarManagementForPoint((int) $point['id']);
        $sidecarUplinkManagement = $this->provisioning->slaveSidecarUplinkManagementForPoint((int) $point['id']);
        $pointId = (int) ($point['id'] ?? 0);
        $bondForwardIds = $this->bondRuntimeForwardIdsForPoint($pointId, $runtimeState);
        $cfRows = $this->db->fetchAll(
            'SELECT f.id, f.path_prefix, f.target_host, f.target_port, f.transport_mode,
                    f.bond_remote_host, f.bond_remote_port, f.backend_mode,
                    fc.upstream_realization_mode AS chain_upstream_realization_mode,
                    combo.self_leg_cf_transport AS forward_combination_self_leg_cf_transport
             FROM forwards f
             LEFT JOIN forward_chains fc ON fc.id = f.forward_chain_id
             LEFT JOIN forward_combinations combo ON combo.id = f.forward_combination_id
             INNER JOIN services s ON s.id = f.service_id
             WHERE f.bond_sidecar_point_id = :point_id
               AND f.enabled = 1
               AND f.backend_mode = :backend_mode
               AND s.status = :service_status
             ORDER BY f.id ASC',
            [
                'point_id' => $pointId,
                'backend_mode' => 'cf_mode',
                'service_status' => 'active',
            ]
        );
        $mptcpRuntimeRows = $this->provisioning->mptcpRuntimeRowsForSidecarPoint($pointId);
        $hasBondRuntime = $bondForwardIds !== [];
        $hasMptcpRuntime = !$mptcpTemporarilyDisabled
            && (
                $mptcpRuntimeRows !== []
                || !empty($mptcpManagement['underlays'])
                || !empty(($mptcpManagement['mptcp_policy']['endpoints'] ?? []))
            );
        $udpUotSidepath = $this->provisioning->udpUotSidepathConfigForSidecarPoint(
            $pointId,
            is_array($runtimeState['udp_uot_sidepath'] ?? null) ? $runtimeState['udp_uot_sidepath'] : []
        );
        $bondListenPort = $this->sidecarBondListenPortForHeartbeat($point);
        $mptcpListenPort = $this->sidecarMptcpListenPortForHeartbeat(array_merge($point, [
            'agent_config_json' => json_encode($runtimeState, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]));
        $cfListenPort = max(1, (int) ($point['cf_listen_port'] ?? 443));
        $sidecarWireguardListenPort = (int) ($runtimeState['wireguard_listen_port'] ?? 0);
        if ($sidecarWireguardListenPort <= 0) {
            $sidecarWireguardListenPort = $mptcpListenPort > 0 ? $mptcpListenPort : 0;
        }
        $cfRuntime = $cfRows === [] ? null : [
            'listen_host' => (string) ($point['bind_host'] ?? '0.0.0.0'),
            'listen_port' => $cfListenPort,
            'tls_cert_path' => (string) ($runtimeState['cf_tls_cert_path'] ?? '/etc/ssl/cf/fullchain.pem'),
            'tls_key_path' => (string) ($runtimeState['cf_tls_key_path'] ?? '/etc/ssl/cf/privkey.pem'),
            'routes' => array_values(array_reduce($cfRows, static function (array $carry, array $row) use ($directCfBackendForwardIds, $sidecarWireguardListenPort): array {
                $forwardId = (int) ($row['id'] ?? 0);
                $pathPrefix = (string) ($row['path_prefix'] ?? '');
                $targetHost = trim((string) ($row['bond_remote_host'] ?? ''));
                $targetPort = (int) ($row['bond_remote_port'] ?? 0);
                if ($targetHost === '' || $targetPort <= 0) {
                    $targetHost = trim((string) ($row['target_host'] ?? ''));
                    $targetPort = (int) ($row['target_port'] ?? 0);
                }
                $target = $targetHost . ':' . $targetPort;
                if ($targetPort > 0) {
                    $target = '127.0.0.1:' . $targetPort;
                }
                $transportMode = (string) ($row['transport_mode'] ?? 'tcp');
                $carry[] = [
                    'forward_id' => $forwardId,
                    'service_name' => 'cf-forward-' . $forwardId,
                    'path_prefix' => $pathPrefix,
                    'target' => $target,
                    'transport_mode' => $transportMode,
                    'backend_mode' => isset($directCfBackendForwardIds[$forwardId]) ? 'direct' : 'wstunnel',
                ];
                if (str_contains($transportMode, 'udp')) {
                    $carry[] = [
                        'forward_id' => $forwardId,
                        'service_name' => 'cf-forward-' . $forwardId . '-uot',
                        'path_prefix' => $pathPrefix . '.uot',
                        'target' => '127.0.0.1:8999',
                        'transport_mode' => 'tcp',
                    ];
                }
                // Regression sentinel: cf_wg_route_publishes_wg_listen_port
                $selfLegCfTransport = strtolower(trim((string) ($row['forward_combination_self_leg_cf_transport'] ?? 'cf_direct')));
                $backendModeRaw = strtolower(trim((string) ($row['backend_mode'] ?? '')));
                if ($selfLegCfTransport === 'cf_wg' && $backendModeRaw === 'cf_mode' && $sidecarWireguardListenPort > 0 && $pathPrefix !== '') {
                    $carry[] = [
                        'forward_id' => $forwardId,
                        'service_name' => 'cf-forward-' . $forwardId . '-cfwg',
                        'path_prefix' => $pathPrefix . '.cfwg',
                        'target' => '127.0.0.1:' . $sidecarWireguardListenPort,
                        'transport_mode' => 'udp',
                        'shadow_route_key' => $pathPrefix . '.cfwg',
                        'backend_mode' => 'wstunnel',
                    ];
                }
                return $carry;
            }, [])),
        ];
        $commonManagement = [
            'agent_token' => (string) ($point['agent_token'] ?? ''),
            'agent_update_request_token' => $updateRequestToken,
            'agent_update_requested_at' => (string) ($point['agent_update_requested_at'] ?? ''),
            'desired_bond_version' => (string) ($bondBinary['version'] ?? ''),
            'desired_bond_sha256' => $desiredBondSha256,
            'desired_sidecar_agent_version' => $sidecarAgentUpdatesEnabled ? (string) ($sidecarAgent['version'] ?? '') : $reportedAgentVersion,
            'desired_sidecar_agent_sha256' => $sidecarAgentUpdatesEnabled ? (string) ($sidecarAgent['sha256'] ?? '') : '',
            'desired_sidecar_runtime_version' => (string) ($sidecarRuntime['version'] ?? ''),
            'desired_sidecar_runtime_sha256' => (string) ($sidecarRuntime['sha256'] ?? ''),
            'desired_installer_version' => (string) ($sidecarInstaller['version'] ?? ''),
            'desired_installer_sha256' => (string) ($sidecarInstaller['sha256'] ?? ''),
            'config_patch' => [
                'service_name' => 'leikwan-bond-sidecar-' . $pointId,
                'state_dir' => '/var/lib/leikwan-sidecar-' . $pointId,
                'runtime_config_path' => '/etc/leikwan-sidecar-' . $pointId . '/bond-runtime.json',
                'udp_uot_sidepath' => $udpUotSidepath,
                'managed_iperf3_ports' => $runtimeState['managed_iperf3_ports'] ?? [],
                'disable_auto_update' => $runtimeState['disable_auto_update'] ?? null,
            ],
        ];

        $management = $commonManagement + [
                'bind_host' => (string) ($point['bind_host'] ?? '0.0.0.0'),
                'listen_port' => $hasMptcpRuntime ? $mptcpListenPort : $bondListenPort,
                'mode' => $hasMptcpRuntime ? 'mptcp' : 'bond',
                'quic_enabled' => true,
                'quic_listen' => (string) ($point['bind_host'] ?? '0.0.0.0') . ':' . $bondListenPort,
                'quic_server_name' => (string) ($point['sidecar_host'] ?? ''),
                'hybrid_mode' => 'quic_ws',
                'total_speed_limit_mbps' => ($point['total_speed_limit_mbps'] ?? null) !== null ? (int) $point['total_speed_limit_mbps'] : null,
                'token_secret' => (string) ($point['token_secret'] ?? ''),
                'bond_runtime' => $hasBondRuntime ? [
                    'bind_host' => (string) ($point['bind_host'] ?? '0.0.0.0'),
                    'listen_port' => $bondListenPort,
                    'token_secret' => (string) ($point['token_secret'] ?? ''),
                    'forward_ids' => $bondForwardIds,
                    'total_speed_limit_mbps' => ($point['total_speed_limit_mbps'] ?? null) !== null ? (int) $point['total_speed_limit_mbps'] : null,
                ] : null,
                'mptcp_runtime' => $hasMptcpRuntime ? [
                    'bind_host' => (string) ($point['bind_host'] ?? '0.0.0.0'),
                    'listen_port' => $mptcpListenPort,
                    'token_secret' => (string) ($point['token_secret'] ?? ''),
                    'total_speed_limit_mbps' => ($point['total_speed_limit_mbps'] ?? null) !== null ? (int) $point['total_speed_limit_mbps'] : null,
                    'underlay' => $mptcpManagement['underlay'] ?? [],
                    'underlays' => $mptcpManagement['underlays'] ?? [],
                    'mptcp_policy' => $mptcpManagement['mptcp_policy'] ?? [],
                    'forward_routes' => array_values(array_map(static function (array $row): array {
                        $backendMode = (string) ($row['backend_mode'] ?? 'current_mode');
                        $transportMode = strtolower((string) ($row['transport_mode'] ?? 'tcp'));
                        return [
                            'forward_id' => (int) ($row['id'] ?? 0),
                            'backend_mode' => $backendMode,
                            'transport_protocol' => str_contains($transportMode, 'udp') ? 'udp' : 'tcp',
                            'target_mode' => $backendMode === 'cf_mode' ? 'cf_local_backend' : 'direct_target',
                            'target_host' => $backendMode === 'cf_mode' ? '127.0.0.1' : trim((string) ($row['target_host'] ?? '')),
                            'target_port' => $backendMode === 'cf_mode'
                                ? ((int) ($row['bond_remote_port'] ?? 0) > 0 ? (int) ($row['bond_remote_port'] ?? 0) : (int) ($row['target_port'] ?? 0))
                                : (int) ($row['target_port'] ?? 0),
                            'upstream_realization_profile' => (string) ($row['chain_upstream_realization_mode'] ?? ''),
                        ];
                    }, $mptcpRuntimeRows)),
                ] : null,
                'self_leg_client_peers' => $this->provisioning->selfLegClientPeersForPoint($pointId),
                'donation_reverse_probe_targets' => $this->provisioning->donationReverseProbeTargetsForPoint($pointId),
                'cf_runtime' => $cfRuntime,
            ];
        if (($sidecarUplinkManagement['authoritative'] ?? false) === true) {
            $management['sidecar_uplinks'] = $sidecarUplinkManagement['uplinks'] ?? [];
        }

        return Response::json(200, [
            'ok' => true,
            'management' => $management,
        ]);
    }

    /**
     * @param array<string, mixed> $runtimeState
     * @return array<int, int>
     */
    private function bondRuntimeForwardIdsForPoint(int $pointId, array $runtimeState): array
    {
        if ($pointId <= 0) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT f.id, f.client_mode, f.backend_mode
             FROM forwards f
             INNER JOIN services s ON s.id = f.service_id
             WHERE f.bond_sidecar_point_id = :point_id
               AND f.enabled = 1
               AND s.status = :service_status
             ORDER BY f.id ASC',
            [
                'point_id' => $pointId,
                'service_status' => 'active',
            ]
        );
        $pointConfigJson = json_encode($runtimeState, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $forwardIds = [];
        foreach ($rows as $row) {
            $clientMode = strtolower(trim((string) ($row['client_mode'] ?? '')));
            if ($clientMode === 'bond') {
                $forwardIds[] = (int) ($row['id'] ?? 0);
                continue;
            }
            $candidate = $row + [
                'bond_sidecar_point_id' => $pointId,
                'bond_sidecar_point_agent_config_json' => $pointConfigJson,
            ];
            if ($this->provisioning->usesSingleRaceSidecarFrontend($candidate)) {
                $forwardIds[] = (int) ($row['id'] ?? 0);
            }
        }
        return array_values(array_filter(array_unique($forwardIds), static fn(int $id): bool => $id > 0));
    }

    /**
     * Appendix C.6f — consume the per-peer wireguard_peer_state carried by the sidecar
     * heartbeat and flag one-way return-path failures.
     *
     * A peer that is transmitting (tx_bytes>0) but receiving nothing (rx_bytes==0) while its
     * handshake is FRESH (handshake_age_seconds is non-null and below FRESH_THRESHOLD, default
     * 180s) is dialling into a black hole: the WG return path is dead even though we just
     * completed a handshake. Such a peer is marked degraded with reason 'one_way_return_path'.
     * Peers with no handshake (null) or a stale handshake are NOT flagged, because there is no
     * fresh evidence of a live-but-one-way tunnel.
     *
     * Field contract (cross-agent): interface_name, peer_public_key, rx_bytes, tx_bytes,
     * handshake_age_seconds.
     *
     * @param array<int, mixed> $peerStates
     * @return array<int, array<string, mixed>>
     */
    private function evaluateWireguardPeerStates(array $peerStates): array
    {
        $freshThreshold = max(1, (int) ($this->config->getInt('SIDECAR_WG_PEER_FRESH_HANDSHAKE_SECONDS', 180) ?? 180));
        $evaluated = [];
        foreach ($peerStates as $peer) {
            if (!is_array($peer)) {
                continue;
            }
            $interfaceName = trim((string) ($peer['interface_name'] ?? ''));
            $peerPublicKey = trim((string) ($peer['peer_public_key'] ?? ''));
            $rxBytes = max(0, (int) ($peer['rx_bytes'] ?? 0));
            $txBytes = max(0, (int) ($peer['tx_bytes'] ?? 0));
            $handshakeAge = array_key_exists('handshake_age_seconds', $peer) && $peer['handshake_age_seconds'] !== null
                ? (int) $peer['handshake_age_seconds']
                : null;

            $freshHandshake = $handshakeAge !== null && $handshakeAge >= 0 && $handshakeAge < $freshThreshold;
            $oneWay = $txBytes > 0 && $rxBytes === 0 && $freshHandshake;

            $evaluated[] = [
                'interface_name' => $interfaceName,
                'peer_public_key' => $peerPublicKey,
                'rx_bytes' => $rxBytes,
                'tx_bytes' => $txBytes,
                'handshake_age_seconds' => $handshakeAge,
                'healthy' => !$oneWay,
                'degraded' => $oneWay,
                'reason' => $oneWay ? 'one_way_return_path' : '',
            ];
        }
        return $evaluated;
    }

    /**
     * Clear a one-shot agent_update_request_token once the agent's reported
     * agent_update_state shows it completed. Returns the token to hand out.
     * The UPDATE is guarded on the token value so a newer admin request that
     * landed between the point SELECT and this heartbeat is never dropped.
     */
    private function retireCompletedUpdateRequestToken(int $pointId, string $updateRequestToken, mixed $reportedUpdateState): string
    {
        if ($updateRequestToken === '' || !is_array($reportedUpdateState)) {
            return $updateRequestToken;
        }
        $completedToken = trim((string) ($reportedUpdateState['last_completed_request_token'] ?? ''));
        if ($completedToken !== $updateRequestToken) {
            return $updateRequestToken;
        }
        $this->db->execute(
            'UPDATE sidecar_points
             SET agent_update_request_token = NULL
             WHERE id = :id AND agent_update_request_token = :request_token',
            ['id' => $pointId, 'request_token' => $updateRequestToken]
        );
        return '';
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
        foreach (['wireguard_public_key', 'agent_architecture', 'runtime_binary_mode'] as $stringKey) {
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
        foreach (['wireguard_peer_state'] as $arrayKey) {
            if (array_key_exists($arrayKey, $input) && is_array($input[$arrayKey])) {
                $merged[$arrayKey] = array_values($input[$arrayKey]);
            }
        }
        if (array_key_exists('external_uot', $input) && is_array($input['external_uot'])) {
            $external = $input['external_uot'];
            $normalized = [];
            foreach ([
                'required', 'healthy', 'binary_present', 'config_present', 'config_valid',
                'service_active', 'service_enabled', 'listening', 'expected_active',
            ] as $boolKey) {
                if (array_key_exists($boolKey, $external)) {
                    $normalized[$boolKey] = filter_var($external[$boolKey], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
                }
            }
            foreach (['state', 'binary_path', 'binary_version', 'error'] as $stringKey) {
                if (array_key_exists($stringKey, $external)) {
                    $value = trim((string) ($external[$stringKey] ?? ''));
                    $normalized[$stringKey] = substr($value, 0, 255);
                }
            }
            foreach (['forward_count', 'listen_port', 'checked_at'] as $intKey) {
                if (array_key_exists($intKey, $external)) {
                    $normalized[$intKey] = max(0, (int) $external[$intKey]);
                }
            }
            foreach (['binary_sha256', 'config_sha256'] as $hashKey) {
                $value = strtolower(trim((string) ($external[$hashKey] ?? '')));
                if (preg_match('/^[a-f0-9]{64}$/', $value) === 1) {
                    $normalized[$hashKey] = $value;
                }
            }
            if ($normalized !== []) {
                $merged['external_uot'] = $normalized;
            }
        }
        return $merged;
    }

    /**
     * Keep controller-side heartbeat math aligned with ProvisioningService.
     *
     * @param array<string, mixed> $point
     */
    private function sidecarBondListenPortForHeartbeat(array $point): int
    {
        $configured = (int) ($point['bond_listen_port'] ?? 0);
        if ($configured > 0) {
            return $configured;
        }

        return max(0, (int) ($point['listen_port'] ?? 0));
    }

    /**
     * @param array<string, mixed> $point
     * @param array<string, mixed> $pointConfig
     */
    private function sidecarMptcpListenPortForHeartbeat(array $point, array $pointConfig = []): int
    {
        $configured = (int) ($point['mptcp_listen_port'] ?? 0);
        if ($configured > 0) {
            return $configured;
        }

        $wireguardListenPort = (int) ($pointConfig['wireguard_listen_port'] ?? 0);
        if ($wireguardListenPort > 0) {
            return $wireguardListenPort;
        }

        return $this->sidecarBondListenPortForHeartbeat($point);
    }
}
