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
        $sidecarAgent = $this->artifactMetadata('sidecar_agent');
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
        ]);
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
        $bondForwardIds = array_map(
            static fn(array $row): int => (int) ($row['id'] ?? 0),
            $this->db->fetchAll(
                'SELECT f.id
                 FROM forwards f
                 INNER JOIN services s ON s.id = f.service_id
                 WHERE f.bond_sidecar_point_id = :point_id
                   AND f.enabled = 1
                   AND f.client_mode = :client_mode
                   AND s.status = :service_status
                 ORDER BY f.id ASC',
                [
                    'point_id' => $pointId,
                    'client_mode' => 'bond',
                    'service_status' => 'active',
                ]
            )
        );
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
                'udp_uot_sidepath' => $runtimeState['udp_uot_sidepath'] ?? null,
                'managed_iperf3_ports' => $runtimeState['managed_iperf3_ports'] ?? [],
                'disable_auto_update' => $runtimeState['disable_auto_update'] ?? null,
            ],
        ];

        return Response::json(200, [
            'ok' => true,
            'management' => $commonManagement + [
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
                'sidecar_uplinks' => $sidecarUplinkManagement['uplinks'] ?? [],
                'self_leg_client_peers' => $this->provisioning->selfLegClientPeersForPoint($pointId),
                'donation_reverse_probe_targets' => $this->provisioning->donationReverseProbeTargetsForPoint($pointId),
                'cf_runtime' => $cfRuntime,
            ],
        ]);
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
        foreach (['wireguard_public_key'] as $stringKey) {
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
