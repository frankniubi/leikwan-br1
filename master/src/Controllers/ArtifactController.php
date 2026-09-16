<?php
declare(strict_types=1);

namespace Leikwan\Master\Controllers;

use Leikwan\Master\Http\Request;
use Leikwan\Master\Http\Response;

final class ArtifactController extends BaseController
{
    private const WSTUNNEL_ARTIFACT_DIR = 'artifacts/wstunnel';
    private const STREAM_THRESHOLD_BYTES = 8 * 1024 * 1024;

    /**
     * Digest cache for GET /artifacts/versions.json.
     *
     * That route answers with a sha256 for every published artifact, so a naive
     * implementation re-hashes the whole artifact tree on every request. In
     * production that is 59 entries and 647 MB of SHA-256 (~2.7 s of CPU) per
     * request, which also evicts the page cache on a box that is trying to keep
     * a database in memory. The cache key is (size, mtime, ctime), so a
     * republished artifact invalidates its own entry: a plain overwrite changes
     * mtime and ctime, and even a `cp -p` that preserves mtime changes ctime.
     */
    private const DIGEST_CACHE_RELATIVE_DIR = 'storage/cache';
    private const DIGEST_CACHE_FILE = 'artifact-digests.json';
    private const DIGEST_CACHE_VERSION = 1;

    /** @var array<string, mixed>|null */
    private ?array $versions = null;
    private string $repoRoot;

    /** @var array<string, array{size:int, mtime:int, ctime:int, sha256:string}>|null */
    private static ?array $digestCache = null;
    private bool $digestCacheDirty = false;

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

    /**
     * Standalone Windows repair/update helper.
     *
     * It ships inside the Windows installer too, but stays a first-class
     * artifact so a host stuck on an older build - one that cannot repair its
     * own shared permissions and whose updater stops after the download - can
     * fetch and run it on its own.
     */
    public function windowsUpdateHelper(Request $request, array $params): Response
    {
        return $this->serveRepoFile('scripts/leikwan-bridge-repair.cmd', 'text/plain; charset=utf-8', $request);
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
                $digest = $this->artifactDigest($normalized['path']);
                if ($digest !== null) {
                    $normalized['sha256'] = $digest['sha256'];
                    $normalized['size_bytes'] = $digest['size'];
                    // Some consumers read the legacy 'size' alias; keep it consistent when present.
                    if (array_key_exists('size', $normalized)) {
                        $normalized['size'] = $digest['size'];
                    }
                }
            }
            $manifest[$key] = $normalized;
        }
        $this->flushArtifactDigestCache();

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

    public function ultraClientLinuxArm64(Request $request, array $params): Response
    {
        return $this->serveRepoFile('artifacts/ultra/ultra-client_linux_arm64', 'application/octet-stream', $request);
    }

    public function ultraClientWindows(Request $request, array $params): Response
    {
        return $this->serveRepoFile('artifacts/ultra/ultra-client_windows_amd64.exe', 'application/octet-stream', $request);
    }

    public function ultraClientMacosX64(Request $request, array $params): Response
    {
        return $this->serveRepoFile('artifacts/ultra/ultra-client_macos_amd64', 'application/octet-stream', $request);
    }

    public function ultraClientMacosArm64(Request $request, array $params): Response
    {
        return $this->serveRepoFile('artifacts/ultra/ultra-client_macos_arm64', 'application/octet-stream', $request);
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

    public function externalUotRuntime(Request $request, array $params): Response
    {
        return $this->serveRepoFile('sidecar-agent/leikwan_external_uot.py', 'text/x-python; charset=utf-8', $request);
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

    public function installClientAgentLinuxArm64(Request $request, array $params): Response
    {
        return $this->serveRepoFile(
            'artifacts/install-client-agent-linux-arm64',
            'application/octet-stream',
            $request,
            ['Content-Disposition' => 'attachment; filename="install-client-agent-linux-arm64"']
        );
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

    // Windowless (no-GUI) clients. The Windows payload is its own small
    // self-contained build of the same Core stack; macOS runs the shipped app
    // bundle in its --headless lifecycle, so it only needs the installers.
    public function windowsHeadlessClient(Request $request, array $params): Response
    {
        return $this->serveRepoFile(
            'artifacts/windows/leikwan-bridge-headless-win-x64.zip',
            'application/zip',
            $request,
            ['Content-Disposition' => 'attachment; filename="leikwan-bridge-headless-win-x64.zip"']
        );
    }

    // Linux has had a windowless client (the systemd client-agent) from the
    // start; this is the same `leikwan` command surface the macOS and Windows
    // no-GUI installs use.
    public function leikwanLinuxCli(Request $request, array $params): Response
    {
        return $this->serveRepoFile('install/leikwan-linux.sh', 'text/plain; charset=utf-8', $request);
    }

    public function installMacosHeadless(Request $request, array $params): Response
    {
        return $this->serveRepoFile('install/install-macos-headless.sh', 'text/plain; charset=utf-8', $request);
    }

    public function uninstallMacosHeadless(Request $request, array $params): Response
    {
        return $this->serveRepoFile('install/uninstall-macos-headless.sh', 'text/plain; charset=utf-8', $request);
    }

    public function installWindowsHeadless(Request $request, array $params): Response
    {
        return $this->serveRepoFile('install/install-windows-headless.ps1', 'text/plain; charset=utf-8', $request);
    }

    public function uninstallWindowsHeadless(Request $request, array $params): Response
    {
        return $this->serveRepoFile('install/uninstall-windows-headless.ps1', 'text/plain; charset=utf-8', $request);
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
            'client_agent_installer_linux_arm64',
            'client_rate_limit',
            'wg_watchdog',
            'ultra_client_runtime',
            'ultra_client_runtime_linux_arm64',
            'ultra_client_runtime_windows',
            'mptcp_client_runtime',
            'uot_runtime',
            'uot_bond_runtime',
            'leikwan_uot_bond_linux_amd64',
            'leikwan_uot_bond_linux_arm64',
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
            'windows_update_helper',
            'macos_client_app_arm64',
            'macos_client_app_x64',
            'macos_client_update_arm64',
            'macos_client_update_x64',
            'legacy_client',
            'wstunnel_linux_amd64',
            'wstunnel_linux_arm64',
            'wstunnel_windows_amd64',
            'leikwan_bond_linux_amd64',
            'leikwan_bond_linux_arm64',
            'leikwan_bond_windows_amd64',
            'leikwan_bond_android_arm64',
            'leikwan_ingress_linux_amd64',
            'leikwan_ingress_linux_arm64',
            'leikwan_ingress_windows_amd64',
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
                $length = $end - $start + 1;
                $headers['Content-Range'] = 'bytes ' . $start . '-' . $end . '/' . $size;
                $headers['Content-Length'] = (string) $length;
                if ($length > self::STREAM_THRESHOLD_BYTES) {
                    return Response::streamed(
                        206,
                        $headers,
                        static function () use ($path, $start, $length): void {
                            self::streamFile($path, $start, $length);
                        },
                    );
                }
                $handle = fopen($path, 'rb');
                if ($handle !== false) {
                    try {
                        if (fseek($handle, $start) === 0) {
                            $body = (string) fread($handle, $length);
                            if (strlen($body) === $length) {
                                return new Response(206, $headers, $body);
                            }
                        }
                    } finally {
                        fclose($handle);
                    }
                }
            }
            $headers['Content-Range'] = 'bytes */' . $size;
            $headers['Content-Length'] = '0';
            return new Response(416, $headers, '');
        }

        if ($size > self::STREAM_THRESHOLD_BYTES) {
            return Response::streamed(
                200,
                $headers,
                static function () use ($path, $size): void {
                    self::streamFile($path, 0, $size);
                },
            );
        }

        return new Response(200, $headers, (string) file_get_contents($path));
    }

    private static function streamFile(string $path, int $offset, int $length): void
    {
        $handle = fopen($path, 'rb');
        if ($handle === false || fseek($handle, $offset) !== 0) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            return;
        }

        try {
            set_time_limit(0);
            $remaining = $length;
            while ($remaining > 0 && !feof($handle)) {
                $chunk = fread($handle, min(1024 * 1024, $remaining));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                echo $chunk;
                $remaining -= strlen($chunk);
                flush();
            }
        } finally {
            fclose($handle);
        }
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

    /**
     * sha256 + size for one repo-relative artifact, served from the (size,
     * mtime, ctime) keyed cache when the file has not moved.
     *
     * Returns null when the file is unknown or unreadable, which keeps the
     * previous behaviour of publishing no digest rather than a guess.
     *
     * @return array{sha256:string, size:int}|null
     */
    private function artifactDigest(string $relativePath): ?array
    {
        $absolute = $this->resolveRepoFile($relativePath);
        if ($absolute === null) {
            return null;
        }
        clearstatcache(true, $absolute);
        $size = @filesize($absolute);
        $mtime = @filemtime($absolute);
        $ctime = @filectime($absolute);
        if ($size === false || $mtime === false || $ctime === false) {
            $sha = @hash_file('sha256', $absolute);
            return is_string($sha) && $sha !== '' ? ['sha256' => $sha, 'size' => 0] : null;
        }
        $size = (int) $size;
        $mtime = (int) $mtime;
        $ctime = (int) $ctime;

        $cache = $this->artifactDigestCache();
        $cached = $cache[$relativePath] ?? null;
        if (is_array($cached)
            && (int) ($cached['size'] ?? -1) === $size
            && (int) ($cached['mtime'] ?? -1) === $mtime
            && (int) ($cached['ctime'] ?? -1) === $ctime
            && is_string($cached['sha256'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/', $cached['sha256']) === 1) {
            return ['sha256' => $cached['sha256'], 'size' => $size];
        }

        $sha = @hash_file('sha256', $absolute);
        if (!is_string($sha) || $sha === '') {
            return null;
        }
        self::$digestCache[$relativePath] = [
            'size' => $size,
            'mtime' => $mtime,
            'ctime' => $ctime,
            'sha256' => $sha,
        ];
        $this->digestCacheDirty = true;

        return ['sha256' => $sha, 'size' => $size];
    }

    /** @return array<string, array{size:int, mtime:int, ctime:int, sha256:string}> */
    private function artifactDigestCache(): array
    {
        if (self::$digestCache !== null) {
            return self::$digestCache;
        }
        self::$digestCache = [];
        $path = $this->artifactDigestCachePath();
        if ($path === null || !is_file($path)) {
            return self::$digestCache;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return self::$digestCache;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)
            || (int) ($decoded['version'] ?? 0) !== self::DIGEST_CACHE_VERSION
            || !is_array($decoded['entries'] ?? null)) {
            return self::$digestCache;
        }
        foreach ($decoded['entries'] as $key => $value) {
            if (is_string($key) && is_array($value) && preg_match('/^[a-f0-9]{64}$/', (string) ($value['sha256'] ?? '')) === 1) {
                self::$digestCache[$key] = $value;
            }
        }

        return self::$digestCache;
    }

    /**
     * Persist the cache once per request, and only when a digest was actually
     * recomputed. Every failure is ignored: a cache that cannot be written must
     * never change what the route serves.
     */
    private function flushArtifactDigestCache(): void
    {
        if (!$this->digestCacheDirty || self::$digestCache === null) {
            return;
        }
        $path = $this->artifactDigestCachePath(true);
        if ($path === null) {
            return;
        }
        $payload = json_encode([
            'version' => self::DIGEST_CACHE_VERSION,
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'entries' => self::$digestCache,
        ], JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) {
            return;
        }
        $temporary = $path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($temporary, $payload, LOCK_EX) === false) {
            @unlink($temporary);
            return;
        }
        // Rename is atomic, so a reader never sees a half-written cache.
        @chmod($temporary, 0644);
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            return;
        }
        $this->digestCacheDirty = false;
    }

    private function artifactDigestCachePath(bool $forWrite = false): ?string
    {
        $directory = $this->repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::DIGEST_CACHE_RELATIVE_DIR);
        if (!is_dir($directory) && $forWrite) {
            @mkdir($directory, 0775, true);
        }
        if (!is_dir($directory)) {
            return null;
        }

        return $directory . DIRECTORY_SEPARATOR . self::DIGEST_CACHE_FILE;
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
