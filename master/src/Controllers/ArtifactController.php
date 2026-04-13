<?php
declare(strict_types=1);

namespace Leikwan\Master\Controllers;

use Leikwan\Master\Http\Request;
use Leikwan\Master\Http\Response;

final class ArtifactController extends BaseController
{
    private const WSTUNNEL_ARTIFACT_DIR = 'artifacts/wstunnel';

    /** @var array<string, mixed>|null */
    private ?array $versions = null;

    public function __construct(\Leikwan\Master\Config $config)
    {
        parent::__construct($config, new \Leikwan\Master\Database\Database($config));
    }

    public function slaveAgent(Request $request, array $params): Response
    {
        return $this->serveRepoFile('slave-agent/slave_agent.py', 'text/x-python; charset=utf-8');
    }

    public function installSlave(Request $request, array $params): Response
    {
        return $this->serveRepoFile('install/install-slave.sh');
    }

    public function uninstallSlave(Request $request, array $params): Response
    {
        return $this->serveRepoFile('install/uninstall-slave.sh');
    }

    public function dedupeSlave(Request $request, array $params): Response
    {
        return $this->serveRepoFile('install/dedupe-slave.sh');
    }

    public function installClient(Request $request, array $params): Response
    {
        return $this->serveRepoFile('install/install-client.sh');
    }

    public function installBondSidecar(Request $request, array $params): Response
    {
        return $this->serveRepoFile('install/install-bond-sidecar.sh');
    }

    public function wgWatchdog(Request $request, array $params): Response
    {
        return $this->serveRepoFile('artifacts/leikwan-wg-watchdog.sh');
    }

    public function uninstallBondSidecar(Request $request, array $params): Response
    {
        return $this->serveRepoFile('install/uninstall-bond-sidecar.sh');
    }

    public function uninstallClient(Request $request, array $params): Response
    {
        return $this->serveRepoFile('install/uninstall-client.sh');
    }

    public function installClientPowerShell(Request $request, array $params): Response
    {
        $forwardId = (int) $request->input('forward_id', 0);
        $token = trim((string) $request->input('token', ''));
        if ($forwardId > 0 && $token !== '') {
            $script = $this->renderForwardSpecificWindowsInstallerWrapper($forwardId, $token);
            return new Response(200, [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="install-client-forward-' . $forwardId . '.ps1"',
            ], $script);
        }

        return $this->serveRepoFile('install/install-client.ps1', 'text/plain; charset=utf-8');
    }

    public function manageClientPowerShell(Request $request, array $params): Response
    {
        $clientId = (int) $request->input('client_id', 0);
        $agentToken = trim((string) $request->input('agent_token', ''));
        if ($clientId > 0 && $agentToken !== '') {
            $script = $this->renderClientSpecificWindowsManagerWrapper($clientId, $agentToken);
            return new Response(200, [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="manage-client-' . $clientId . '.ps1"',
            ], $script);
        }

        return $this->serveRepoFile('install/manage-client.ps1', 'text/plain; charset=utf-8');
    }

    public function apiDefaultPage(Request $request, array $params): Response
    {
        return $this->serveRepoFile('api-default.html', 'text/html; charset=utf-8');
    }

    public function clientAgent(Request $request, array $params): Response
    {
        return $this->serveRepoFile('client-agent/client_agent.py', 'text/x-python; charset=utf-8');
    }

    public function sidecarAgent(Request $request, array $params): Response
    {
        return $this->serveRepoFile('sidecar-agent/sidecar_agent.py', 'text/x-python; charset=utf-8');
    }

    public function installForwardRuntime(Request $request, array $params): Response
    {
        return $this->serveRepoFile('client-agent/install_forward_runtime.py', 'text/x-python; charset=utf-8');
    }

    public function mptcpClientRuntime(Request $request, array $params): Response
    {
        return $this->serveRepoFile('client-agent/leikwan_mptcp_client.py', 'text/x-python; charset=utf-8');
    }

    public function uotRuntime(Request $request, array $params): Response
    {
        return $this->serveRepoFile('client-agent/leikwan_uot.py', 'text/x-python; charset=utf-8');
    }

    public function mptcpSidecarRuntime(Request $request, array $params): Response
    {
        return $this->serveRepoFile('sidecar-agent/leikwan_mptcp_sidecar.py', 'text/x-python; charset=utf-8');
    }

    public function clientRateLimitHelper(Request $request, array $params): Response
    {
        return $this->serveRepoFile('client-agent/leikwan_rate_limit.py', 'text/x-python; charset=utf-8');
    }

    public function installClientAgent(Request $request, array $params): Response
    {
        return $this->serveRepoFile('install/install-client-agent.sh');
    }

    public function uninstallClientAgent(Request $request, array $params): Response
    {
        return $this->serveRepoFile('install/uninstall-client-agent.sh');
    }

    public function androidClientApk(Request $request, array $params): Response
    {
        return $this->serveRepoFile('artifacts/android/leikwan-android-client.apk', 'application/vnd.android.package-archive');
    }

    public function windowsClientApp(Request $request, array $params): Response
    {
        return $this->serveRepoFile('artifacts/windows/leikwan-bridge-setup.exe', 'application/octet-stream');
    }

    public function legacyClient(Request $request, array $params): Response
    {
        return $this->serveRepoFile('client.sh');
    }

    public function remoteInstallSlave(Request $request, array $params): Response
    {
        return $this->serveRepoFile('install/remote-install-slave.sh');
    }

    public function remoteInstallClient(Request $request, array $params): Response
    {
        return $this->serveRepoFile('install/remote-install-client.sh');
    }

    public function remoteInstallClientAgent(Request $request, array $params): Response
    {
        return $this->serveRepoFile('install/remote-install-client-agent.sh');
    }

    public function wstunnelPackage(Request $request, array $params): Response
    {
        $filename = basename((string) ($params['filename'] ?? ''));
        if ($filename === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $filename)) {
            return Response::json(404, ['error' => 'artifact_not_found', 'path' => 'artifacts/wstunnel/' . $filename]);
        }

        $path = dirname(__DIR__, 3)
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, self::WSTUNNEL_ARTIFACT_DIR)
            . DIRECTORY_SEPARATOR
            . $filename;

        if (!is_file($path)) {
            return Response::json(404, [
                'error' => 'artifact_not_found',
                'path' => 'artifacts/wstunnel/' . $filename,
                'message' => 'Place the requested wstunnel archive under artifacts/wstunnel on the panel host.',
            ]);
        }

        $contentType = str_ends_with($filename, '.tar.gz')
            ? 'application/gzip'
            : 'application/octet-stream';

        return new Response(200, [
            'Content-Type' => $contentType,
            'Content-Length' => (string) filesize($path),
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ], (string) file_get_contents($path));
    }

    public function slaveManifest(Request $request, array $params): Response
    {
        return Response::json(200, [
            'component' => 'slave',
            'manifest' => $this->manifestFor('slave_agent', 'slave_installer', 'wstunnel_linux_amd64', 'wstunnel_linux_arm64', 'leikwan_leg_relay_linux_amd64'),
        ]);
    }

    public function sidecarManifest(Request $request, array $params): Response
    {
        return Response::json(200, [
            'component' => 'sidecar',
            'manifest' => $this->manifestFor(
                'sidecar_agent',
                'mptcp_sidecar_runtime',
                'bond_sidecar_installer',
                'bond_sidecar_uninstaller'
            ),
        ]);
    }

    public function clientManifest(Request $request, array $params): Response
    {
        return Response::json(200, [
            'component' => 'client',
            'manifest' => $this->manifestFor(
                'client_installer',
                'client_installer_windows',
                'client_manager_windows',
                'bond_sidecar_installer',
                'bond_sidecar_uninstaller',
                'client_agent',
                'client_agent_installer',
                'mptcp_client_runtime',
                'uot_runtime',
                'sidecar_agent',
                'mptcp_sidecar_runtime',
                'android_client_apk',
                'windows_client_app',
                'legacy_client',
                'wstunnel_linux_amd64',
                'wstunnel_windows_amd64',
                'leikwan_bond_linux_amd64',
                'leikwan_bond_windows_amd64',
                'leikwan_bond_android_arm64'
            ),
        ]);
    }

    private function serveRepoFile(string $relativePath, string $contentType = 'text/plain; charset=utf-8'): Response
    {
        $repoRoot = dirname(__DIR__, 3);
        $path = $repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (!is_file($path)) {
            return Response::json(404, ['error' => 'artifact_not_found', 'path' => $relativePath]);
        }

        return Response::text(200, (string) file_get_contents($path), $contentType);
    }

    private function renderForwardSpecificWindowsInstallerWrapper(int $forwardId, string $token): string
    {
        $coreInstallerUrl = $this->artifactBaseUrl() . '/artifacts/install-client.ps1';
        $masterUrl = $this->agentBaseUrl();
        $tmpName = 'install-client-core-' . $forwardId . '.ps1';

        return implode("\r\n", [
            '$ErrorActionPreference = "Stop"',
            '$tmp = Join-Path $env:TEMP ' . $this->powerShellSingleQuoted($tmpName),
            'Invoke-WebRequest -UseBasicParsing ' . $this->powerShellSingleQuoted($coreInstallerUrl) . ' -OutFile $tmp',
            '& $tmp -MasterUrl ' . $this->powerShellSingleQuoted($masterUrl) . ' -ForwardId ' . $forwardId . ' -Token ' . $this->powerShellSingleQuoted($token),
            '',
        ]);
    }

    private function renderClientSpecificWindowsManagerWrapper(int $clientId, string $agentToken): string
    {
        $coreManagerUrl = $this->artifactBaseUrl() . '/artifacts/manage-client.ps1';
        $masterUrl = $this->agentBaseUrl();
        $tmpName = 'manage-client-core-' . $clientId . '.ps1';

        return implode("\r\n", [
            '$ErrorActionPreference = "Stop"',
            '$tmp = Join-Path $env:TEMP ' . $this->powerShellSingleQuoted($tmpName),
            'Invoke-WebRequest -UseBasicParsing ' . $this->powerShellSingleQuoted($coreManagerUrl) . ' -OutFile $tmp',
            '& $tmp -MasterUrl ' . $this->powerShellSingleQuoted($masterUrl) . ' -ClientId ' . $clientId . ' -AgentToken ' . $this->powerShellSingleQuoted($agentToken),
            '',
        ]);
    }

    private function powerShellSingleQuoted(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    /**
     * @return array<string, mixed>
     */
    private function manifestFor(string ...$keys): array
    {
        $all = $this->versions();
        $artifactUrl = $this->artifactBaseUrl();
        $manifest = [];
        foreach ($keys as $key) {
            $entry = $all[$key] ?? null;
            if (!is_array($entry)) {
                continue;
            }
            $normalized = $entry;
            foreach (['artifact_url', 'install_url', 'remote_install_url'] as $urlKey) {
                if (isset($normalized[$urlKey]) && is_string($normalized[$urlKey]) && $normalized[$urlKey] !== '') {
                    $normalized[$urlKey] = $artifactUrl !== '' ? $artifactUrl . $normalized[$urlKey] : $normalized[$urlKey];
                }
            }
            if (isset($normalized['path']) && is_string($normalized['path'])) {
                $absolute = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized['path']);
                if (is_file($absolute)) {
                    $normalized['sha256'] = hash_file('sha256', $absolute);
                    $normalized['size_bytes'] = filesize($absolute);
                }
            }
            $manifest[$key] = $normalized;
        }
        return $manifest;
    }

    /**
     * @return array<string, mixed>
     */
    private function versions(): array
    {
        if (is_array($this->versions)) {
            return $this->versions;
        }

        $repoRoot = dirname(__DIR__, 3);
        $path = $repoRoot . DIRECTORY_SEPARATOR . 'artifacts' . DIRECTORY_SEPARATOR . 'versions.json';
        if (!is_file($path)) {
            $this->versions = [];
            return $this->versions;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        $this->versions = is_array($decoded) ? $decoded : [];
        return $this->versions;
    }
}
