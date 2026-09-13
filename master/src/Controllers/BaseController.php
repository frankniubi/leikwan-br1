<?php
declare(strict_types=1);

namespace Leikwan\Master\Controllers;

use DateTimeImmutable;
use DateTimeZone;
use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Http\Response;
use Leikwan\Master\Support\ApiException;
use Leikwan\Master\Support\LocaleResolver;
use Leikwan\Master\Support\LocaleSwitcher;
use Leikwan\Master\Support\SessionAuth;
use Leikwan\Master\Support\PrecisionGridUi;

abstract class BaseController
{
    /** @var array<string, array<string, mixed>> */
    private array $normalizedArtifactMetadataCache = [];

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

    protected function shellQuote(string $value): string
    {
        return "'" . str_replace("'", "'\"'\"'", $value) . "'";
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
        $artifactBase = rtrim($this->artifactBaseUrl(), '/');
        $masterUrl = $this->agentBaseUrl();
        $clientId = (int) ($client['id'] ?? 0);
        $agentToken = (string) ($client['agent_token'] ?? '');

        $command = 'curl -fsSL ' . $this->shellQuote($artifactBase . '/artifacts/install-client-agent.sh')
            . ' | bash -s -- --master ' . $this->shellQuote($masterUrl);
        if ($artifactBase !== $masterUrl) {
            $command .= ' --artifact-base ' . $this->shellQuote($artifactBase);
        }

        return $command
            . ' --client-id ' . $clientId
            . ' --agent-token ' . $this->shellQuote($agentToken);
    }

    protected function androidClientApkUrl(): string
    {
        return $this->clientAppArtifactUrl('android_client_apk', '/artifacts/leikwan-android-client.apk');
    }

    protected function windowsClientAppUrl(): string
    {
        return $this->clientAppArtifactUrl('windows_client_app', '/artifacts/leikwan-bridge-setup.exe');
    }

    /**
     * Monochrome platform marks for the download panels. Inline SVG with
     * currentColor so they follow the surrounding text colour in both the light
     * and the dark operator theme, and need no asset pipeline.
     */
    protected function platformIcon(string $kind): string
    {
        $open = '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true">';
        $icons = [
            'windows' => '<rect x="2" y="2.5" width="9" height="9"/><rect x="12.6" y="2.5" width="9.4" height="9"/><rect x="2" y="13.1" width="9" height="9"/><rect x="12.6" y="13.1" width="9.4" height="9"/>',
            'macos' => '<path d="M12 7.6c-3 0-5.2 2.4-5.2 5.6 0 3.2 2.3 5.9 5.2 5.9s5.2-2.7 5.2-5.9c0-3.2-2.2-5.6-5.2-5.6z"/><ellipse cx="14.6" cy="4.7" rx="2.6" ry="1.2" transform="rotate(-32 14.6 4.7)"/><rect x="11.6" y="5.4" width="1" height="2.6" rx="0.5"/>',
            'linux' => '<circle cx="12" cy="7.2" r="4.3"/><ellipse cx="12" cy="16" rx="5.6" ry="6.4"/><path d="M10 9.2h4L12 11.9z"/><ellipse cx="6.4" cy="20.4" rx="2.6" ry="1.1"/><ellipse cx="17.6" cy="20.4" rx="2.6" ry="1.1"/>',
            'android' => '<path d="M5.6 10.4h12.8v6.8a2 2 0 0 1-2 2H7.6a2 2 0 0 1-2-2z"/><path d="M5.6 9.6a6.4 6.4 0 0 1 12.8 0z"/><path d="M8.2 3.4 6.9 1.8M15.8 3.4l1.3-1.6" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" fill="none"/><rect x="3.1" y="10.6" width="1.7" height="5.6" rx="0.85"/><rect x="19.2" y="10.6" width="1.7" height="5.6" rx="0.85"/>',
            'helper' => '<path d="M12 4.6a7.4 7.4 0 1 0 7.1 9.4l-2.1-.6A5.2 5.2 0 1 1 12 6.8z"/><path d="M12 2.2l4 3-4 3z"/>',
        ];
        if (!isset($icons[$kind])) {
            return '';
        }

        return '<span class="command-card-icon" style="display:inline-flex;vertical-align:-4px;margin-right:8px;">'
            . $open . $icons[$kind] . '</svg></span>';
    }
    /**
     * The repair/update helper rendered inside the Windows App panel. A host on
     * an older build needs the app and the helper together, and a second panel
     * for the same platform only splits the attention of whoever is installing.
     */
    protected function renderWindowsUpdateHelperBlock(): string
    {
        $url = $this->windowsUpdateHelperUrl();
        $command = $this->windowsUpdateHelperCommand();

        return '<div style="margin-top:14px;padding-top:12px;border-top:1px solid rgba(127,127,127,0.25);">'
            . '<div class="command-card-title">' . $this->platformIcon('helper')
            . $this->escape($this->clientAppReleaseLabel('Windows Update Helper', 'windows_update_helper')) . '</div>'
            . '<div class="muted command-card-note">老版本或卡住的客户端用它：一键修复共享权限并更新到当前版本，需以管理员身份运行；安装包里也带一份。</div>'
            . '<pre class="command-pre">' . $this->escape($command) . '</pre>'
            . '<div class="actions command-card-actions">'
            . $this->renderCommandLink('Download Update Helper', $url)
            . $this->renderCopyButton('Copy Update Command', $command)
            . '</div></div>';
    }

    protected function windowsUpdateHelperUrl(): string
    {
        return $this->clientAppArtifactUrl('windows_update_helper', '/artifacts/leikwan-bridge-repair.cmd');
    }

    /**
     * One line an operator can paste on a Windows host that is stuck on an older
     * build: fetch the helper and start it elevated. It repairs the shared
     * permissions and then installs the published build.
     *
     * This line is parsed twice - once by the shell it is pasted into, once by
     * the child powershell that parses the -Command payload - so it has to be
     * valid under both. Two rules make that true in cmd, Windows PowerShell and
     * pwsh alike:
     *
     *   1. No $ in the payload. Inside the outer double quotes a PowerShell
     *      parent expands $h/$env:TEMP before the child ever runs, so the child
     *      received " = Join-Path C:\...\Temp ..." and the line could not work
     *      in PowerShell at all.
     *   2. The artifact URL is single quoted. It always carries
     *      "?v=<version>&sha256=<hash>", and once the outer quotes are consumed
     *      the child's parser met a bare & and refused the whole line with
     *      "The ampersand (&) character is not allowed".
     */
    protected function windowsUpdateHelperCommand(): string
    {
        $target = '(Join-Path ([System.IO.Path]::GetTempPath()) leikwan-bridge-repair.cmd)';
        $payload = 'Invoke-WebRequest -UseBasicParsing ' . $this->powershellSingleQuote($this->windowsUpdateHelperUrl())
            . ' -OutFile ' . $target
            . '; Start-Process -FilePath ' . $target . ' -Verb RunAs';

        return 'powershell -NoProfile -ExecutionPolicy Bypass -Command "' . $payload . '"';
    }

    /** Single quoted PowerShell literal: quotes are escaped by doubling them. */
    protected function powershellSingleQuote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    protected function linuxArm64ClientInstallerUrl(): string
    {
        return $this->clientAppArtifactUrl(
            'client_agent_installer_linux_arm64',
            '/artifacts/install-client-agent-linux-arm64'
        );
    }

    protected function macosClientArm64AppUrl(): string
    {
        return $this->clientAppArtifactUrl('macos_client_app_arm64', '/artifacts/macos/leikwan-bridge-arm64.dmg');
    }

    protected function macosClientX64AppUrl(): string
    {
        return $this->clientAppArtifactUrl('macos_client_app_x64', '/artifacts/macos/leikwan-bridge-x64.dmg');
    }

    // No-GUI surface. The Windows payload is its own artifact; macOS reuses the
    // shipped app bundle in its --headless lifecycle, so it only needs the
    // installers. Every URL carries the manifest version/hash like the GUI
    // downloads, so an operator can verify what they fetched.
    protected function windowsHeadlessClientUrl(): string
    {
        return $this->clientAppArtifactUrl(
            'windows_headless_client',
            '/artifacts/windows/leikwan-bridge-headless-win-x64.zip'
        );
    }

    protected function macosHeadlessInstallerUrl(): string
    {
        return $this->installerScriptUrl('macos_headless_installer', '/artifacts/install-macos-headless.sh');
    }

    protected function windowsHeadlessInstallerUrl(): string
    {
        return $this->installerScriptUrl('windows_headless_installer', '/artifacts/install-windows-headless.ps1');
    }

    protected function installerScriptUrl(string $artifactKey, string $fallbackPath): string
    {
        $metadata = $this->artifactMetadata($artifactKey);
        $configuredPath = trim((string) ($metadata['install_url'] ?? ''));
        $path = $configuredPath !== '' ? $configuredPath : $fallbackPath;
        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        return $this->artifactBaseUrl() . '/' . ltrim($path, '/');
    }

    /**
     * One-line installers for the no-GUI clients. Both carry the same client id
     * and agent token the Linux installer uses; neither embeds a credential in
     * a URL query, so the command can be copied out of the page safely.
     *
     * @param array<string, mixed> $client
     */
    protected function macosHeadlessInstallCommand(array $client): string
    {
        $artifactBase = rtrim($this->artifactBaseUrl(), '/');
        $masterUrl = $this->agentBaseUrl();
        $clientId = (int) ($client['id'] ?? 0);
        $agentToken = (string) ($client['agent_token'] ?? '');

        return 'curl -fsSL ' . $this->shellQuote($artifactBase . '/artifacts/install-macos-headless.sh')
            . ' | sudo sh -s -- --panel ' . $this->shellQuote($masterUrl)
            . ' --client-id ' . $clientId
            . ' --agent-token ' . $this->shellQuote($agentToken);
    }

    /**
     * @param array<string, mixed> $client
     */
    protected function windowsHeadlessInstallCommand(array $client): string
    {
        $zipUrl = $this->windowsHeadlessClientUrl();
        $masterUrl = $this->agentBaseUrl();
        $clientId = (int) ($client['id'] ?? 0);
        $agentToken = (string) ($client['agent_token'] ?? '');

        return '$z="$env:TEMP\lkb-headless.zip"; '
            . 'iwr \'' . $zipUrl . '\' -OutFile $z; '
            . 'Expand-Archive $z "$env:TEMP\lkb-headless" -Force; '
            . 'cd "$env:TEMP\lkb-headless\leikwan-bridge-headless"; '
            . '.\install-windows-headless.ps1 -Panel ' . $masterUrl
            . ' -ClientId ' . $clientId
            . ' -AgentToken \'' . $agentToken . '\'';
    }

    protected function headlessClientReleaseVersion(): string
    {
        foreach (['windows_headless_client', 'macos_headless_installer'] as $key) {
            $version = trim((string) ($this->artifactMetadata($key)['version'] ?? ''));
            if ($version !== '') {
                return $version;
            }
        }

        return '';
    }

    private function clientAppArtifactUrl(string $artifactKey, string $fallbackPath): string
    {
        $metadata = $this->artifactMetadata($artifactKey);
        $configuredPath = trim((string) ($metadata['artifact_url'] ?? ''));
        $url = $configuredPath !== ''
            ? (preg_match('#^https?://#i', $configuredPath) === 1
                ? $configuredPath
                : $this->artifactBaseUrl() . '/' . ltrim($configuredPath, '/'))
            : $this->artifactBaseUrl() . $fallbackPath;
        $fragment = '';
        if (str_contains($url, '#')) {
            [$url, $fragmentValue] = explode('#', $url, 2);
            $fragment = '#' . $fragmentValue;
        }
        $base = $url;
        $existingQuery = '';
        if (str_contains($url, '?')) {
            [$base, $existingQuery] = explode('?', $url, 2);
        }
        $pairs = [];
        foreach (explode('&', $existingQuery) as $pair) {
            if ($pair === '') {
                continue;
            }
            $queryKey = strtolower(rawurldecode(explode('=', $pair, 2)[0]));
            if (!in_array($queryKey, ['v', 'sha256'], true)) {
                $pairs[] = $pair;
            }
        }
        foreach ([
            'v' => trim((string) ($metadata['version'] ?? '')),
            'sha256' => strtolower(trim((string) ($metadata['sha256'] ?? ''))),
        ] as $key => $value) {
            if ($value !== '') {
                $pairs[] = rawurlencode($key) . '=' . rawurlencode($value);
            }
        }

        return $base . ($pairs === [] ? '' : '?' . implode('&', $pairs)) . $fragment;
    }

    protected function clientAppReleaseLabel(string $label, string $artifactKey): string
    {
        $version = trim((string) ($this->artifactMetadata($artifactKey)['version'] ?? ''));
        return $version === '' ? $label : $label . ' ' . $version;
    }

    protected function macosClientArtifactsAvailable(): bool
    {
        foreach (['macos_client_app_arm64', 'macos_client_app_x64'] as $key) {
            $metadata = $this->artifactMetadata($key);
            if ($metadata === [] || trim((string) ($metadata['artifact_url'] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    protected function macosClientReleaseVersion(): string
    {
        foreach (['macos_client_app_arm64', 'macos_client_app_x64'] as $key) {
            $version = trim((string) ($this->artifactMetadata($key)['version'] ?? ''));
            if ($version !== '') {
                return $version;
            }
        }

        return '';
    }

    protected function macosClientReleaseNotice(): string
    {
        $version = $this->macosClientReleaseVersion();

        return $version !== ''
            ? 'Release announcement / 发布声明：macOS client ' . $version . ' is now available. macOS 客户端 ' . $version . ' 已发布，支持 Apple Silicon 与 Intel。'
            : 'Release announcement / 发布声明：macOS client is now available. macOS 客户端已发布，支持 Apple Silicon 与 Intel。';
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

    protected function renderCopyButton(
        string $label,
        string $text,
        string $variant = 'secondary',
        string $successLabel = 'Copied',
        string $errorLabel = 'Copy failed'
    ): string
    {
        $class = 'button';
        if ($variant !== '') {
            $class .= ' ' . $variant;
        }

        return '<button type="button" class="' . $this->escape($class) . '" data-copy-text="' . $this->escape($text) . '" data-copy-success="' . $this->escape($successLabel) . '" data-copy-error="' . $this->escape($errorLabel) . '">'
            . $this->escape($label)
            . '</button>';
    }

    /**
     * @param array<int, string> $actions
     */
    protected function renderCommandPanel(
        string $title,
        string $command,
        array $actions = [],
        string $description = '',
        string $icon = '',
        string $extraHtml = ''
    ): string {
        $actionsHtml = $actions !== []
            ? '<div class="actions command-card-actions">' . implode('', $actions) . '</div>'
            : '';
        $descriptionHtml = $description !== ''
            ? '<div class="muted command-card-note">' . $this->escape($description) . '</div>'
            : '';

        return '<section class="command-card">'
            . '<div class="command-card-header">'
            . '<div><div class="command-card-title">' . $this->platformIcon($icon) . $this->escape($title) . '</div>' . $descriptionHtml . '</div>'
            . $actionsHtml
            . '</div>'
            . '<pre class="command-pre">' . $this->escape($command) . '</pre>'
            . $extraHtml
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
            ? 'Debian 11/12/13 only. One command for both architectures: the installer detects x86_64/amd64 or aarch64/arm64 and verifies every artifact against the panel manifest. Example shown for the current client; each client card below repeats its own exact command.'
            : 'Debian 11/12/13 only. One command for both architectures: the installer detects x86_64/amd64 or aarch64/arm64 and verifies every artifact against the panel manifest. Create a client first to get its exact install command.';

        return '<section class="card" style="margin-bottom:20px;">'
            . '<h3>App Download</h3>'
            . '<p class="muted">Windows, Android, and macOS use the app install flow. Linux remains a local/manual install command for the host that runs the client-agent. Ultra UDP forwards keep a protocol-level association alive while running; requested debug logs are private, redacted, and expire automatically.</p>'
            . $this->renderCommandPanel(
                $this->clientAppReleaseLabel('Windows App', 'windows_client_app'),
                $windowsAppUrl,
                [
                    $this->renderCommandLink('Download Windows App', $windowsAppUrl),
                    $this->renderCopyButton('Copy Download Link', $windowsAppUrl),
                ],
                'Download the current LeiKwan Bridge desktop app for this client.'
                ,
                'windows',
                $this->renderWindowsUpdateHelperBlock()
            )
            . $this->renderMacClientDownloadPanels()
            . $this->renderCommandPanel(
                $this->clientAppReleaseLabel('Android App', 'android_client_apk'),
                $androidApkUrl,
                [
                    $this->renderCommandLink('Download Android App', $androidApkUrl),
                    $this->renderCopyButton('Copy Download Link', $androidApkUrl),
                ],
                'Download the Android app for this client or use the Android import string from a client card below.',
                'android'
            )
            . $this->renderCommandPanel(
                $this->clientAppReleaseLabel('Linux Install (x86_64 / arm64)', 'client_agent_installer'),
                $linuxInstall,
                $linuxActions,
                $linuxDescription,
                'linux'
            )
            . $this->renderHeadlessClientDownloadPanel($client)
            . '</section>';
    }

    /**
     * No-GUI clients: the same client without a desktop session. The panel links
     * the payload, the installer, and the knowledgebase guide that documents the
     * exact commands, so an operator never has to guess a flag.
     *
     * @param array<string, mixed>|null $client
     */
    protected function renderHeadlessClientDownloadPanel(?array $client = null): string
    {
        $clientId = (int) ($client['id'] ?? 0);
        $agentToken = trim((string) ($client['agent_token'] ?? ''));
        $hasClient = $clientId > 0 && $agentToken !== '';
        $zipUrl = $this->windowsHeadlessClientUrl();
        $guideUrl = '/knowledgebase?thread=no-gui-clients';
        $guideLink = '<a class="button secondary" href="' . $this->escape($guideUrl) . '">使用说明 / Install guide</a>';
        $description = 'Windows 与 macOS 都可以没有桌面会话地运行：macOS 注册为 launchd 守护进程，Windows 注册为系统服务。与图形客户端同一套内核、同一份运行时指纹与更新校验，令牌保存在仅服务可读的文件里。'
            . ($hasClient
                ? ' 下面的命令已带好当前客户端的身份。'
                : ' 先在下方创建客户端，再复制带身份的安装命令。');

        $actions = [
            $this->renderCommandLink('下载 Windows 无界面版', $zipUrl),
            $this->renderCopyButton('复制下载链接', $zipUrl),
            $guideLink,
        ];
        $command = $hasClient
            ? $this->windowsHeadlessInstallCommand($client)
            : $zipUrl;

        return $this->renderCommandPanel(
            $this->clientAppReleaseLabel('No-GUI Client (Windows / macOS)', 'windows_headless_client'),
            $command,
            $actions,
            $description,
            'helper'
        );
    }

    protected function renderClientAgentInstallDetails(array $client, array $forwardInstalls = []): string
    {
        $installCommand = $this->clientAgentInstallCommand($client);
        $windowsInstallerUrl = $this->clientWindowsInstallerUrl();
        $windowsManagerCommand = $this->clientWindowsManagerCommand($client);
        $windowsManagerUrl = $this->clientWindowsManagerUrlForClient($client);
        $androidApkUrl = $this->androidClientApkUrl();
        $windowsAppUrl = $this->windowsClientAppUrl();
        $uninstallCommand = $this->clientAgentUninstallCommand();
        $clientId = (int) ($client['id'] ?? 0);
        $agentToken = trim((string) ($client['agent_token'] ?? ''));
        $windowsInstallPanels = $this->renderCommandPanel(
            $this->clientAppReleaseLabel('Windows Forward Selector', 'client_manager_windows'),
            $windowsManagerCommand,
            [
                $this->renderCommandLink('Open Install Link', $windowsManagerUrl),
                $this->renderCopyButton('Copy Install Command', $windowsManagerCommand),
            ],
            'Secondary tool for hosts that still use the Windows PowerShell manager to pick and control local forward tasks.',
            'windows'
        );
        if ($windowsManagerUrl === $this->clientWindowsManagerUrl()) {
            $windowsInstallPanels .= $this->renderCommandPanel(
                $this->clientAppReleaseLabel('Windows PowerShell (Admin)', 'client_installer_windows'),
                $windowsInstallerUrl,
                [
                    $this->renderCommandLink('Open Install Link', $windowsInstallerUrl),
                    $this->renderCopyButton('Copy Install Link', $windowsInstallerUrl),
                ],
                'Legacy bootstrap script. Use the Windows app above as the primary install path.',
                'windows'
            );
        }

        return '<section class="card" style="margin-top:14px;">'
            . '<h4 style="margin:0 0 8px;">App Download</h4>'
            . '<div class="muted command-lead">Windows, Android, and macOS use the app install flow. Linux remains available as a local/manual install command for this client. Ultra UDP keepalive starts with the forward and remains active for its lifetime.</div>'
            . $this->renderCommandPanel(
                $this->clientAppReleaseLabel('Windows App', 'windows_client_app'),
                $windowsAppUrl,
                [
                    $this->renderCommandLink('Download Windows App', $windowsAppUrl),
                    $this->renderCopyButton('Copy Download Link', $windowsAppUrl),
                ],
                'Install the LeiKwan Bridge desktop app for this client.',
                'windows',
                $this->renderWindowsUpdateHelperBlock()
            )
            . $this->renderMacClientDownloadPanels()
            . $this->renderCommandPanel(
                $this->clientAppReleaseLabel('Android App', 'android_client_apk'),
                $androidApkUrl,
                [
                    $this->renderCommandLink('Download Android App', $androidApkUrl),
                    $this->renderCopyButton('Copy Download Link', $androidApkUrl),
                ],
                'Install the Android app for this client.',
                'android'
            )
            . $this->renderCommandPanel(
                $this->clientAppReleaseLabel('Linux Install (x86_64 / arm64)', 'client_agent_installer'),
                $installCommand,
                [
                    $this->renderCommandLink('Open Install Link', $this->clientAgentInstallerUrl()),
                    $this->renderCopyButton('Copy Install Command', $installCommand),
                ],
                'Debian 11/12/13 only. Manual/local install for this client on the Linux host that will run the client-agent. The installer detects x86_64/amd64 or aarch64/arm64 (apt/dpkg/systemd) and verifies every artifact against the panel manifest.'
            )
            . '<details style="margin-top:14px;">'
            . '<summary>More install tools</summary>'
            . '<div class="muted" style="margin:10px 0 12px;">These secondary tools stay available for local management and compatibility flows, but the primary install story is now app download or Linux install.</div>'
            . $windowsInstallPanels
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

    protected function renderMacClientDownloadPanels(): string
    {
        if (!$this->macosClientArtifactsAvailable()) {
            return '';
        }

        $releaseNotice = $this->macosClientReleaseNotice();
        $arm64Url = $this->macosClientArm64AppUrl();
        $x64Url = $this->macosClientX64AppUrl();

        return $this->renderCommandPanel(
            $this->clientAppReleaseLabel('macOS App - Apple Silicon', 'macos_client_app_arm64'),
            $arm64Url,
            [
                $this->renderCommandLink('Download Apple Silicon', $arm64Url),
                $this->renderCopyButton('Copy Download Link', $arm64Url),
            ],
            $releaseNotice . ' Unsigned build; macOS may show a security prompt on first open. 未签名版本，首次打开时 macOS 可能显示安全提示。',
            'macos'
        )
        . $this->renderCommandPanel(
            $this->clientAppReleaseLabel('macOS App - Intel', 'macos_client_app_x64'),
            $x64Url,
            [
                $this->renderCommandLink('Download Intel', $x64Url),
                $this->renderCopyButton('Copy Download Link', $x64Url),
            ],
            'For Intel Macs (x86_64). 适用于 Intel Mac。',
            'macos'
        );
    }

    protected function renderMacClientInstallSection(): string
    {
        if (!$this->macosClientArtifactsAvailable()) {
            return '';
        }

        $releaseNotice = $this->macosClientReleaseNotice();

        $version = $this->macosClientReleaseVersion();

        return '<section class="tenant-client-install__platform"><strong>'
            . $this->platformIcon('macos')
            . 'macOS' . ($version !== '' ? ' ' . $this->escape($version) : '') . '</strong>'
            . '<div class="flash success" style="margin:10px 0 0;">' . $this->escape($releaseNotice) . '</div>'
            . '<div class="muted">Unsigned build; macOS may show a security prompt on first open. 未签名版本，首次打开时 macOS 可能显示安全提示。</div>'
            . '<div class="actions">'
            . $this->renderCommandLink('Apple Silicon', $this->macosClientArm64AppUrl(), 'secondary')
            . $this->renderCommandLink('Intel', $this->macosClientX64AppUrl(), 'secondary')
            . '</div>'
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

        if (array_key_exists($key, $this->normalizedArtifactMetadataCache)) {
            return $this->normalizedArtifactMetadataCache[$key];
        }

        $entry = $cache[$key] ?? null;
        if (!is_array($entry)) {
            return $this->normalizedArtifactMetadataCache[$key] = [];
        }

        return $this->normalizedArtifactMetadataCache[$key] = $this->normalizeArtifactMetadataEntry($entry);
    }

    /**
     * @param list<string> $keys
     * @return array<string, array<string, mixed>>
     */
    public function artifactMetadataForActivation(array $keys): array
    {
        $metadata = [];
        $sourceMap = new \Leikwan\Master\Support\LeigodOnlyArtifactSourceBuildMap(dirname(__DIR__, 3));
        $helperMap = $sourceMap->routeOnlyHelperMap();

        foreach (array_values(array_unique(array_filter($keys, 'is_string'))) as $key) {
            $entry = $this->artifactMetadata($key);
            if ($entry === [] && isset($helperMap[$key]) && is_array($helperMap[$key])) {
                $entry = $this->metadataFromSourceMapEntry($helperMap[$key]);
            }
            if ($entry !== []) {
                $metadata[$key] = $entry;
            }
        }

        return $metadata;
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function normalizeArtifactMetadataEntry(array $entry): array
    {
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

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function metadataFromSourceMapEntry(array $entry): array
    {
        $paths = is_array($entry['paths'] ?? null) ? $entry['paths'] : [];
        $relativePath = '';
        foreach ($paths as $path) {
            if (is_string($path) && $path !== '') {
                $relativePath = $path;
                break;
            }
        }
        $routePath = is_string($entry['route_path'] ?? null) ? $entry['route_path'] : '';
        if ($relativePath === '' || $routePath === '') {
            return [];
        }

        $absolute = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (!is_file($absolute)) {
            return [];
        }

        return [
            'path' => $relativePath,
            'artifact_url' => $routePath,
            'sha256' => hash_file('sha256', $absolute),
            'size_bytes' => filesize($absolute),
        ];
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
            ['group' => 'Operations', 'href' => '/admin', 'label' => 'Overview', 'active' => $active === 'overview'],
            ['group' => 'Operations', 'href' => '/admin/services', 'label' => 'Services', 'active' => $active === 'services'],
            ['group' => 'Operations', 'href' => '/admin/clients', 'label' => 'Clients', 'active' => $active === 'clients'],
            ['group' => 'Operations', 'href' => '/admin/ultra', 'label' => 'Ultra Operations', 'active' => $active === 'ultra'],
            ['group' => 'Security', 'href' => '/admin/access-control', 'label' => 'Access Control', 'active' => $active === 'access-control'],
            ['group' => 'Customers', 'href' => '/admin/tenants', 'label' => 'Tenants', 'active' => $active === 'tenants'],
            ['group' => 'Customers', 'href' => '/admin/registration', 'label' => 'Registration Codes', 'active' => $active === 'registration'],
            ['group' => 'Customers', 'href' => '/admin/user-groups', 'label' => 'User Groups', 'active' => $active === 'user-groups'],
            ['group' => 'Products', 'href' => '/admin/plans', 'label' => 'Plans', 'active' => $active === 'plans'],
            ['group' => 'Infrastructure', 'href' => '/admin-transport-endpoints', 'label' => 'Transport Endpoints', 'active' => $active === 'transport-endpoints'],
            ['group' => 'Infrastructure', 'href' => '/admin/node-groups', 'label' => 'Slave Groups', 'active' => $active === 'node-groups'],
            ['group' => 'Infrastructure', 'href' => '/admin/nodes', 'label' => 'Slave Nodes', 'active' => $active === 'nodes'],
            ['group' => 'Infrastructure', 'href' => '/admin/forward-combinations', 'label' => 'Forward Combinations', 'active' => $active === 'forward-combinations'],
            ['group' => 'Infrastructure', 'href' => '/admin/leigod-lines', 'label' => 'Managed TYO Lines', 'active' => $active === 'leigod-lines'],
            ['group' => 'Infrastructure', 'href' => '/admin/client-groups', 'label' => 'Client Groups', 'active' => $active === 'client-groups'],
            ['group' => 'Infrastructure', 'href' => '/admin/credentials', 'label' => 'Credentials', 'active' => $active === 'credentials'],
            ['group' => 'Support', 'href' => '/knowledgebase', 'label' => 'Knowledgebase', 'active' => $active === 'knowledgebase'],
            ['group' => 'Context', 'href' => '/tenant', 'label' => 'Tenant Area'],
        ];
    }

    protected function localeSwitcherHtml(): string
    {
        return LocaleSwitcher::html(LocaleResolver::fromGlobals());
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
      cookieName: 'leikwan_locale',
      basePath: '/i18n'
    };
  </script>
  <script src="/i18n/patch.js"></script>
HTML;
    }

    protected function publicPage(string $title, string $body, ?array $viewer = null, string $variant = 'default'): Response
    {
        $isBridgeHome = $variant === 'bridge-home';
        $escapedTitle = $this->escape($title);
        $appName = $this->appName();
        $escapedAppName = $this->escape($appName);
        $escapedBrandName = $this->escape($this->displayBrandName());
        $brandLogoUrl = $this->escape($this->brandLogoUrl());
        $flashHtml = $this->flashHtml();
        $currentLocale = LocaleResolver::fromGlobals();
        $languageSwitcher = $this->localeSwitcherHtml();
        $htmlLang = $this->escape($currentLocale);
        $csrfToken = $this->escape(SessionAuth::csrfToken());
        $bodyLayoutClass = $isBridgeHome ? 'bridge-home-body' : 'pg-auth-layout';
        $pageClass = $isBridgeHome ? ' bridge-home-page' : '';
        $topbarClass = $isBridgeHome ? ' bridge-home-topbar' : '';
        $mainClass = $isBridgeHome ? ' bridge-home-main' : '';
        $styleLinks = '<link rel="stylesheet" href="/assets/leikwan-ui.css?v=20260725">'
            . ($isBridgeHome
                ? '<link rel="stylesheet" href="/assets/bridge-home.css?v=20260914a">'
                : '<link rel="stylesheet" href="/assets/signal-chamber.css?v=20260725"><link rel="stylesheet" href="/assets/bridge-modern.css?v=20260914a">');
        $script = $isBridgeHome ? '' : '<script src="/assets/signal-chamber.js?v=20260725" defer></script>';
        $body = '<div class="pg-public-body ' . $bodyLayoutClass . '">' . $body . '</div>';

        $actionsHtml = '<a class="button" href="/login">Sign In</a>';
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
<html lang="{$htmlLang}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$escapedTitle}</title>
  {$styleLinks}
</head>
<body class="pg-public{$pageClass}">
  <div class="public-shell">
    <header class="site-topbar{$topbarClass}">
      <a class="site-brand" href="/">
        <span class="site-mark"><img src="{$brandLogoUrl}" alt="{$escapedBrandName} logo"></span>
        <span class="site-copy">
          <strong>{$escapedBrandName}</strong>
          <span>{$escapedAppName} control plane</span>
        </span>
      </a>
      <div class="site-actions">
        {$languageSwitcher}
        {$actionsHtml}
      </div>
    </header>
    <main class="landing-stack{$mainClass}">
      {$flashHtml}
      {$body}
    </main>
  </div>
{$this->i18nBootstrapHtml()}
  {$script}
</body>
</html>
HTML;

        return Response::html(200, $html);
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
        $currentLocale = LocaleResolver::fromGlobals();
        $languageSwitcher = $this->localeSwitcherHtml();
        $htmlLang = $this->escape($currentLocale);

        $activeGroup = 'Workspace';
        foreach ($nav as $item) {
            if (!empty($item['active'])) {
                $activeGroup = (string) ($item['group'] ?? 'Workspace');
                break;
            }
        }
        $templateClass = str_contains(strtolower($title), 'overview')
            ? 'pg-template-overview'
            : (preg_match('/edit|create|account|settings|detail/i', $title) === 1
                ? 'pg-template-detail'
                : 'pg-template-operations');
        $body = '<div class="pg-console">' . PrecisionGridUi::pageHeader($activeGroup, $title)
            . '<div class="pg-page-body ' . $templateClass . '">' . $body . '</div></div>';

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

        $navHtml = PrecisionGridUi::groupNavigation($nav);

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
<html lang="{$htmlLang}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$escapedTitle}</title>
  <link rel="stylesheet" href="/assets/leikwan-ui.css?v=20260725">
  <link rel="stylesheet" href="/assets/operator-console.css?v=20260825">
  <link rel="stylesheet" href="/assets/bridge-modern.css?v=20260914a">
</head>
<body class="pg-app">
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
        <button class="mobile-nav-toggle" type="button" aria-controls="workspace-navigation" aria-expanded="false">&#9776;<span class="pg-visually-hidden">Navigation</span></button>
        {$languageSwitcher}
        {$viewerHtml}
      </div>
    </div>
    <div class="layout">
      <aside class="sidebar" id="workspace-navigation" data-open="false">
        <div class="sidebar-panel">
          <strong>Workspace Shell</strong>
          <p>Use the public homepage as the front door, then jump back into the console you need.</p>
        </div>
        {$navHtml}
      </aside>
      <main class="content-card">
        {$flashHtml}
        {$lowBalanceHtml}
        {$body}
      </main>
    </div>
  </div>
{$this->i18nBootstrapHtml()}
<script>
  (function () {
    var navToggle = document.querySelector('.mobile-nav-toggle');
    var nav = document.getElementById('workspace-navigation');
    if (navToggle && nav) {
      navToggle.addEventListener('click', function () {
        var open = nav.getAttribute('data-open') !== 'true';
        nav.setAttribute('data-open', open ? 'true' : 'false');
        navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
    }
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
