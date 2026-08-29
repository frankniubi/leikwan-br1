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
    private string $repoRoot;

    /** @param array<string, mixed>|null $versions */
    public function __construct(\Leikwan\Master\Config $config, ?array $versions = null, ?string $repoRoot = null)
    {
        parent::__construct($config, new \Leikwan\Master\Database\Database($config));
        $this->versions = $versions;
        $this->repoRoot = rtrim($repoRoot ?? dirname(__DIR__, 3), '/\\');
    }

    public function slaveAgent(Request $request, array $params): Response
    {
        return $this->serveRepoFile('slave-agent/slave_agent.py', 'text/x-python; charset=utf-8', $request);
    }

    public function slavePreconnectRelay(Request $request, array $params): Response
    {
        return $this->serveRepoFile('slave-agent/preconnect_relay.py', 'text/x-python; charset=utf-8', $request);
    }

    public function leigodForwardRelay(Request $request, array $params): Response
    {
        return $this->serveRepoFile('slave-agent/leigod_forward_relay.py', 'text/x-python; charset=utf-8', $request);
    }

    public function installSlave(Request $request, array $params): Response
    {
        return $this->serveRepoFile('install/install-slave.sh', 'text/plain; charset=utf-8', $request);
    }

    public function uninstallSlave(Request $request, array $params): Response
    {
        return $this->serveRepoFile('install/uninstall-slave.sh', 'text/plain; charset=utf-8', $request);
    }

    public function dedupeSlave(Request $request, array $params): Response
    {
        return $this->serveRepoFile('install/dedupe-slave.sh', 'text/plain; charset=utf-8', $request);
    }

    public function installClient(Request $request, array $params): Response
    {
        return $this->serveRepoFile('install/install-client.sh', 'text/plain; charset=utf-8', $request);
    }

    public function installBondSidecar(Request $request, array $params): Response
    {
        return $this->serveRepoFile('install/install-bond-sidecar.sh', 'text/plain; charset=utf-8', $request);
    }

    public function wgWatchdog(Request $request, array $params): Response
    {
        return $this->serveRepoFile('artifacts/leikwan-wg-watchdog.sh', 'text/plain; charset=utf-8', $request);
    }

    public function uninstallBondSidecar(Request $request, array $params): Response
    {
        return $this->serveRepoFile('install/uninstall-bond-sidecar.sh', 'text/plain; charset=utf-8', $request);
    }

    public function sidecarAgentUpdate(Request $request, array $params): Response
    {
        return $this->serveRepoFile('install/sidecar-agent-update.sh', 'text/plain; charset=utf-8', $request);
    }

    public function slaveAgentUpdate(Request $request, array $params): Response
    {
        return $this->serveRepoFile('install/leikwan-slave-update', 'text/plain; charset=utf-8', $request);
    }

    public function uninstallClient(Request $request, array $params): Response
    {
        return $this->serveRepoFile('install/uninstall-client.sh', 'text/plain; charset=utf-8', $request);
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

        return $this->serveRepoFile('install/install-client.ps1', 'text/plain; charset=utf-8', $request);
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

        return $this->serveRepoFile('install/manage-client.ps1', 'text/plain; charset=utf-8', $request);
    }

    public function apiDefaultPage(Request $request, array $params): Response
    {
        return $this->serveRepoFile('api-default.html', 'text/html; charset=utf-8', $request);
    }

    /**
     * Serve the AUTHORITATIVE artifact manifest at GET /artifacts/versions.json.
     *
     * The static master/public/artifacts/versions.json file historically drifted from the routed
     * binaries (it advertised a stale slave_agent sha while the served binary had moved on). This route
     * reads the top-level artifacts/versions.json and, for every entry that has a 'path', recomputes
     * sha256 + size from the actual file -- mirroring BaseController::artifactMetadata's per-entry
     * normalization. The served manifest therefore ALWAYS matches what artifactMetadata advertises and
     * the routed binaries, eliminating manifest/binary divergence for installer flows that fetch the URL.
     */
    public function versionsManifest(Request $request, array $params): Response
    {
        $manifest = [];
        foreach ($this->versions() as $key => $entry) {
            if (!is_array($entry)) {
                $manifest[$key] = $entry;
                continue;
            }
            $normalized = $entry;
            if (isset($normalized['path']) && is_string($normalized['path']) && $normalized['path'] !== '') {
                $absolute = $this->resolveRepoFile($normalized['path']);
                if ($absolute !== null) {
                    $sha = hash_file('sha256', $absolute);
                    $size = filesize($absolute);
                    $normalized['sha256'] = $sha;
                    $normalized['size_bytes'] = $size;
                    // Some consumers read the legacy 'size' alias; keep it consistent when present.
                    if (array_key_exists('size', $normalized)) {
                        $normalized['size'] = $size;
                    }
                }
            }
            $manifest[$key] = $normalized;
        }

        return new Response(200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'public, max-age=60',
        ], (string) json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    public function clientAgent(Request $request, array $params): Response
    {
        return $this->serveRepoFile('client-agent/client_agent.py', 'text/x-python; charset=utf-8', $request);
    }

    public function sidecarAgent(Request $request, array $params): Response
    {
        return $this->serveRepoFile('sidecar-agent/sidecar_agent.py', 'text/x-python; charset=utf-8', $request);
    }

    public function cfwgLatencyWatchdog(Request $request, array $params): Response
    {
        return $this->serveRepoFile('client-agent/cfwg_latency_watchdog.py', 'text/x-python; charset=utf-8', $request);
    }

    public function installForwardRuntime(Request $request, array $params): Response
    {
        return $this->serveRepoFile('client-agent/install_forward_runtime.py', 'text/x-python; charset=utf-8', $request);
    }

    public function ultraClient(Request $request, array $params): Response
    {
        return $this->serveRepoFile('artifacts/ultra/ultra-client_linux_amd64', 'application/octet-stream', $request);
    }

    public function ultraClientWindows(Request $request, array $params): Response
    {
        return $this->serveRepoFile('artifacts/ultra/ultra-client_windows_amd64.exe', 'application/octet-stream', $request);
    }

    public function forwardStrategyHelper(Request $request, array $params): Response
    {
        return $this->serveRepoFile('artifacts/forward-strategy.py', 'text/x-python; charset=utf-8', $request);
    }

    public function tcpRaceRuntime(Request $request, array $params): Response
    {
        return $this->serveRepoFile('client-agent/leikwan_tcp_race.py', 'text/x-python; charset=utf-8', $request);
    }

    public function udpFanoutRuntime(Request $request, array $params): Response
    {
        return $this->serveRepoFile('client-agent/leikwan_udp_fanout.py', 'text/x-python; charset=utf-8', $request);
    }

    public function mptcpClientRuntime(Request $request, array $params): Response
    {
        return $this->serveRepoFile('client-agent/leikwan_mptcp_client.py', 'text/x-python; charset=utf-8', $request);
    }

    public function uotRuntime(Request $request, array $params): Response
    {
        return $this->serveRepoFile('client-agent/leikwan_uot.py', 'text/x-python; charset=utf-8', $request);
    }

    public function uotBondRuntime(Request $request, array $params): Response
    {
        return $this->serveRepoFile('client-agent/leikwan_uot_bond.py', 'text/x-python; charset=utf-8', $request);
    }

    public function mptcpSidecarRuntime(Request $request, array $params): Response
    {
        return $this->serveRepoFile('sidecar-agent/leikwan_mptcp_sidecar.py', 'text/x-python; charset=utf-8', $request);
    }

    public function udpUotMergeRuntime(Request $request, array $params): Response
    {
        return $this->serveRepoFile('sidecar-agent/leikwan_udp_uot_merge.py', 'text/x-python; charset=utf-8', $request);
    }

    public function wssMuxRuntime(Request $request, array $params): Response
    {
        return $this->serveRepoFile('sidecar-agent/leikwan_wss_mux.py', 'text/x-python; charset=utf-8', $request);
    }

    public function clientRateLimitHelper(Request $request, array $params): Response
    {
        return $this->serveRepoFile('client-agent/leikwan_rate_limit.py', 'text/x-python; charset=utf-8', $request);
    }

    public function tcpAccelRuntime(Request $request, array $params): Response
    {
        return $this->serveRepoFile('tools/tcp-accel/tcp_accel.py', 'text/x-python; charset=utf-8', $request);
    }

    public function tcpAccelBridgeRuntime(Request $request, array $params): Response
    {
        return $this->serveRepoFile('slave-agent/leigod_tcp_accel_bridge.py', 'text/x-python; charset=utf-8', $request);
    }

    public function installTcpAccel(Request $request, array $params): Response
    {
        return $this->serveRepoFile('install/install-tcp-accel.sh', 'text/plain; charset=utf-8', $request);
    }

    public function installClientAgent(Request $request, array $params): Response
    {
        return $this->serveRepoFile('install/install-client-agent.sh', 'text/plain; charset=utf-8', $request);
    }

    public function clientAgentUpdate(Request $request, array $params): Response
    {
        return $this->serveRepoFile('install/client-agent-update.sh', 'text/plain; charset=utf-8', $request);
    }

    public function uninstallClientAgent(Request $request, array $params): Response
    {
        return $this->serveRepoFile('install/uninstall-client-agent.sh', 'text/plain; charset=utf-8', $request);
    }

    public function androidClientApk(Request $request, array $params): Response
    {
        return $this->serveRepoFile('artifacts/android/leikwan-android-client.apk', 'application/vnd.android.package-archive', $request);
    }

    public function windowsClientApp(Request $request, array $params): Response
    {
        return $this->serveRepoFile('artifacts/windows/leikwan-bridge-setup.exe', 'application/octet-stream', $request);
    }

    public function macosClientArm64Dmg(Request $request, array $params): Response
    {
        return $this->serveRepoFile(
            'artifacts/macos/leikwan-bridge-arm64.dmg',
            'application/x-apple-diskimage',
            $request,
            ['Content-Disposition' => 'attachment; filename="leikwan-bridge-arm64.dmg"']
        );
    }

    public function macosClientX64Dmg(Request $request, array $params): Response
    {
        return $this->serveRepoFile(
            'artifacts/macos/leikwan-bridge-x64.dmg',
            'application/x-apple-diskimage',
            $request,
            ['Content-Disposition' => 'attachment; filename="leikwan-bridge-x64.dmg"']
        );
    }

    public function macosClientArm64Update(Request $request, array $params): Response
    {
        return $this->serveRepoFile(
            'artifacts/macos/leikwan-bridge-update-arm64.zip',
            'application/zip',
            $request,
            ['Content-Disposition' => 'attachment; filename="leikwan-bridge-update-arm64.zip"']
        );
    }

    public function macosClientX64Update(Request $request, array $params): Response
    {
        return $this->serveRepoFile(
            'artifacts/macos/leikwan-bridge-update-x64.zip',
            'application/zip',
            $request,
            ['Content-Disposition' => 'attachment; filename="leikwan-bridge-update-x64.zip"']
        );
    }

    public function gostBinary(Request $request, array $params): Response
    {
        return $this->serveRepoFile('artifacts/gost/gost_linux_amd64', 'application/octet-stream', $request);
    }

    public function leigodBinary(Request $request, array $params): Response
    {
        return $this->serveRepoFile('artifacts/leigod/leigod-linux-amd64', 'application/octet-stream', $request);
    }

    public function lguotBinary(Request $request, array $params): Response
    {
        return $this->serveRepoFile('artifacts/lguot/lguot_linux_amd64', 'application/octet-stream', $request);
    }

    public function legacyClient(Request $request, array $params): Response
    {
        return $this->serveRepoFile('client.sh', 'text/plain; charset=utf-8', $request);
    }

    public function wstunnelPackage(Request $request, array $params): Response
    {
        $filename = basename((string) ($params['filename'] ?? ''));
        if ($filename === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $filename)) {
            return Response::json(404, ['error' => 'artifact_not_found', 'path' => 'artifacts/wstunnel/' . $filename]);
        }

        $path = $this->repoRoot
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

        return $this->serveRepoFile(self::WSTUNNEL_ARTIFACT_DIR . '/' . $filename, $contentType, $request, [
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    public function slaveManifest(Request $request, array $params): Response
    {
        return Response::json(200, [
            'component' => 'slave',
            'manifest' => $this->manifestFor('slave_agent', 'slave_preconnect_relay', 'leigod_forward_relay', 'slave_installer', 'tcp_accel_runtime', 'tcp_accel_bridge_runtime', 'tcp_accel_installer', 'wstunnel_linux_amd64', 'wstunnel_linux_arm64', 'leikwan_leg_relay_linux_amd64'),
        ]);
    }

    public function sidecarManifest(Request $request, array $params): Response
    {
        return Response::json(200, [
            'component' => 'sidecar',
            'manifest' => $this->manifestFor(
                'sidecar_agent',
                'mptcp_sidecar_runtime',
                'udp_uot_merge_runtime',
                'leikwan_udp_uot_merge_linux_amd64',
                'bond_sidecar_installer',
                'bond_sidecar_uninstaller',
                'tcp_accel_runtime',
                'tcp_accel_installer'
            ),
        ]);
    }

    public function clientManifest(Request $request, array $params): Response
    {
        $manifest = $this->manifestFor(
            'client_installer',
            'client_installer_windows',
            'client_manager_windows',
            'bond_sidecar_installer',
            'bond_sidecar_uninstaller',
            'client_agent',
            'client_agent_update',
            'install_forward_runtime',
            'cfwg_latency_watchdog',
            'client_agent_installer',
            'client_rate_limit',
            'wg_watchdog',
            'ultra_client_runtime',
            'ultra_client_runtime_windows',
            'mptcp_client_runtime',
            'uot_runtime',
            'uot_bond_runtime',
            'leikwan_uot_bond_linux_amd64',
            'leikwan_uot_bond_windows_amd64',
            'sidecar_agent',
            'mptcp_sidecar_runtime',
            'udp_uot_merge_runtime',
            'tcp_race_runtime',
            'udp_fanout_runtime',
            'tcp_accel_runtime',
            'tcp_accel_installer',
            'android_client_apk',
            'windows_client_app',
            'macos_client_app_arm64',
            'macos_client_app_x64',
            'macos_client_update_arm64',
            'macos_client_update_x64',
            'legacy_client',
            'wstunnel_linux_amd64',
            'wstunnel_windows_amd64',
            'leikwan_bond_linux_amd64',
            'leikwan_bond_windows_amd64',
            'leikwan_bond_android_arm64',
            'leikwan_mptcp_relay_linux_amd64'
        );
        $macKeys = [
            'macos_client_app_arm64',
            'macos_client_app_x64',
            'macos_client_update_arm64',
            'macos_client_update_x64',
        ];
        foreach ($macKeys as $key) {
            if (!isset($manifest[$key])
                || !is_array($manifest[$key])
                || $this->artifactEntryPath($manifest[$key]) === null) {
                unset($manifest[$key]);
            }
        }
        $manifest = $this->normalizeClientAppArtifactUrls(
            $manifest,
            array_merge(['windows_client_app'], $macKeys)
        );

        return Response::json(200, [
            'component' => 'client',
            'manifest' => $manifest,
        ]);
    }

    /** @param array<string, string> $extraHeaders */
    private function serveRepoFile(string $relativePath, string $contentType, Request $request, array $extraHeaders = []): Response
    {
        $path = $this->resolveRepoFile($relativePath);
        if ($path === null) {
            return Response::json(404, ['error' => 'artifact_not_found', 'path' => $relativePath]);
        }

        $size = (int) filesize($path);
        $mtime = (int) filemtime($path);
        $etag = '"' . hash_file('sha256', $path) . '"';
        $lastModified = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';
        $headers = [
            'Content-Type' => $contentType,
            'Content-Length' => (string) $size,
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => $etag,
            'Last-Modified' => $lastModified,
            'Accept-Ranges' => 'bytes',
            'X-Leikwan-Artifact-Response' => 'artifact_response',
        ];
        $headers = array_merge($headers, $extraHeaders);

        $ifNoneMatch = trim((string) ($request->header('If-None-Match', '') ?? ''));
        $ifModifiedSince = trim((string) ($request->header('If-Modified-Since', '') ?? ''));
        if ($ifNoneMatch === $etag || ($ifModifiedSince !== '' && strtotime($ifModifiedSince) !== false && (int) strtotime($ifModifiedSince) >= $mtime)) {
            return new Response(304, $headers, '');
        }

        $range = trim((string) ($request->header('Range', '') ?? ''));
        if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $matches) === 1 && ($matches[1] !== '' || $matches[2] !== '')) {
            $start = null;
            $end = null;
            if ($matches[1] === '') {
                $suffixLength = min((int) $matches[2], $size);
                if ($suffixLength > 0) {
                    $start = $size - $suffixLength;
                    $end = $size - 1;
                }
            } else {
                $requestedStart = (int) $matches[1];
                $requestedEnd = $matches[2] === '' ? $size - 1 : (int) $matches[2];
                if ($requestedStart < $size && $requestedStart <= $requestedEnd) {
                    $start = $requestedStart;
                    $end = min($requestedEnd, $size - 1);
                }
            }
            if ($start !== null && $end !== null && $start <= $end && $start >= 0 && $end < $size) {
                $handle = fopen($path, 'rb');
                if ($handle !== false) {
                    fseek($handle, $start);
                    $body = (string) fread($handle, $end - $start + 1);
                    fclose($handle);
                    $headers['Content-Range'] = 'bytes ' . $start . '-' . $end . '/' . $size;
                    $headers['Content-Length'] = (string) strlen($body);
                    return new Response(206, $headers, $body);
                }
            }
            $headers['Content-Range'] = 'bytes */' . $size;
            $headers['Content-Length'] = '0';
            return new Response(416, $headers, '');
        }

        return new Response(200, $headers, (string) file_get_contents($path));
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
                $absolute = $this->resolveRepoFile($normalized['path']);
                if ($absolute !== null) {
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

        $path = $this->repoRoot . DIRECTORY_SEPARATOR . 'artifacts' . DIRECTORY_SEPARATOR . 'versions.json';
        if (!is_file($path)) {
            $this->versions = [];
            return $this->versions;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        $this->versions = is_array($decoded) ? $decoded : [];
        return $this->versions;
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array<int, string> $keys
     * @return array<string, mixed>
     */
    private function normalizeClientAppArtifactUrls(array $manifest, array $keys): array
    {
        $versions = $this->versions();
        foreach ($keys as $key) {
            if (!isset($manifest[$key]) || !is_array($manifest[$key])) {
                continue;
            }
            $configuredUrl = trim((string) ($versions[$key]['artifact_url'] ?? ''));
            $artifactUrl = $configuredUrl !== ''
                ? $configuredUrl
                : trim((string) ($manifest[$key]['artifact_url'] ?? ''));
            if ($artifactUrl === '') {
                continue;
            }
            $manifest[$key]['artifact_url'] = $this->appendArtifactCacheBust(
                $this->absoluteArtifactUrl($artifactUrl),
                (string) ($manifest[$key]['version'] ?? ''),
                (string) ($manifest[$key]['sha256'] ?? '')
            );
        }
        return $manifest;
    }

    private function absoluteArtifactUrl(string $url): string
    {
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }
        return $this->artifactBaseUrl() . '/' . ltrim($url, '/');
    }

    private function appendArtifactCacheBust(string $url, string $version, string $sha256): string
    {
        $fragment = '';
        if (str_contains($url, '#')) {
            [$url, $fragmentValue] = explode('#', $url, 2);
            $fragment = '#' . $fragmentValue;
        }
        $base = $url;
        $query = '';
        if (str_contains($url, '?')) {
            [$base, $query] = explode('?', $url, 2);
        }
        $pairs = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            $queryKey = rawurldecode(explode('=', $pair, 2)[0]);
            if (in_array(strtolower($queryKey), ['v', 'sha256'], true)) {
                continue;
            }
            $pairs[] = $pair;
        }
        if ($version !== '') {
            $pairs[] = 'v=' . rawurlencode($version);
        }
        if ($sha256 !== '') {
            $pairs[] = 'sha256=' . rawurlencode($sha256);
        }
        return $base . ($pairs === [] ? '' : '?' . implode('&', $pairs)) . $fragment;
    }

    /** @param array<string, mixed> $entry */
    private function artifactEntryPath(array $entry): ?string
    {
        $relativePath = trim((string) ($entry['path'] ?? ''));
        return $relativePath === '' ? null : $this->resolveRepoFile($relativePath);
    }

    private function resolveRepoFile(string $relativePath): ?string
    {
        if ($relativePath === '' || str_contains($relativePath, "\0")) {
            return null;
        }
        $root = realpath($this->repoRoot);
        if ($root === false) {
            return null;
        }
        $candidate = $this->repoRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
        $resolved = realpath($candidate);
        if ($resolved === false || !is_file($resolved)) {
            return null;
        }
        $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($resolved, $rootPrefix)) {
            return null;
        }
        return $resolved;
    }
}
