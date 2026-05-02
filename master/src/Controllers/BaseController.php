<?php
declare(strict_types=1);

namespace Leikwan\Master\Controllers;

use DateTimeImmutable;
use DateTimeZone;
use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Http\Response;
use Leikwan\Master\Support\ApiException;
use Leikwan\Master\Support\SessionAuth;

abstract class BaseController
{
    public function __construct(
        protected Config $config,
        protected Database $db,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function json(array $payload, int $status = 200): Response
    {
        return Response::json($status, $payload);
    }

    protected function idParam(array $params, string $key = 'id'): int
    {
        return (int) ($params[$key] ?? 0);
    }

    protected function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    protected function agentBaseUrl(): string
    {
        $agentUrl = (string) ($this->config->get('AGENT_API_URL', '') ?? '');
        if ($agentUrl !== '') {
            return rtrim($agentUrl, '/');
        }

        return rtrim((string) ($this->config->get('APP_URL', 'https://panel.example.com') ?? 'https://panel.example.com'), '/');
    }

    protected function artifactBaseUrl(): string
    {
        $artifactUrl = (string) ($this->config->get('ARTIFACT_BASE_URL', '') ?? '');
        if ($artifactUrl !== '') {
            return rtrim($artifactUrl, '/');
        }

        return $this->agentBaseUrl();
    }

    protected function canonicalClientMode(string $mode): string
    {
        return match (strtolower(trim($mode))) {
            '', 'single' => 'single',
            'race', 'failover', 'multi-path', 'lb' => 'race',
            'bond', 'bonding', 'aggregate', 'aggregated' => 'bond',
            'mptcp', 'mptcp-wireguard', 'mptcp-ipsec', 'multipath-tcp' => 'mptcp',
            default => 'single',
        };
    }

    protected function clientModeLabel(string $mode): string
    {
        $mode = $this->canonicalClientMode($mode);
        if ($mode === 'race') {
            return 'Race';
        }
        if ($mode === 'bond') {
            return 'Bond';
        }
        if ($mode === 'mptcp') {
            return 'MPTCP';
        }
        return 'Single';

        return match ($this->canonicalClientMode($mode)) {
            'race' => 'Race / Failover',
            'bond' => '本地汇聚',
            default => 'Single',
        };
    }

    protected function transportModeLabel(string $mode): string
    {
        return match (strtolower(trim($mode))) {
            'tcp+udp' => 'TCP + UDP',
            'udp' => 'UDP',
            default => 'TCP',
        };
    }

    protected function endpointModeLabel(string $mode): string
    {
        return match (strtolower(trim($mode))) {
            'forward_chain' => 'Forward Chain',
            default => 'Slave Group',
        };
    }

    protected function renderClientModeOptions(string $selected = 'single', bool $allowBond = true): string
    {
        $selected = $this->canonicalClientMode($selected);
        $options =
            '<option value="single"' . ($selected === 'single' ? ' selected' : '') . '>Single</option>'
            . '<option value="race"' . ($selected === 'race' ? ' selected' : '') . '>Race</option>';

        if ($allowBond) {
            $options .= '<option value="bond"' . ($selected === 'bond' ? ' selected' : '') . '>Bond</option>';
            $options .= '<option value="mptcp"' . ($selected === 'mptcp' ? ' selected' : '') . '>MPTCP</option>';
        }

        if (false && $allowBond) {
            $options .= '<option value="bond"' . ($selected === 'bond' ? ' selected' : '') . '>本地汇聚 - true single-stream multi-slave aggregation via aggregator</option>';
        }

        return $options;
    }

    protected function renderEndpointModeOptions(string $selected = 'slave_group', bool $allowForwardChain = true): string
    {
        $selected = strtolower(trim($selected));
        $options = '<option value="slave_group"' . ($selected === 'slave_group' ? ' selected' : '') . '>Slave Group</option>';
        if ($allowForwardChain) {
            $options .= '<option value="forward_chain"' . ($selected === 'forward_chain' ? ' selected' : '') . '>Forward Chain</option>';
        }
        return $options;
    }

    /**
     * @param array<string, mixed> $point
     * @return array{class:string,label:string}
     */
    protected function sidecarPointStatusInfo(array $point): array
    {
        if ((int) ($point['is_active'] ?? 1) !== 1) {
            return ['class' => 'danger', 'label' => 'disabled'];
        }

        $status = strtolower(trim((string) ($point['last_status'] ?? '')));
        $lastHeartbeat = trim((string) ($point['last_heartbeat_at'] ?? ''));
        $heartbeatTs = $lastHeartbeat !== '' ? (strtotime($lastHeartbeat) ?: 0) : 0;
        $age = $heartbeatTs > 0 ? (time() - $heartbeatTs) : null;

        if ($status === '' || $heartbeatTs === 0) {
            return ['class' => 'info', 'label' => 'not installed'];
        }

        if ($age !== null && $age > 600) {
            return ['class' => 'info', 'label' => 'stale'];
        }

        if (in_array($status, ['ok', 'active'], true)) {
            return ['class' => 'success', 'label' => 'online'];
        }

        return ['class' => 'danger', 'label' => $status];
    }

    protected function sidecarServiceName(int $pointId): string
    {
        return 'leikwan-bond-sidecar-' . max(1, $pointId);
    }

    /**
     * @param array<string, mixed> $point
     */
    protected function sidecarBondListenPort(array $point): int
    {
        $configured = (int) ($point['bond_listen_port'] ?? 0);
        if ($configured > 0) {
            return $configured;
        }
        return max(0, (int) ($point['listen_port'] ?? 0));
    }

    /**
     * @param array<string, mixed> $point
     */
    protected function sidecarMptcpListenPort(array $point): int
    {
        $configured = (int) ($point['mptcp_listen_port'] ?? 0);
        if ($configured > 0) {
            return $configured;
        }
        $config = $this->decodeJsonAssoc($point['agent_config_json'] ?? null);
        $wireguardListenPort = (int) ($config['wireguard_listen_port'] ?? 0);
        if ($wireguardListenPort > 0) {
            return $wireguardListenPort;
        }
        return $this->sidecarBondListenPort($point);
    }

    /**
     * @param array<string, mixed> $point
     */
    protected function sidecarPortSummary(array $point): string
    {
        $parts = [];
        $bondPort = $this->sidecarBondListenPort($point);
        $mptcpPort = $this->sidecarMptcpListenPort($point);
        $cfPort = max(0, (int) ($point['cf_listen_port'] ?? 0));
        if ($bondPort > 0) {
            $parts[] = 'bond ' . $bondPort;
        }
        if ($mptcpPort > 0 && $mptcpPort !== $bondPort) {
            $parts[] = 'mptcp ' . $mptcpPort;
        }
        if ($cfPort > 0) {
            $parts[] = 'cf ' . $cfPort;
        }
        if ($parts === []) {
            return '0';
        }
        return implode(' / ', $parts);
    }

    /**
     * @param array<string, mixed> $point
     */
    protected function bondSidecarInstallCommand(array $point): string
    {
        $serviceName = $this->sidecarServiceName((int) ($point['id'] ?? 0));
        $command = 'curl -fsSL ' . $this->artifactBaseUrl() . '/artifacts/install-bond-sidecar.sh | bash -s -- --master '
            . $this->agentBaseUrl()
            . ' --artifact-base ' . $this->artifactBaseUrl()
            . ' --listen-port ' . $this->sidecarBondListenPort($point)
            . ' --token-secret ' . (string) ($point['token_secret'] ?? '')
            . ' --agent-token ' . (string) ($point['agent_token'] ?? '')
            . ' --service-name ' . $serviceName;

        $totalSpeedLimitMbps = (int) ($point['total_speed_limit_mbps'] ?? 0);
        if ($totalSpeedLimitMbps > 0) {
            $command .= ' --total-speed-limit-mbps ' . $totalSpeedLimitMbps;
        }

        return $command;
    }

    /**
     * @param array<string, mixed> $point
     */
    protected function bondSidecarUninstallCommand(array $point): string
    {
        return 'curl -fsSL ' . $this->artifactBaseUrl() . '/artifacts/uninstall-bond-sidecar.sh | bash -s -- --service-name '
            . $this->sidecarServiceName((int) ($point['id'] ?? 0));
    }

    /**
     * @param array<string, mixed> $client
     */
    protected function clientAgentInstallCommand(array $client): string
    {
        return 'curl -fsSL ' . $this->artifactBaseUrl() . '/artifacts/install-client-agent.sh | bash -s -- --master '
            . $this->agentBaseUrl()
            . ' --client-id ' . (int) ($client['id'] ?? 0)
            . ' --agent-token ' . (string) ($client['agent_token'] ?? '');
    }

    /**
     * @param array<string, mixed> $client
     */
    protected function clientAgentAndroidImportCommand(array $client): string
    {
        return 'leikwan-android://install?master_url='
            . rawurlencode($this->agentBaseUrl())
            . '&client_id=' . (int) ($client['id'] ?? 0)
            . '&agent_token=' . rawurlencode((string) ($client['agent_token'] ?? ''));
    }

    protected function androidClientApkUrl(): string
    {
        return $this->artifactBaseUrl() . '/artifacts/leikwan-android-client.apk';
    }

    protected function windowsClientAppUrl(): string
    {
        return $this->artifactBaseUrl() . '/artifacts/leikwan-bridge-setup.exe';
    }

    protected function clientWindowsInstallerUrl(): string
    {
        return $this->artifactBaseUrl() . '/artifacts/install-client.ps1';
    }

    protected function clientWindowsManagerUrl(): string
    {
        return $this->artifactBaseUrl() . '/artifacts/manage-client.ps1';
    }

    protected function clientWindowsManagerUrlForClient(array $client): string
    {
        $clientId = (int) ($client['id'] ?? 0);
        $agentToken = trim((string) ($client['agent_token'] ?? ''));
        if ($clientId <= 0 || $agentToken === '') {
            return $this->clientWindowsManagerUrl();
        }

        return $this->artifactBaseUrl() . '/artifacts/manage-client.ps1?' . http_build_query([
            'client_id' => $clientId,
            'agent_token' => $agentToken,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    protected function clientWindowsManagerCommand(array $client): string
    {
        return '$tmp = "$env:TEMP\\manage-client.ps1"; Invoke-WebRequest -UseBasicParsing "'
            . $this->clientWindowsManagerUrlForClient($client)
            . '" -OutFile $tmp; powershell -ExecutionPolicy Bypass -File $tmp';
    }

    protected function clientForwardWindowsInstallerUrl(int $forwardId, string $token): string
    {
        return $this->artifactBaseUrl() . '/artifacts/install-client.ps1?' . http_build_query([
            'forward_id' => $forwardId,
            'token' => $token,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    protected function clientForwardWindowsInstallCommand(int $forwardId, string $token): string
    {
        $url = $this->clientForwardWindowsInstallerUrl($forwardId, $token);
        return '$tmp = "$env:TEMP\\install-client-forward-' . $forwardId . '.ps1"; Invoke-WebRequest -UseBasicParsing "'
            . $url
            . '" -OutFile $tmp; powershell -ExecutionPolicy Bypass -File $tmp';
    }

    protected function clientAgentUninstallCommand(string $configDir = '/etc/leikwan-client-agent', bool $purge = true): string
    {
        return 'curl -fsSL ' . $this->artifactBaseUrl() . '/artifacts/uninstall-client-agent.sh | bash -s -- --config-dir '
            . $configDir
            . ($purge ? ' --purge' : '');
    }

    protected function clientAgentInstallerUrl(): string
    {
        return $this->artifactBaseUrl() . '/artifacts/install-client-agent.sh';
    }

    protected function clientAgentUninstallerUrl(): string
    {
        return $this->artifactBaseUrl() . '/artifacts/uninstall-client-agent.sh';
    }

    protected function renderCommandLink(string $label, string $href, string $variant = 'ghost'): string
    {
        $class = 'button';
        if ($variant !== '') {
            $class .= ' ' . $variant;
        }

        return '<a class="' . $this->escape($class) . '" href="' . $this->escape($href) . '" target="_blank" rel="noopener">'
            . $this->escape($label)
            . '</a>';
    }

    protected function renderCopyButton(string $label, string $text, string $variant = 'secondary'): string
    {
        $class = 'button';
        if ($variant !== '') {
            $class .= ' ' . $variant;
        }

        return '<button type="button" class="' . $this->escape($class) . '" data-copy-text="' . $this->escape($text) . '" data-copy-success="Copied" data-copy-error="Copy failed">'
            . $this->escape($label)
            . '</button>';
    }

    /**
     * @param array<int, string> $actions
     */
    protected function renderCommandPanel(string $title, string $command, array $actions = [], string $description = ''): string
    {
        $actionsHtml = $actions !== []
            ? '<div class="actions command-card-actions">' . implode('', $actions) . '</div>'
            : '';
        $descriptionHtml = $description !== ''
            ? '<div class="muted command-card-note">' . $this->escape($description) . '</div>'
            : '';

        return '<section class="command-card">'
            . '<div class="command-card-header">'
            . '<div><div class="command-card-title">' . $this->escape($title) . '</div>' . $descriptionHtml . '</div>'
            . $actionsHtml
            . '</div>'
            . '<pre class="command-pre">' . $this->escape($command) . '</pre>'
            . '</section>';
    }

    /**
     * @param array<string, mixed> $client
     */
    protected function renderAssignedForwardSummary(array $forwardInstalls): string
    {
        if ($forwardInstalls === []) {
            return '<details style="margin-top:14px;">'
                . '<summary>Assigned Forwards</summary>'
                . '<div class="muted" style="margin-top:10px;">No forwards are currently assigned to this client.</div>'
                . '<div class="muted" style="margin-top:10px;">Refresh this page to see the current panel view. The Windows Forward Selector script also pulls the latest assigned forward list every time you run it.</div>'
                . '</details>';
        }

        $items = '';
        foreach ($forwardInstalls as $forwardInstall) {
            if (!is_array($forwardInstall)) {
                continue;
            }
            $forwardId = (int) ($forwardInstall['forward_id'] ?? 0);
            if ($forwardId <= 0) {
                continue;
            }
            $serviceName = trim((string) ($forwardInstall['service_name'] ?? ''));
            $listenPort = (int) ($forwardInstall['listen_port'] ?? 0);
            $target = trim((string) ($forwardInstall['target'] ?? ''));

            $line = 'Forward #' . $forwardId;
            if ($serviceName !== '') {
                $line .= ' | service ' . $serviceName;
            }
            if ($listenPort > 0) {
                $line .= ' | listen ' . $listenPort;
            }
            if ($target !== '') {
                $line .= ' | target ' . $target;
            }
            $items .= '<li>' . $this->escape($line) . '</li>';
        }

        if ($items === '') {
            $items = '<li class="muted">No forwards are currently assigned to this client.</li>';
        }

        return '<details style="margin-top:14px;">'
            . '<summary>Assigned Forwards</summary>'
            . '<ul class="list" style="margin-top:10px;">' . $items . '</ul>'
            . '<div class="muted" style="margin-top:10px;">Refresh this page to see the current panel view. The Windows Forward Selector script also pulls the latest assigned forward list every time you run it.</div>'
            . '</details>';
    }

    /**
     * @param array<string, mixed>|null $client
     */
    protected function renderClientAppDownloadOverview(?array $client = null): string
    {
        $windowsAppUrl = $this->windowsClientAppUrl();
        $androidApkUrl = $this->androidClientApkUrl();
        $clientId = (int) ($client['id'] ?? 0);
        $agentToken = trim((string) ($client['agent_token'] ?? ''));
        $hasClient = $clientId > 0 && $agentToken !== '';
        $linuxInstall = $hasClient
            ? $this->clientAgentInstallCommand($client)
            : 'Create a client first, then copy the exact Linux install command for that client.';
        $linuxActions = $hasClient
            ? [
                $this->renderCommandLink('Open Install Link', $this->clientAgentInstallerUrl()),
                $this->renderCopyButton('Copy Install Command', $linuxInstall),
            ]
            : [
                $this->renderCommandLink('Open Install Link', $this->clientAgentInstallerUrl()),
            ];
        $linuxDescription = $hasClient
            ? 'Example shown for the current client. Each client card below includes its own exact install details and Linux install command.'
            : 'Windows and Android use the app install flow. Linux remains a local/manual install on the host that will run the client-agent.';

        return '<section class="card" style="margin-bottom:20px;">'
            . '<h3>App Download</h3>'
            . '<p class="muted">Windows and Android use the app install flow. Linux remains a local/manual install command for the host that runs the client-agent.</p>'
            . $this->renderCommandPanel(
                'Windows App',
                $windowsAppUrl,
                [
                    $this->renderCommandLink('Download Windows App', $windowsAppUrl),
                    $this->renderCopyButton('Copy Download Link', $windowsAppUrl),
                ],
                'Download the LeiKwan Bridge desktop app for this client.'
            )
            . $this->renderCommandPanel(
                'Android App',
                $androidApkUrl,
                [
                    $this->renderCommandLink('Download Android App', $androidApkUrl),
                    $this->renderCopyButton('Copy Download Link', $androidApkUrl),
                ],
                'Download the Android app for this client or use the Android import string from a client card below.'
            )
            . $this->renderCommandPanel(
                'Linux Install',
                $linuxInstall,
                $linuxActions,
                $linuxDescription
            )
            . '</section>';
    }

    protected function renderClientAgentInstallDetails(array $client, array $forwardInstalls = []): string
    {
        $installCommand = $this->clientAgentInstallCommand($client);
        $windowsInstallerUrl = $this->clientWindowsInstallerUrl();
        $windowsManagerCommand = $this->clientWindowsManagerCommand($client);
        $windowsManagerUrl = $this->clientWindowsManagerUrlForClient($client);
        $androidImportCommand = $this->clientAgentAndroidImportCommand($client);
        $androidApkUrl = $this->androidClientApkUrl();
        $windowsAppUrl = $this->windowsClientAppUrl();
        $uninstallCommand = $this->clientAgentUninstallCommand();
        $clientId = (int) ($client['id'] ?? 0);
        $agentToken = trim((string) ($client['agent_token'] ?? ''));
        $windowsInstallPanels = $this->renderCommandPanel(
            'Windows Forward Selector',
            $windowsManagerCommand,
            [
                $this->renderCommandLink('Open Install Link', $windowsManagerUrl),
                $this->renderCopyButton('Copy Install Command', $windowsManagerCommand),
            ],
            'Secondary tool for hosts that still use the Windows PowerShell manager to pick and control local forward tasks.'
        );
        if ($windowsManagerUrl === $this->clientWindowsManagerUrl()) {
            $windowsInstallPanels .= $this->renderCommandPanel(
                'Windows PowerShell (Admin)',
                $windowsInstallerUrl,
                [
                    $this->renderCommandLink('Open Install Link', $windowsInstallerUrl),
                    $this->renderCopyButton('Copy Install Link', $windowsInstallerUrl),
                ],
                'Legacy bootstrap script. Use the Windows app above as the primary install path.'
            );
        }

        return '<section class="card" style="margin-top:14px;">'
            . '<h4 style="margin:0 0 8px;">App Download</h4>'
            . '<div class="muted command-lead">Windows and Android use the app install flow. Linux remains available as a local/manual install command for this client.</div>'
            . $this->renderCommandPanel(
                'Windows App',
                $windowsAppUrl,
                [
                    $this->renderCommandLink('Download Windows App', $windowsAppUrl),
                    $this->renderCopyButton('Copy Download Link', $windowsAppUrl),
                ],
                'Install the LeiKwan Bridge desktop app for this client.'
            )
            . $this->renderCommandPanel(
                'Android App',
                $androidApkUrl,
                [
                    $this->renderCommandLink('Download Android App', $androidApkUrl),
                    $this->renderCopyButton('Copy Download Link', $androidApkUrl),
                ],
                'Install the Android app for this client.'
            )
            . $this->renderCommandPanel(
                'Linux Install',
                $installCommand,
                [
                    $this->renderCommandLink('Open Install Link', $this->clientAgentInstallerUrl()),
                    $this->renderCopyButton('Copy Install Command', $installCommand),
                ],
                'Manual/local install for this client on the Linux host that will run the client-agent.'
            )
            . '<details style="margin-top:14px;">'
            . '<summary>More install tools</summary>'
            . '<div class="muted" style="margin:10px 0 12px;">These secondary tools stay available for local management and compatibility flows, but the primary install story is now app download or Linux install.</div>'
            . $windowsInstallPanels
            . $this->renderCommandPanel(
                'Android Import String',
                $androidImportCommand,
                [
                    $this->renderCopyButton('Copy Android Import', $androidImportCommand),
                ],
                'Optional helper to prefill the Android app with panel URL and this client token.'
            )
            . $this->renderCommandPanel(
                'Uninstall',
                $uninstallCommand,
                [
                    $this->renderCommandLink('Open Uninstall Link', $this->clientAgentUninstallerUrl()),
                    $this->renderCopyButton('Copy Uninstall Command', $uninstallCommand),
                ],
                'Manual uninstall command for the Linux client-agent host.'
            )
            . '</details>'
            . '</section>';
    }

    /**
     * @return array<int, mixed>
     */
    protected function decodeJsonList(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeJsonAssoc(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $client
     */
    protected function renderClientDiagnosticsHtml(array $client): string
    {
        $diagPayload = $this->decodeJsonList($client['last_diag_result_json'] ?? null);
        $diagItems = '';
        foreach ($diagPayload as $result) {
            if (!is_array($result)) {
                continue;
            }
            $diagItems .= '<div style="margin-bottom:12px;"><strong>Forward #' . (int) ($result['forward_id'] ?? 0) . '</strong><br>';
            $diagItems .= '<span class="muted">Mode:</span> ' . $this->escape((string) ($result['endpoint_mode'] ?? '')) . '<br>';
            $diagItems .= '<span class="muted">Local service:</span> ' . (!empty($result['local_service_active']) ? 'active' : 'inactive') . '<br>';

            $diagItems .= '<div style="margin-top:8px;"><strong>CLIENT -> SLAVE</strong><br>';
            foreach (($result['endpoint_results'] ?? []) as $endpointResult) {
                if (!is_array($endpointResult)) {
                    continue;
                }
                $diagItems .= $this->escape((string) ($endpointResult['host'] ?? '')) . ':' . $this->escape((string) ($endpointResult['port'] ?? '')) . ' -> '
                    . (!empty($endpointResult['reachable']) ? 'reachable' : 'failed')
                    . (($endpointResult['latency_ms'] ?? null) !== null ? ' (' . $this->escape((string) $endpointResult['latency_ms']) . ' ms)' : '')
                    . (!empty($endpointResult['error']) ? ' [' . $this->escape((string) $endpointResult['error']) . ']' : '')
                    . '<br>';
            }
            $diagItems .= '</div>';

            $endToEnd = is_array($result['end_to_end_result'] ?? null) ? $result['end_to_end_result'] : [];
            $diagItems .= '<div style="margin-top:8px;"><strong>CLIENT -> SLAVE -> ENDPOINT</strong><br>';
            $diagItems .= (!empty($endToEnd['reachable']) ? 'reachable' : 'failed')
                . (($endToEnd['latency_ms'] ?? null) !== null ? ' (' . $this->escape((string) $endToEnd['latency_ms']) . ' ms)' : '')
                . (!empty($endToEnd['error']) ? ' [' . $this->escape((string) $endToEnd['error']) . ']' : '')
                . '</div>';
            $diagItems .= '</div>';
        }

        if ($diagItems === '') {
            $diagItems = '<div class="muted">No diagnostic result yet.</div>';
        }

        return $diagItems;
    }

    /**
     * @param array<string, mixed> $client
     */
    protected function renderTenantClientDiagnosticsDialog(array $client, string $dialogId): string
    {
        $diagStatus = strtolower(trim((string) ($client['last_diag_status'] ?? '')));
        $diagLabel = $diagStatus !== '' ? $diagStatus : 'not run';
        $diagBadgeClass = match ($diagStatus) {
            'ok' => 'success',
            'error' => 'danger',
            'requested' => 'info',
            default => 'info',
        };
        $lastRun = trim((string) ($client['last_diag_run_at'] ?? ''));
        $diagError = trim((string) ($client['last_diag_error'] ?? ''));
        $diagPayload = $this->decodeJsonList($client['last_diag_result_json'] ?? null);
        $reputationRows = is_array($client['bond_leg_reputation_rows'] ?? null) ? $client['bond_leg_reputation_rows'] : [];
        $reputationEvents = is_array($client['bond_leg_reputation_events'] ?? null) ? $client['bond_leg_reputation_events'] : [];
        $dialogTitleId = $dialogId . '-title';
        $content = '';

        foreach ($diagPayload as $result) {
            if (!is_array($result)) {
                continue;
            }

            $forwardId = (int) ($result['forward_id'] ?? 0);
            $routeMode = trim((string) (($result['mode'] ?? $result['endpoint_mode'] ?? 'single')));
            $localServiceActive = !empty($result['local_service_active']);
            $localBadgeClass = $localServiceActive ? 'success' : 'danger';
            $localLabel = $localServiceActive ? 'ready' : 'inactive';
            $endpointResults = is_array($result['endpoint_results'] ?? null) ? $result['endpoint_results'] : [];
            $legItems = '';
            $legCount = 0;
            $healthyLegCount = 0;

            foreach ($endpointResults as $index => $endpointResult) {
                if (!is_array($endpointResult)) {
                    continue;
                }

                $legCount++;
                $reachable = !empty($endpointResult['reachable']);
                if ($reachable) {
                    $healthyLegCount++;
                }

                $legBadgeClass = $reachable ? 'success' : 'danger';
                $legLabel = $reachable ? 'connected' : 'failed';
                $latency = ($endpointResult['latency_ms'] ?? null) !== null
                    ? $this->escape((string) $endpointResult['latency_ms']) . ' ms'
                    : 'not measured';
                $legHint = $reachable ? 'Connection probe looks healthy.' : 'Connection probe failed.';

                $legItems .= '<div class="diag-leg">'
                    . '<div class="diag-forward-title">'
                    . '<strong>Leg ' . ($index + 1) . '</strong>'
                    . '<span class="badge ' . $legBadgeClass . '">' . $this->escape($legLabel) . '</span>'
                    . '</div>'
                    . '<div class="diag-kv"><span class="muted">Latency</span><span>' . $latency . '</span></div>'
                    . '<div class="muted" style="margin-top:8px;">' . $this->escape($legHint) . '</div>'
                    . '</div>';
            }

            if ($legItems === '') {
                $legItems = '<div class="muted">No leg result was reported yet.</div>';
            }

            $endToEnd = is_array($result['end_to_end_result'] ?? null) ? $result['end_to_end_result'] : [];
            $tunnelReachable = !empty($endToEnd['reachable']);
            $tunnelBadgeClass = $tunnelReachable ? 'success' : 'danger';
            $tunnelLabel = $tunnelReachable ? 'established' : 'not established';
            $tunnelLatency = ($endToEnd['latency_ms'] ?? null) !== null
                ? $this->escape((string) $endToEnd['latency_ms']) . ' ms'
                : 'not measured';
            $tunnelHint = $tunnelReachable ? 'The client tunnel can be reached end-to-end.' : 'The end-to-end tunnel check failed.';
            $singleLegWarning = '';
            if (in_array(strtolower($routeMode), ['bond', 'mptcp'], true) && $legCount <= 1) {
                $singleLegWarning = '<div class="flash danger" style="margin:0 0 14px;">Only one active leg was reported for this multi-path forward. Bandwidth aggregation cannot happen until another leg joins successfully.</div>';
            }

            $content .= '<section class="diag-forward">'
                . '<div class="diag-forward-title">'
                . '<strong>Forward #' . $forwardId . '</strong>'
                . '<span class="badge info">' . $this->escape($routeMode !== '' ? $routeMode : 'single') . '</span>'
                . '</div>'
                . $singleLegWarning
                . '<div class="diag-pill-row">'
                . '<span class="badge ' . $localBadgeClass . '">Client listener ' . $this->escape($localLabel) . '</span>'
                . '<span class="badge info">Healthy legs ' . $healthyLegCount . '/' . $legCount . '</span>'
                . '<span class="badge ' . $tunnelBadgeClass . '">Tunnel ' . $this->escape($tunnelLabel) . '</span>'
                . '</div>'
                . '<div class="diag-leg-list">' . $legItems . '</div>'
                . '<div class="diag-tunnel">'
                . '<div class="diag-forward-title">'
                . '<strong>Bond Tunnel</strong>'
                . '<span class="badge ' . $tunnelBadgeClass . '">' . $this->escape($tunnelLabel) . '</span>'
                . '</div>'
                . '<div class="diag-kv"><span class="muted">Latency</span><span>' . $tunnelLatency . '</span></div>'
                . '<div class="muted" style="margin-top:8px;">' . $this->escape($tunnelHint) . '</div>'
                . '</div>'
                . '</section>';
        }

        if ($content === '') {
            $content = '<div class="muted">No diagnostic result yet. Click <strong>Run Diagnostics</strong>, wait for the client-agent to report back, then reopen this window.</div>';
        }

        $errorHtml = $diagError !== ''
            ? '<div class="flash danger" style="margin:0 0 16px;">' . $this->escape($diagError) . '</div>'
            : '';
        $reputationRowsHtml = '';
        foreach ($reputationRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $reputationRowsHtml .= '<tr>'
                . '<td>' . (int) ($row['forward_id'] ?? 0) . '</td>'
                . '<td>' . $this->escape((string) ($row['protocol'] ?? '')) . '</td>'
                . '<td>' . $this->escape((string) ($row['host'] ?? '')) . '</td>'
                . '<td>' . $this->escape((string) ($row['state'] ?? '')) . '</td>'
                . '<td>' . $this->escape((string) ($row['effective_weight'] ?? '0')) . '</td>'
                . '<td>' . $this->escape((string) ($row['updated_at'] ?? '')) . '</td>'
                . '</tr>';
        }
        if ($reputationRowsHtml === '') {
            $reputationRowsHtml = '<tr><td colspan="6" class="muted">No reputation data yet for this client.</td></tr>';
        }
        $reputationEventsHtml = '';
        foreach ($reputationEvents as $event) {
            if (!is_array($event)) {
                continue;
            }
            $reputationEventsHtml .= '<tr>'
                . '<td>' . (int) ($event['forward_id'] ?? 0) . '</td>'
                . '<td>' . $this->escape((string) ($event['protocol'] ?? '')) . '</td>'
                . '<td>' . $this->escape((string) ($event['from_state'] ?? '')) . '</td>'
                . '<td>' . $this->escape((string) ($event['to_state'] ?? '')) . '</td>'
                . '<td>' . $this->escape((string) ($event['reason'] ?? '')) . '</td>'
                . '<td>' . $this->escape((string) ($event['observed_at'] ?? '')) . '</td>'
                . '</tr>';
        }
        if ($reputationEventsHtml === '') {
            $reputationEventsHtml = '<tr><td colspan="6" class="muted">No recent reputation changes.</td></tr>';
        }
        $reputationSection = '<section class="diag-forward">'
            . '<div class="diag-forward-title"><strong>Leg Reputation</strong><span class="badge info">Reference only</span></div>'
            . '<div class="muted" style="margin:8px 0 14px;">Reference only. Runtime already adapts automatically when a leg degrades. You usually do not need to take action unless the line itself is failing.</div>'
            . '<div class="table-wrap" style="margin-bottom:14px;"><table><thead><tr><th>Forward</th><th>Protocol</th><th>Host</th><th>State</th><th>Weight</th><th>Updated</th></tr></thead><tbody>' . $reputationRowsHtml . '</tbody></table></div>'
            . '<div class="table-wrap"><table><thead><tr><th>Forward</th><th>Protocol</th><th>From</th><th>To</th><th>Reason</th><th>Observed</th></tr></thead><tbody>' . $reputationEventsHtml . '</tbody></table></div>'
            . '</section>';

        return '<dialog id="' . $this->escape($dialogId) . '" class="diag-dialog" aria-labelledby="' . $this->escape($dialogTitleId) . '">'
            . '<div class="diag-dialog-shell">'
            . '<div class="diag-dialog-header">'
            . '<div>'
            . '<h3 id="' . $this->escape($dialogTitleId) . '" style="margin-bottom:8px;">Online Diagnostics</h3>'
            . '<div class="muted">This view only shows whether each bond leg is healthy and whether the tunnel is established. Slave addresses stay hidden.</div>'
            . '</div>'
            . '<form method="dialog"><button type="submit" class="button ghost">Close</button></form>'
            . '</div>'
            . '<div class="diag-pill-row" style="margin-bottom:16px;">'
            . '<span class="badge ' . $diagBadgeClass . '">Status ' . $this->escape($diagLabel) . '</span>'
            . '<span class="badge info">Last Run ' . $this->escape($lastRun !== '' ? $lastRun : 'never') . '</span>'
            . '</div>'
            . $errorHtml
            . '<div class="diag-stack">' . $content . $reputationSection . '</div>'
            . '<div class="diag-actions"><form method="dialog"><button type="submit" class="button secondary">Close</button></form></div>'
            . '</div>'
            . '</dialog>';
    }

    /**
     * @param array<string, mixed> $usage
     */
    protected function renderUsageOverviewPanel(array $usage, string $title = 'Usage Overview'): string
    {
        $monthStartRaw = trim((string) ($usage['month_start'] ?? ''));
        $todayRaw = trim((string) ($usage['today'] ?? ''));
        $monthStart = DateTimeImmutable::createFromFormat('Y-m-d', $monthStartRaw, new DateTimeZone('UTC'))
            ?: new DateTimeImmutable('first day of this month', new DateTimeZone('UTC'));
        $today = DateTimeImmutable::createFromFormat('Y-m-d', $todayRaw, new DateTimeZone('UTC'))
            ?: new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $dailyBytes = is_array($usage['daily_bytes'] ?? null) ? $usage['daily_bytes'] : [];

        $metricHtml = ''
            . '<div class="usage-metric"><span class="muted">This Hour</span><strong>' . $this->escape($this->formatUsageBytes((int) ($usage['hour_bytes'] ?? 0))) . '</strong></div>'
            . '<div class="usage-metric"><span class="muted">Today</span><strong>' . $this->escape($this->formatUsageBytes((int) ($usage['day_bytes'] ?? 0))) . '</strong></div>'
            . '<div class="usage-metric"><span class="muted">This Month</span><strong>' . $this->escape($this->formatUsageBytes((int) ($usage['month_bytes'] ?? 0))) . '</strong></div>';

        $weekdayHtml = '';
        foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $label) {
            $weekdayHtml .= '<span>' . $this->escape($label) . '</span>';
        }

        $dayCells = '';
        $leadingEmpty = max(0, (int) $monthStart->format('N') - 1);
        for ($index = 0; $index < $leadingEmpty; $index++) {
            $dayCells .= '<div class="usage-day is-empty"></div>';
        }

        $daysInMonth = (int) $monthStart->format('t');
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $date = $monthStart->setDate((int) $monthStart->format('Y'), (int) $monthStart->format('m'), $day);
            $dateKey = $date->format('Y-m-d');
            $bytes = max(0, (int) ($dailyBytes[$dateKey] ?? 0));
            $classes = ['usage-day'];
            if ($bytes > 0) {
                $classes[] = 'has-usage';
            }
            if ($dateKey === $today->format('Y-m-d')) {
                $classes[] = 'is-today';
            }
            $dayCells .= '<div class="' . implode(' ', $classes) . '">'
                . '<span class="usage-day-num">' . $day . '</span>'
                . '<span class="usage-day-total">' . $this->escape($bytes > 0 ? $this->formatUsageBytesCompact($bytes) : '-') . '</span>'
                . '</div>';
        }

        return '<section class="usage-panel">'
            . '<div class="usage-panel-header">'
            . '<strong>' . $this->escape($title) . '</strong>'
            . '<span class="muted">UTC · ' . $this->escape((string) ($usage['month_label'] ?? $monthStart->format('F Y'))) . '</span>'
            . '</div>'
            . '<div class="usage-metrics">' . $metricHtml . '</div>'
            . '<div class="usage-calendar">'
            . '<div class="usage-weekdays">' . $weekdayHtml . '</div>'
            . '<div class="usage-grid">' . $dayCells . '</div>'
            . '</div>'
            . '</section>';
    }

    protected function formatUsageBytes(int $bytes): string
    {
        $bytes = max(0, $bytes);
        if ($bytes >= 1024 * 1024 * 1024) {
            return number_format($bytes / 1024 / 1024 / 1024, 2) . ' GB';
        }
        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / 1024 / 1024, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        return (string) $bytes . ' B';
    }

    protected function formatUsageBytesCompact(int $bytes): string
    {
        $bytes = max(0, $bytes);
        if ($bytes >= 1024 * 1024 * 1024) {
            return number_format($bytes / 1024 / 1024 / 1024, 1) . 'G';
        }
        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / 1024 / 1024, 1) . 'M';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . 'K';
        }
        return (string) $bytes . 'B';
    }

    protected function artifactVersion(string $key): string
    {
        $entry = $this->artifactMetadata($key);
        return is_array($entry) ? (string) ($entry['version'] ?? '') : '';
    }

    /**
     * @return array<string, mixed>
     */
    protected function artifactMetadata(string $key): array
    {
        static $cache = null;
        if (!is_array($cache)) {
            $path = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'artifacts' . DIRECTORY_SEPARATOR . 'versions.json';
            if (is_file($path)) {
                $decoded = json_decode((string) file_get_contents($path), true);
                $cache = is_array($decoded) ? $decoded : [];
            } else {
                $cache = [];
            }
        }

        $entry = $cache[$key] ?? null;
        if (!is_array($entry)) {
            return [];
        }

        $normalized = $entry;
        if (isset($normalized['path']) && is_string($normalized['path']) && $normalized['path'] !== '') {
            $absolute = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized['path']);
            if (is_file($absolute)) {
                $normalized['sha256'] = hash_file('sha256', $absolute);
                $normalized['size_bytes'] = filesize($absolute);
            }
        }

        return $normalized;
    }

    protected function autoUpdateTokenForVersion(string $currentVersion, string $artifactKey, string $prefix = 'auto-release'): string
    {
        $targetVersion = trim((string) ($this->artifactMetadata($artifactKey)['version'] ?? ''));
        $currentVersion = trim($currentVersion);
        if ($targetVersion === '' || $currentVersion === $targetVersion) {
            return '';
        }

        return $prefix . ':version=' . $targetVersion;
    }

    protected function autoUpdateTokenForSha256(string $currentSha256, string $artifactKey, string $prefix = 'auto-release'): string
    {
        $metadata = $this->artifactMetadata($artifactKey);
        $targetSha256 = strtolower(trim((string) ($metadata['binary_sha256'] ?? $metadata['sha256'] ?? '')));
        $currentSha256 = strtolower(trim($currentSha256));
        if ($targetSha256 === '' || $currentSha256 === $targetSha256) {
            return '';
        }

        return $prefix . ':sha256=' . $targetSha256;
    }

    protected function appName(): string
    {
        return (string) ($this->config->get('APP_NAME', '利群會聚 LeiKwan Bridge') ?? '利群會聚 LeiKwan Bridge');
    }

    protected function brandName(): string
    {
        return (string) ($this->config->get('BRAND_NAME', '利群會聚 LeiKwan Bridge') ?? '利群會聚 LeiKwan Bridge');
    }

    protected function brandLogoUrl(): string
    {
        return (string) ($this->config->get('BRAND_LOGO_URL', 'https://buy.leikwanhost.com/assets/img/logo.png') ?? 'https://buy.leikwanhost.com/assets/img/logo.png');
    }

    protected function displayBrandName(): string
    {
        return (string) ($this->config->get('DISPLAY_BRAND_NAME', '利群會聚 LeiKwan Bridge') ?? '利群會聚 LeiKwan Bridge');
    }

    /**
     * @param array<string, mixed> $user
     */
    protected function dashboardPathForUser(array $user): string
    {
        return (string) ($user['role'] ?? '') === 'admin' ? '/admin' : '/tenant';
    }

    /**
     * @param array<string, mixed> $user
     */
    protected function dashboardLabelForUser(array $user): string
    {
        return (string) ($user['role'] ?? '') === 'admin' ? 'Admin Console' : 'Tenant Panel';
    }

    /**
     * @return array<int, array{href:string,label:string,active?:bool}>
     */
    protected function adminNavigationItems(string $active = ''): array
    {
        return [
            ['href' => '/admin', 'label' => 'Overview', 'active' => $active === 'overview'],
            ['href' => '/admin/tenants', 'label' => 'Tenants', 'active' => $active === 'tenants'],
            ['href' => '/admin/registration', 'label' => 'Registration Codes', 'active' => $active === 'registration'],
            ['href' => '/admin/services', 'label' => 'Services', 'active' => $active === 'services'],
            ['href' => '/admin/plans', 'label' => 'Plans', 'active' => $active === 'plans'],
            ['href' => '/admin/client-groups', 'label' => 'Client Groups', 'active' => $active === 'client-groups'],
            ['href' => '/admin/credentials', 'label' => 'Credentials', 'active' => $active === 'credentials'],
            ['href' => '/admin/clients', 'label' => 'Clients', 'active' => $active === 'clients'],
            ['href' => '/admin/sidecars', 'label' => 'Sidecar Points', 'active' => $active === 'sidecars'],
            ['href' => '/admin/cf-location-groups', 'label' => 'CF Location Groups', 'active' => $active === 'cf-location-groups'],
            ['href' => '/admin/forward-combinations', 'label' => 'Forward Combinations', 'active' => $active === 'forward-combinations'],
            ['href' => '/knowledgebase', 'label' => 'Knowledgebase', 'active' => $active === 'knowledgebase'],
            ['href' => '/admin/node-groups', 'label' => 'Slave Groups', 'active' => $active === 'node-groups'],
            ['href' => '/admin/chains', 'label' => 'Chains', 'active' => $active === 'chains'],
            ['href' => '/admin/nodes', 'label' => 'Slave Nodes', 'active' => $active === 'nodes'],
            ['href' => '/admin/user-groups', 'label' => 'User Groups', 'active' => $active === 'user-groups'],
            ['href' => '/tenant', 'label' => 'Tenant Area'],
        ];
    }

    protected function localeSwitcherHtml(): string
    {
        return <<<HTML
            <div class="locale-switch">
                <label for="locale-switcher" class="locale-label">Language</label>
                <select id="locale-switcher" class="locale-select">
                    <option value="en">English</option>
                    <option value="zh-Hans">Simplified Chinese</option>
                    <option value="zh-TW">Traditional Chinese</option>
                </select>
            </div>
        HTML;
    }

    protected function flashHtml(): string
    {
        $flash = SessionAuth::consumeFlash();
        if ($flash === null) {
            return '';
        }

        $flashClass = $flash['type'] === 'error' ? 'danger' : 'success';
        return '<div class="flash ' . $flashClass . '">' . $this->escape($flash['message']) . '</div>';
    }

    protected function displayThrowableMessage(\Throwable $throwable): string
    {
        if ($throwable instanceof ApiException) {
            $details = $throwable->details();
            $message = trim((string) ($details['message'] ?? ''));
            if ($message !== '') {
                return $message;
            }
        }

        return $throwable->getMessage();
    }

    protected function i18nBootstrapHtml(): string
    {
        return <<<'HTML'
  <script>
    window.LEIKWAN_I18N = {
      defaultLocale: 'en',
      supported: ['en', 'zh-TW', 'zh-Hans'],
      basePath: '/i18n'
    };
  </script>
  <script src="/i18n/patch.js"></script>
HTML;
    }

    protected function publicPage(string $title, string $body, ?array $viewer = null): Response
    {
        $escapedTitle = $this->escape($title);
        $appName = $this->appName();
        $escapedAppName = $this->escape($appName);
        $escapedBrandName = $this->escape($this->displayBrandName());
        $brandLogoUrl = $this->escape($this->brandLogoUrl());
        $flashHtml = $this->flashHtml();
        $languageSwitcher = $this->localeSwitcherHtml();
        $csrfToken = $this->escape(SessionAuth::csrfToken());

        $actionsHtml = '<a class="button ghost" href="/register">Create Account</a><a class="button" href="/login">Sign In</a>';
        if ($viewer !== null) {
            $consoleHref = $this->escape($this->dashboardPathForUser($viewer));
            $consoleLabel = $this->escape($this->dashboardLabelForUser($viewer));
            $viewerName = $this->escape((string) ($viewer['name'] ?? 'Workspace User'));
            $viewerRole = strtoupper($this->escape((string) ($viewer['role'] ?? 'user')));
            $actionsHtml = '
                <div class="public-session">
                    <strong>' . $viewerName . '</strong>
                    <span>' . $viewerRole . ' SESSION</span>
                </div>
                <a class="button" href="' . $consoleHref . '">' . $consoleLabel . '</a>
                <form class="inline" method="post" action="/logout">
                    <input type="hidden" name="csrf_token" value="' . $csrfToken . '">
                    <button type="submit" class="button secondary">Sign Out</button>
                </form>';
        }

        $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$escapedTitle}</title>
  <style>
{$this->publicPageStyles()}
  </style>
</head>
<body>
  <div class="public-shell">
    <header class="site-topbar">
      <a class="site-brand" href="/">
        <span class="site-mark"><img src="{$brandLogoUrl}" alt="{$escapedBrandName} logo"></span>
        <span class="site-copy">
          <strong>{$escapedBrandName}</strong>
          <span>{$escapedAppName} control plane</span>
        </span>
      </a>
      <div class="site-actions">
        {$actionsHtml}
      </div>
    </header>
    <main class="landing-stack">
      {$flashHtml}
      {$body}
    </main>
    <footer class="page-footer">
      <div class="footer-tools">
        {$languageSwitcher}
      </div>
    </footer>
  </div>
{$this->i18nBootstrapHtml()}
</body>
</html>
HTML;

        return Response::html(200, $html);
    }

    private function publicPageStyles(): string
    {
        return <<<'CSS'
    :root {
      --bg: #f7f7f3;
      --bg-accent: #efefe8;
      --surface: rgba(255,255,255,0.94);
      --text: #14161a;
      --muted: #6b7280;
      --line: #e5e7eb;
      --line-strong: #d1d5db;
      --primary: #111827;
      --shadow: 0 16px 40px rgba(15,23,42,0.07);
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      min-height: 100vh;
      font-family: "Aptos", "Segoe UI", "PingFang TC", "Microsoft JhengHei", sans-serif;
      color: var(--text);
      background:
        radial-gradient(circle at 0% 0%, rgba(255,255,255,0.9), transparent 28%),
        radial-gradient(circle at 100% 0%, rgba(255,255,255,0.75), transparent 22%),
        linear-gradient(180deg, #fbfbf8 0%, var(--bg) 50%, var(--bg-accent) 100%);
    }
    a { color: inherit; text-decoration: none; }
    button, input, select, textarea { font: inherit; }
    code {
      font-family: Consolas, "Courier New", monospace;
      font-size: 0.94em;
      padding: 2px 7px;
      border-radius: 8px;
      background: #f3f4f6;
      color: #1f2937;
    }
    pre {
      margin: 0;
      padding: 16px 18px;
      border-radius: 18px;
      overflow: auto;
      background: #111827;
      color: #f9fafb;
      font-size: 12px;
      line-height: 1.65;
    }
    .public-shell {
      max-width: 1120px;
      margin: 0 auto;
      padding: 24px 20px 56px;
    }
    .site-topbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      padding: 16px 18px;
      border-radius: 22px;
      background: rgba(255,255,255,0.84);
      border: 1px solid rgba(229,231,235,0.92);
      box-shadow: var(--shadow);
      backdrop-filter: blur(12px);
    }
    .site-brand { display: inline-flex; align-items: center; gap: 14px; min-width: 0; }
    .site-mark {
      width: 124px;
      height: 50px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 8px 12px;
      border-radius: 16px;
      background: #ffffff;
      border: 1px solid var(--line);
    }
    .site-mark img { max-width: 100%; max-height: 100%; object-fit: contain; display: block; }
    .site-copy {
      min-width: 0;
      display: flex;
      flex-direction: column;
      gap: 3px;
    }
    .site-copy strong {
      font-family: "Iowan Old Style", "Palatino Linotype", "Book Antiqua", Georgia, serif;
      font-size: 24px;
      line-height: 1;
      color: var(--text);
    }
    .site-copy span {
      color: var(--muted);
      font-size: 13px;
    }
    .site-actions,
    .hero-actions,
    .portal-actions,
    .actions {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      align-items: center;
    }
    .hero-actions,
    .portal-actions,
    .actions { margin-top: 16px; }
    .public-session,
    .locale-switch {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 8px 12px;
      border-radius: 14px;
      background: #ffffff;
      border: 1px solid var(--line);
    }
    .public-session {
      flex-direction: column;
      align-items: flex-start;
      gap: 2px;
    }
    .public-session strong { font-size: 13px; color: var(--text); }
    .public-session span {
      color: var(--muted);
      font-size: 11px;
      letter-spacing: 0.12em;
      text-transform: uppercase;
    }
    .locale-label { color: var(--muted); font-size: 12px; }
    .locale-select {
      border: 0;
      background: transparent;
      color: var(--text);
    }
    .landing-stack {
      display: flex;
      flex-direction: column;
      gap: 18px;
      margin-top: 20px;
    }
    .page-footer {
      display: flex;
      justify-content: center;
      margin-top: 18px;
      padding-bottom: 8px;
    }
    .footer-tools {
      display: flex;
      justify-content: center;
    }
    .landing-hero {
      display: grid;
      grid-template-columns: minmax(0, 1.35fr) minmax(280px, 0.75fr);
      gap: 16px;
      align-items: stretch;
    }
    .launch-hero {
      position: relative;
      display: grid;
      grid-template-columns: minmax(0, 1.02fr) minmax(340px, 0.98fr);
      gap: 18px;
      align-items: stretch;
      padding: clamp(22px, 4vw, 44px);
      min-height: 560px;
      overflow: hidden;
      border-radius: 34px;
      background:
        radial-gradient(circle at 8% 12%, rgba(239,68,68,0.18), transparent 22%),
        radial-gradient(circle at 78% 0%, rgba(34,197,94,0.18), transparent 24%),
        linear-gradient(135deg, #ffffff 0%, #f8fafc 48%, #eef2ff 100%);
      border: 1px solid rgba(17,24,39,0.10);
      box-shadow: var(--shadow);
    }
    .launch-hero::before {
      content: "";
      position: absolute;
      inset: 18px;
      pointer-events: none;
      border-radius: 26px;
      border: 1px solid rgba(17,24,39,0.08);
    }
    .launch-copy {
      position: relative;
      z-index: 1;
      display: flex;
      flex-direction: column;
      justify-content: center;
      gap: 22px;
      max-width: 720px;
    }
    .launch-kicker {
      display: inline-flex;
      align-items: center;
      width: fit-content;
      padding: 8px 12px;
      border-radius: 999px;
      background: #111827;
      color: #ffffff;
      font-size: 11px;
      font-weight: 900;
      letter-spacing: 0.18em;
      text-transform: uppercase;
    }
    .launch-copy h1 {
      margin: 0;
      color: #0b1020;
      font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      font-size: clamp(3.6rem, 8.2vw, 7.9rem);
      line-height: 0.86;
      letter-spacing: -0.08em;
      max-width: 780px;
    }
    .launch-copy p {
      max-width: 660px;
      margin: 0;
      color: #475569;
      font-size: clamp(1rem, 1.65vw, 1.25rem);
      line-height: 1.7;
    }
    .launch-primary {
      background: #111827;
      color: #ffffff;
      border-color: #111827;
      box-shadow: 0 16px 34px rgba(17,24,39,0.22);
    }
    .launch-secondary {
      background: rgba(255,255,255,0.78);
      color: #111827;
      border-color: rgba(17,24,39,0.16);
    }
    .flow-visual {
      position: relative;
      z-index: 1;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      gap: 22px;
      min-height: 430px;
      padding: clamp(20px, 3vw, 34px);
      border-radius: 30px;
      background: #0b1020;
      color: #ffffff;
      box-shadow: inset 0 0 0 1px rgba(255,255,255,0.10), 0 30px 70px rgba(15,23,42,0.28);
    }
    .flow-visual::before,
    .flow-visual::after {
      content: "";
      position: absolute;
      pointer-events: none;
      border-radius: 999px;
      filter: blur(2px);
      opacity: 0.8;
    }
    .flow-visual::before {
      width: 180px;
      height: 180px;
      right: 18px;
      top: 18px;
      background: radial-gradient(circle, rgba(56,189,248,0.38), transparent 66%);
    }
    .flow-visual::after {
      width: 220px;
      height: 220px;
      left: -60px;
      bottom: -60px;
      background: radial-gradient(circle, rgba(168,85,247,0.34), transparent 68%);
    }
    .flow-topline,
    .flow-nodes,
    .flow-pills {
      position: relative;
      z-index: 1;
    }
    .flow-topline {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 16px;
      color: #cbd5e1;
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: 0.14em;
    }
    .flow-topline strong {
      color: #ffffff;
      font-size: clamp(1.6rem, 3.4vw, 3.1rem);
      line-height: 0.9;
      letter-spacing: -0.05em;
      text-transform: none;
    }
    .flow-band {
      position: relative;
      z-index: 1;
      display: grid;
      grid-template-columns: repeat(8, minmax(18px, 1fr));
      gap: 8px;
      align-items: end;
      min-height: 150px;
      padding: 16px;
      border-radius: 24px;
      background: rgba(255,255,255,0.06);
      border: 1px solid rgba(255,255,255,0.10);
    }
    .flow-band i {
      display: block;
      min-height: 74px;
      border-radius: 16px;
      background: linear-gradient(180deg, #22c55e, #38bdf8 48%, #a855f7);
      box-shadow: 0 16px 40px rgba(56,189,248,0.18);
    }
    .flow-band i:nth-child(2n) { min-height: 116px; }
    .flow-band i:nth-child(3n) { min-height: 146px; background: linear-gradient(180deg, #f97316, #ef4444); }
    .flow-band i:nth-child(4n) { min-height: 92px; }
    .flow-nodes {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 8px;
    }
    .flow-nodes span,
    .flow-pills span {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 44px;
      padding: 10px 12px;
      border-radius: 14px;
      background: rgba(255,255,255,0.08);
      border: 1px solid rgba(255,255,255,0.12);
      color: #e5e7eb;
      font-size: 12px;
      font-weight: 800;
    }
    .flow-pills {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
    }
    .flow-pills span {
      border-radius: 999px;
      min-height: 0;
      background: rgba(255,255,255,0.12);
    }
    .proof-grid,
    .launch-entry-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 16px;
    }
    .launch-proof,
    .launch-entry {
      padding: 24px;
      border-radius: 28px;
      background: #ffffff;
      border: 1px solid rgba(17,24,39,0.10);
      box-shadow: var(--shadow);
    }
    .launch-proof {
      min-height: 250px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      gap: 18px;
    }
    .launch-proof > span,
    .launch-entry > span {
      display: inline-flex;
      width: fit-content;
      color: #ef4444;
      font-size: 11px;
      font-weight: 900;
      letter-spacing: 0.16em;
      text-transform: uppercase;
    }
    .launch-proof h3,
    .launch-entry h3 {
      margin: 0;
      color: #111827;
      font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      font-size: clamp(1.65rem, 2.5vw, 2.35rem);
      line-height: 0.98;
      letter-spacing: -0.05em;
    }
    .launch-proof p,
    .launch-entry p {
      margin: 0;
      color: #64748b;
      line-height: 1.7;
    }
    .launch-entry {
      display: flex;
      flex-direction: column;
      gap: 14px;
      min-height: 230px;
    }
    .launch-entry.accent {
      background: #111827;
      color: #ffffff;
    }
    .launch-entry.accent h3,
    .launch-entry.accent p {
      color: #ffffff;
    }
    .launch-entry.accent p { color: #cbd5e1; }

    .entry-grid,
    .portal-grid,
    .auth-grid,
    .form-grid {
      display: grid;
      gap: 16px;
    }
    .entry-grid,
    .portal-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .auth-grid { grid-template-columns: minmax(0, 1fr) 300px; align-items: start; }
    .form-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .form-grid.single { grid-template-columns: 1fr; }
    .entry-card {
      display: flex;
      flex-direction: column;
      gap: 12px;
      min-height: 210px;
    }
    .entry-card .actions { margin-top: auto; }
    .list,
    .card-list {
      margin: 0;
      padding-left: 1.15rem;
    }
    .list li,
    .card-list li {
      margin-bottom: 10px;
      line-height: 1.65;
      color: var(--muted);
    }
    .field {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }
    .field label {
      color: #4b5563;
      font-size: 12px;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }
    .field input,
    .field select,
    .field textarea {
      width: 100%;
      border: 1px solid var(--line-strong);
      border-radius: 14px;
      padding: 13px 14px;
      background: #ffffff;
      color: var(--text);
    }
    .field textarea { min-height: 120px; resize: vertical; }
    .field-note {
      margin: 0;
      color: var(--muted);
      font-size: 12px;
      line-height: 1.5;
    }
    .button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 44px;
      padding: 10px 16px;
      border: 1px solid transparent;
      border-radius: 14px;
      cursor: pointer;
      font-size: 14px;
      font-weight: 700;
      background: var(--primary);
      color: #ffffff;
    }
    .button.secondary {
      background: #f3f4f6;
      border-color: var(--line);
      color: var(--text);
    }
    .button.ghost {
      background: #ffffff;
      border-color: var(--line);
      color: var(--text);
    }
    .flash {
      padding: 14px 16px;
      border-radius: 16px;
      border: 1px solid transparent;
      font-weight: 600;
    }
    .flash.success {
      background: #eefaf2;
      border-color: #c8ead1;
      color: #166534;
    }
    .flash.danger {
      background: #fef2f2;
      border-color: #fecaca;
      color: #b91c1c;
    }
    form.inline { display: inline; }
    @media (max-width: 980px) {
      .landing-hero,
      .auth-grid,
      .entry-grid,
      .portal-grid,
      .form-grid {
        grid-template-columns: 1fr;
      }
    }
    @media (max-width: 720px) {
      .launch-hero,
      .proof-grid,
      .launch-entry-grid,
      .flow-nodes {
        grid-template-columns: 1fr;
      }
      .launch-hero {
        min-height: 0;
        padding: 18px;
      }
      .launch-copy h1 {
        font-size: clamp(3rem, 18vw, 4.6rem);
      }
      .flow-visual {
        min-height: 360px;
      }
    }
    @media (max-width: 820px) {
      .public-shell { padding-inline: 16px; }
      .site-topbar {
        flex-direction: column;
        align-items: flex-start;
      }
      .site-actions { width: 100%; }
      .site-brand { align-items: flex-start; }
      .hero-copy { min-height: 0; }
      .page-footer {
        justify-content: stretch;
      }
      .footer-tools,
      .footer-tools .locale-switch {
        width: 100%;
      }
    }
CSS;
    }

    /**
     * @param array<int, array{href:string,label:string,active?:bool}> $nav
     * @param array<string, mixed>|null $viewer
     */
    protected function page(string $title, string $body, ?array $viewer = null, array $nav = []): Response
    {
        $escapedTitle = $this->escape($title);
        $appName = $this->appName();
        $escapedAppName = $this->escape($appName);
        $escapedBrandName = $this->escape($this->displayBrandName());
        $brandLogoUrl = $this->escape($this->brandLogoUrl());
        $csrfToken = $this->escape(SessionAuth::csrfToken());
        $languageSwitcher = $this->localeSwitcherHtml();

        $flash = SessionAuth::consumeFlash();
        $flashHtml = '';
        if ($flash !== null) {
            $flashClass = $flash['type'] === 'error' ? 'danger' : 'success';
            $flashHtml = '<div class="flash ' . $flashClass . '">' . $this->escape($flash['message']) . '</div>';
        }

        $lowBalanceHtml = '';
        if ($viewer !== null && (int) ($viewer['wallet_warning_sent'] ?? 0) === 1) {
            $balance = number_format((float) ($viewer['wallet_balance'] ?? 0), 2);
            $curr = strtoupper(trim((string) ($viewer['wallet_currency'] ?? 'CNY')));
            $lowBalanceHtml = '<div class="flash danger" style="margin-bottom:18px;">Your balance is low (' . $this->escape($curr) . ' ' . $this->escape($balance) . '). <a href="/tenant/account" style="text-decoration:underline;">Top up</a> to avoid service interruption.</div>';
        }

        $navHtml = '';
        foreach ($nav as $item) {
            $class = !empty($item['active']) ? 'nav-link active' : 'nav-link';
            $navHtml .= '<a class="' . $class . '" href="' . $this->escape($item['href']) . '">' . $this->escape($item['label']) . '</a>';
        }

        $viewerHtml = '';
        if ($viewer !== null) {
            $viewerRole = strtoupper($this->escape((string) ($viewer['role'] ?? 'user')));
            $viewerHtml = '
                <div class="viewer">
                    <div class="viewer-meta">
                        <strong>' . $this->escape((string) $viewer['name']) . '</strong>
                        <span>' . $this->escape((string) $viewer['email']) . '</span>
                        <span class="viewer-role">' . $viewerRole . '</span>
                    </div>
                    <form method="post" action="/logout">
                        <input type="hidden" name="csrf_token" value="' . $csrfToken . '">
                        <button type="submit" class="button danger">Logout</button>
                    </form>
                </div>';
        }

        $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$escapedTitle}</title>
  <style>
    :root {
      --bg: #f4efe5;
      --bg-tint: #edf5ff;
      --card: rgba(255,255,255,0.92);
      --text: #132238;
      --muted: #66778f;
      --line: rgba(19,34,56,0.12);
      --primary: #0f6d86;
      --primary-deep: #0b4f67;
      --success: #1f8f52;
      --success-soft: rgba(31,143,82,0.12);
      --danger: #bc3f38;
      --danger-soft: rgba(188,63,56,0.12);
      --shadow: 0 24px 56px rgba(17, 28, 43, 0.12);
      --shadow-soft: 0 16px 34px rgba(17, 28, 43, 0.08);
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: "Aptos", "Segoe UI", "PingFang TC", "Microsoft JhengHei", sans-serif;
      background:
        radial-gradient(circle at 12% 12%, rgba(15,109,134,0.12), transparent 26%),
        radial-gradient(circle at 88% 10%, rgba(201,107,50,0.14), transparent 22%),
        linear-gradient(180deg, var(--bg-tint) 0%, var(--bg) 220px, #f7f4ee 100%);
      color: var(--text);
    }
    a { color: inherit; text-decoration: none; }
    code {
      font-family: Consolas, "Courier New", monospace;
      font-size: 0.95em;
      background: rgba(15,109,134,0.08);
      color: #0a4f67;
      padding: 2px 7px;
      border-radius: 9px;
    }
    .shell { max-width: 1440px; margin: 0 auto; padding: 28px; }
    .topbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 18px;
      background: rgba(255,255,255,0.78);
      backdrop-filter: blur(16px);
      border: 1px solid rgba(255,255,255,0.76);
      box-shadow: var(--shadow);
      border-radius: 28px;
      padding: 18px 22px;
      margin-bottom: 20px;
      position: sticky;
      top: 20px;
      z-index: 20;
    }
    .brand { display: flex; align-items: center; gap: 14px; }
    .brand-mark {
      width: 132px;
      height: 54px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 18px;
      background: rgba(255,255,255,0.96);
      box-shadow: inset 0 0 0 1px rgba(17,34,56,0.06);
      padding: 8px 12px;
    }
    .brand-mark img { max-width: 100%; max-height: 100%; object-fit: contain; display: block; }
    .brand-copy h1 {
      margin: 0;
      font-family: "Iowan Old Style", "Palatino Linotype", "Book Antiqua", Georgia, serif;
      font-size: 30px;
      line-height: 1;
    }
    .brand-copy p { margin: 5px 0 0; color: var(--muted); font-size: 13px; }
    .viewer { display: flex; align-items: center; gap: 14px; }
    .viewer-meta { text-align: right; }
    .viewer-meta span { display: block; color: var(--muted); font-size: 12px; }
    .viewer-role {
      display: inline-flex;
      margin-top: 6px;
      padding: 6px 10px;
      border-radius: 999px;
      background: rgba(15,109,134,0.1);
      color: var(--primary-deep);
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 0.12em;
      text-transform: uppercase;
    }
    .toolbar { display:flex; align-items:center; gap:12px; flex-wrap:wrap; justify-content:flex-end; }
    .locale-switch {
      display:flex;
      align-items:center;
      gap:8px;
      padding: 8px 12px;
      border-radius: 14px;
      background: rgba(248,250,252,0.92);
      border: 1px solid var(--line);
    }
    .locale-label { color: var(--muted); font-size: 12px; }
    .locale-select {
      border:0;
      border-radius:10px;
      padding:0;
      font-size:13px;
      background:transparent;
      color:var(--text);
    }
    .layout { display: grid; grid-template-columns: minmax(250px, 288px) minmax(0, 1fr); gap: 24px; }
    .page-footer {
      display:flex;
      justify-content:center;
      margin-top:20px;
      padding-bottom:8px;
    }
    .footer-tools { display:flex; justify-content:center; }
    .sidebar, .content-card, .card {
      background: var(--card);
      border-radius: 26px;
      box-shadow: var(--shadow);
      border: 1px solid rgba(255,255,255,0.76);
    }
    .sidebar {
      padding: 22px;
      height: fit-content;
      position: sticky;
      top: 112px;
    }
    .sidebar-title {
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: .16em;
      color: var(--muted);
      margin: 0 0 10px;
      font-weight: 700;
    }
    .sidebar-panel {
      padding: 16px;
      border-radius: 20px;
      background: linear-gradient(180deg, rgba(15,109,134,0.09) 0%, rgba(255,255,255,0.88) 100%);
      border: 1px solid rgba(15,109,134,0.1);
      margin-bottom: 18px;
    }
    .sidebar-panel strong { display: block; margin-bottom: 6px; font-size: 14px; }
    .sidebar-panel p { margin: 0; color: var(--muted); font-size: 13px; line-height: 1.65; }
    .nav-group { display: flex; flex-direction: column; gap: 10px; }
    .nav-link {
      display: block;
      padding: 12px 14px;
      border-radius: 16px;
      color: #29415c;
      border: 1px solid transparent;
      font-weight: 600;
    }
    .nav-link.active { background: linear-gradient(135deg, var(--primary) 0%, var(--primary-deep) 100%); color: #fff; }
    .nav-link.portal-link {
      background: rgba(201,107,50,0.08);
      border-color: rgba(201,107,50,0.16);
      color: #8d4416;
    }
    .content-card { padding: 28px; min-height: calc(100vh - 170px); }
    h2 {
      margin-top: 0;
      margin-bottom: 10px;
      font-size: clamp(2rem, 3vw, 2.75rem);
      font-family: "Iowan Old Style", "Palatino Linotype", "Book Antiqua", Georgia, serif;
      line-height: 1.06;
    }
    h3 { margin-top: 0; margin-bottom: 14px; }
    .muted { color: var(--muted); }
    .grid { display: grid; gap: 18px; }
    .grid.two { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .grid.three { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .card { padding: 22px; box-shadow: var(--shadow-soft); }
    .stats {
      font-size: clamp(2rem, 3vw, 3rem);
      font-weight: 700;
      margin-top: 10px;
      font-family: "Iowan Old Style", "Palatino Linotype", "Book Antiqua", Georgia, serif;
      line-height: 0.95;
    }
    .table-wrap {
      overflow-x: auto;
      border-radius: 20px;
      border: 1px solid var(--line);
      background: rgba(255,255,255,0.82);
    }
    table { width: 100%; border-collapse: collapse; min-width: 780px; }
    th, td { padding: 14px 16px; border-bottom: 1px solid rgba(19,34,56,0.08); text-align: left; font-size: 14px; vertical-align: top; }
    th {
      color: var(--muted);
      font-weight: 700;
      font-size: 11px;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      background: rgba(244,247,250,0.88);
    }
    .badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      border-radius: 999px;
      padding: 4px 10px;
      font-size: 12px;
      font-weight: 600;
    }
    .badge.success { background: var(--success-soft); color: var(--success); }
    .badge.danger { background: var(--danger-soft); color: var(--danger); }
    .badge.info { background: rgba(15,109,134,0.12); color: var(--primary-deep); }
    .diag-dialog {
      width: min(760px, calc(100vw - 28px));
      border: 0;
      border-radius: 26px;
      padding: 0;
      color: var(--text);
      background: rgba(255,255,255,0.98);
      box-shadow: 0 32px 72px rgba(17, 28, 43, 0.24);
    }
    .diag-dialog::backdrop {
      background: rgba(19,34,56,0.44);
      backdrop-filter: blur(4px);
    }
    .diag-dialog-shell { padding: 22px; }
    .diag-dialog-header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 14px;
      margin-bottom: 16px;
    }
    .diag-stack { display: grid; gap: 14px; }
    .diag-forward {
      padding: 16px;
      border-radius: 20px;
      border: 1px solid var(--line);
      background: rgba(248,250,252,0.84);
    }
    .diag-forward-title {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      flex-wrap: wrap;
    }
    .diag-pill-row {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
    }
    .diag-leg-list {
      display: grid;
      gap: 10px;
      margin-top: 14px;
    }
    .diag-leg,
    .diag-tunnel {
      padding: 14px;
      border-radius: 16px;
      border: 1px solid rgba(19,34,56,0.08);
      background: rgba(255,255,255,0.92);
    }
    .diag-tunnel { margin-top: 14px; }
    .diag-kv {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      flex-wrap: wrap;
      margin-top: 8px;
    }
    .diag-actions {
      display: flex;
      justify-content: flex-end;
      margin-top: 18px;
    }
    .usage-panel {
      margin-top: 14px;
      padding: 16px;
      border-radius: 20px;
      border: 1px solid var(--line);
      background: rgba(248,250,252,0.84);
    }
    .usage-panel-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      flex-wrap: wrap;
      margin-bottom: 14px;
    }
    .usage-metrics {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 10px;
      margin-bottom: 14px;
    }
    .usage-metric {
      display: flex;
      flex-direction: column;
      gap: 6px;
      padding: 12px 14px;
      border-radius: 16px;
      background: rgba(255,255,255,0.92);
      border: 1px solid rgba(19,34,56,0.08);
    }
    .usage-metric strong {
      font-size: 18px;
      line-height: 1.1;
    }
    .usage-calendar {
      padding: 12px;
      border-radius: 16px;
      background: rgba(255,255,255,0.92);
      border: 1px solid rgba(19,34,56,0.08);
    }
    .usage-weekdays,
    .usage-grid {
      display: grid;
      grid-template-columns: repeat(7, minmax(0, 1fr));
      gap: 6px;
    }
    .usage-weekdays {
      margin-bottom: 6px;
      color: var(--muted);
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }
    .usage-day {
      min-height: 72px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      gap: 8px;
      padding: 8px;
      border-radius: 12px;
      border: 1px solid rgba(19,34,56,0.08);
      background: rgba(244,247,250,0.72);
    }
    .usage-day.has-usage {
      background: rgba(15,109,134,0.10);
      border-color: rgba(15,109,134,0.18);
    }
    .usage-day.is-today {
      box-shadow: inset 0 0 0 2px rgba(201,107,50,0.34);
    }
    .usage-day.is-empty {
      min-height: 0;
      padding: 0;
      border: 0;
      background: transparent;
    }
    .usage-day-num {
      font-size: 12px;
      font-weight: 700;
    }
    .usage-day-total {
      color: var(--muted);
      font-size: 11px;
      line-height: 1.2;
      word-break: break-word;
    }
    .button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      border: 0;
      border-radius: 14px;
      padding: 10px 16px;
      font-size: 14px;
      font-weight: 700;
      cursor: pointer;
      background: linear-gradient(135deg, var(--primary) 0%, var(--primary-deep) 100%);
      color: white;
    }
    .button.secondary { background: rgba(15,109,134,0.1); color: var(--primary-deep); }
    .button.danger { background: linear-gradient(135deg, #d5544e 0%, var(--danger) 100%); }
    .button.ghost { background: rgba(255,255,255,0.78); color: var(--text); border: 1px solid var(--line); }
    .command-lead { margin: 10px 0 12px; }
    .command-card {
      margin-top: 12px;
      padding: 14px;
      border-radius: 18px;
      border: 1px solid var(--line);
      background: rgba(255,255,255,0.72);
    }
    .command-card-header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 12px;
      flex-wrap: wrap;
      margin-bottom: 10px;
    }
    .command-card-title {
      color: var(--muted);
      font-size: 12px;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }
    .command-card-note {
      margin-top: 6px;
      font-size: 12px;
      line-height: 1.6;
    }
    .command-card-actions { margin-top: 0; }
    .command-pre {
      margin: 0;
      white-space: pre-wrap;
      word-break: break-word;
    }
    form.inline { display: inline; }
    .form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
    .field { display: flex; flex-direction: column; gap: 8px; }
    .field label {
      font-size: 12px;
      color: var(--muted);
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }
    .field input, .field select, .field textarea {
      border: 1px solid var(--line);
      border-radius: 15px;
      padding: 12px 14px;
      font-size: 14px;
      background: rgba(255,255,255,0.96);
      width: 100%;
    }
    .field textarea { min-height: 140px; resize: vertical; }
    .field-note {
      margin: 0;
      color: var(--muted);
      font-size: 12px;
      line-height: 1.5;
    }
    .field-note strong { color: var(--text); }
    .value-chip {
      border: 1px solid var(--line);
      border-radius: 15px;
      padding: 12px 14px;
      background: rgba(246,248,251,0.92);
      color: var(--text);
      font-size: 14px;
      font-weight: 600;
      line-height: 1.5;
    }
    .actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 18px; }
    .flash { margin-bottom: 18px; padding: 15px 18px; border-radius: 18px; border: 1px solid transparent; font-size: 14px; font-weight: 600; }
    .flash.success { background: var(--success-soft); color: var(--success); border-color: rgba(31,143,82,0.2); }
    .flash.danger { background: var(--danger-soft); color: var(--danger); border-color: rgba(188,63,56,0.22); }
    .hero {
      display: flex;
      align-items: flex-end;
      justify-content: space-between;
      gap: 18px;
      margin-bottom: 22px;
      padding-bottom: 18px;
      border-bottom: 1px solid var(--line);
    }
    pre {
      background: #102033;
      color: #eef4fb;
      padding: 16px 18px;
      border-radius: 18px;
      overflow-x: auto;
      font-size: 12px;
      line-height: 1.65;
    }
    .stack { display: flex; flex-direction: column; gap: 18px; }
    .list { margin: 0; padding-left: 1.2rem; }
    .list li { margin-bottom: 10px; line-height: 1.65; }
    details {
      border: 1px solid var(--line);
      border-radius: 18px;
      padding: 14px 16px;
      background: rgba(250,251,252,0.88);
    }
    summary { cursor: pointer; font-weight: 700; }
    @media (max-width: 1100px) {
      .layout { grid-template-columns: 1fr; }
      .grid.two, .grid.three, .form-grid { grid-template-columns: 1fr; }
      .usage-metrics { grid-template-columns: 1fr; }
      .sidebar { position: static; }
      .topbar { position: static; top: auto; }
      .page-footer { justify-content: stretch; }
      .footer-tools,
      .footer-tools .locale-switch { width: 100%; }
      .viewer { align-items: flex-start; flex-direction: column; border-left: 0; border-top: 1px solid var(--line); padding-left: 0; padding-top: 12px; }
      .viewer-meta { text-align: left; }
      .topbar { align-items: flex-start; flex-direction: column; }
    }
  </style>
</head>
<body>
  <div class="shell">
    <div class="topbar">
      <div class="brand">
        <div class="brand-mark"><img src="{$brandLogoUrl}" alt="{$escapedBrandName} logo"></div>
        <div class="brand-copy">
          <h1>{$escapedBrandName}</h1>
          <p>{$escapedAppName} control plane for IXP ingress, compliant routing and tenant delivery</p>
        </div>
      </div>
      <div class="toolbar">
        {$viewerHtml}
      </div>
    </div>
    <div class="layout">
      <aside class="sidebar">
        <div class="sidebar-panel">
          <strong>Workspace Shell</strong>
          <p>Use the public homepage as the front door, then jump back into the console you need.</p>
        </div>
        <div class="sidebar-title">Home</div>
        <div class="nav-group" style="margin-bottom:18px;">
          <a class="nav-link portal-link" href="/">Homepage</a>
        </div>
        <div class="sidebar-title">Navigation</div>
        <div class="nav-group">
          {$navHtml}
        </div>
      </aside>
      <main class="content-card">
        {$flashHtml}
        {$lowBalanceHtml}
        {$body}
      </main>
    </div>
    <footer class="page-footer">
      <div class="footer-tools">
        {$languageSwitcher}
      </div>
    </footer>
  </div>
{$this->i18nBootstrapHtml()}
<script>
  (function () {
    function fallbackCopy(text) {
      var textarea = document.createElement('textarea');
      textarea.value = text;
      textarea.setAttribute('readonly', '');
      textarea.style.position = 'fixed';
      textarea.style.opacity = '0';
      textarea.style.pointerEvents = 'none';
      document.body.appendChild(textarea);
      textarea.focus();
      textarea.select();
      textarea.setSelectionRange(0, textarea.value.length);
      var copied = document.execCommand('copy');
      document.body.removeChild(textarea);
      if (!copied) {
        throw new Error('copy failed');
      }
    }

    document.addEventListener('click', function (event) {
      var button = event.target.closest('[data-copy-text]');
      if (!button) {
        return;
      }
      event.preventDefault();
      var text = button.getAttribute('data-copy-text') || '';
      var defaultLabel = button.getAttribute('data-copy-default') || button.textContent || 'Copy';
      var successLabel = button.getAttribute('data-copy-success') || 'Copied';
      var errorLabel = button.getAttribute('data-copy-error') || 'Copy failed';

      var done = function (label) {
        button.textContent = label;
        window.setTimeout(function () {
          button.textContent = defaultLabel;
        }, 1600);
      };

      var copyPromise;
      if (navigator.clipboard && window.isSecureContext) {
        copyPromise = navigator.clipboard.writeText(text);
      } else {
        copyPromise = Promise.resolve().then(function () {
          fallbackCopy(text);
        });
      }

      copyPromise.then(function () {
        done(successLabel);
      }).catch(function () {
        done(errorLabel);
      });
    });
  })();
</script>
</body>
</html>
HTML;

        return Response::html(200, $html);
    }
}
