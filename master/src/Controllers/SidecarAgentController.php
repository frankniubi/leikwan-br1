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
        $updateRequestToken = trim((string) ($point['agent_update_request_token'] ?? ''));

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
        $this->provisioning->recordAgentUsage('sidecar', (int) $point['id'], $usageBytesIn, $usageBytesOut);
        $this->provisioning->republishActiveForwardsForSidecarPoint((int) $point['id']);
        $mptcpManagement = $this->provisioning->mptcpSidecarManagementForPoint((int) $point['id']);
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
            'SELECT f.id, f.path_prefix, f.target_host, f.target_port, f.transport_mode
             FROM forwards f
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
        $hasBondRuntime = $bondForwardIds !== [];
        $hasMptcpRuntime = !empty($mptcpManagement['underlays']) || !empty(($mptcpManagement['mptcp_policy']['endpoints'] ?? []));
        $bondListenPort = $this->sidecarBondListenPortForHeartbeat($point);
        $mptcpListenPort = $this->sidecarMptcpListenPortForHeartbeat(array_merge($point, [
            'agent_config_json' => json_encode($runtimeState, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]));
        $cfListenPort = max(1, (int) ($point['cf_listen_port'] ?? 443));
        $cfRuntime = $cfRows === [] ? null : [
            'listen_host' => (string) ($point['bind_host'] ?? '0.0.0.0'),
            'listen_port' => $cfListenPort,
            'tls_cert_path' => (string) ($runtimeState['cf_tls_cert_path'] ?? '/etc/ssl/cf/fullchain.pem'),
            'tls_key_path' => (string) ($runtimeState['cf_tls_key_path'] ?? '/etc/ssl/cf/privkey.pem'),
            'routes' => array_values(array_reduce($cfRows, static function (array $carry, array $row): array {
                $forwardId = (int) ($row['id'] ?? 0);
                $pathPrefix = (string) ($row['path_prefix'] ?? '');
                $target = (string) ($row['target_host'] ?? '') . ':' . (int) ($row['target_port'] ?? 0);
                $transportMode = (string) ($row['transport_mode'] ?? 'tcp');
                $carry[] = [
                    'forward_id' => $forwardId,
                    'service_name' => 'cf-forward-' . $forwardId,
                    'path_prefix' => $pathPrefix,
                    'target' => $target,
                    'transport_mode' => $transportMode,
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
                return $carry;
            }, [])),
        ];
        $commonManagement = [
            'agent_token' => (string) ($point['agent_token'] ?? ''),
            'agent_update_request_token' => $updateRequestToken,
            'agent_update_requested_at' => (string) ($point['agent_update_requested_at'] ?? ''),
            'desired_bond_version' => (string) ($bondBinary['version'] ?? ''),
            'desired_bond_sha256' => $desiredBondSha256,
            'desired_sidecar_agent_version' => (string) ($sidecarAgent['version'] ?? ''),
            'desired_sidecar_agent_sha256' => (string) ($sidecarAgent['sha256'] ?? ''),
            'desired_sidecar_runtime_version' => (string) ($sidecarRuntime['version'] ?? ''),
            'desired_sidecar_runtime_sha256' => (string) ($sidecarRuntime['sha256'] ?? ''),
            'desired_installer_version' => (string) ($sidecarInstaller['version'] ?? ''),
            'desired_installer_sha256' => (string) ($sidecarInstaller['sha256'] ?? ''),
            'config_patch' => [
                'service_name' => 'leikwan-bond-sidecar-' . $pointId,
                'state_dir' => '/var/lib/leikwan-sidecar-' . $pointId,
                'runtime_config_path' => '/etc/leikwan-sidecar-' . $pointId . '/bond-runtime.json',
                'udp_uot_sidepath' => $runtimeState['udp_uot_sidepath'] ?? null,
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
                ] : null,
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
