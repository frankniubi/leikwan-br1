<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

final class LeigodOnlyArtifactSourceBuildMap
{
    /** @var array<string, array<string, mixed>> */
    private array $activationCritical;
    /** @var array<string, array<string, mixed>> */
    private array $routeOnlyHelpers;

    public function __construct(private ?string $repoRoot = null)
    {
        $this->repoRoot = $repoRoot !== null ? rtrim($repoRoot, DIRECTORY_SEPARATOR . '/') : dirname(__DIR__, 3);
        $this->activationCritical = [
            'client_agent' => ['paths' => ['client-agent/client_agent.py'], 'route_path' => '/artifacts/client-agent.py', 'platform_family' => 'linux-bond'],
            'client_agent_linux_amd64' => ['paths' => ['client-agent', 'artifacts/python-agent/leikwan-client-agent_linux_amd64'], 'route_path' => '/artifacts/python-agent/leikwan-client-agent_linux_amd64', 'platform_family' => 'linux-bond'],
            'client_agent_linux_arm64' => ['paths' => ['client-agent', 'artifacts/python-agent/leikwan-client-agent_linux_arm64'], 'route_path' => '/artifacts/python-agent/leikwan-client-agent_linux_arm64', 'platform_family' => 'linux-bond'],
            'slave_agent' => ['paths' => ['slave-agent/slave_agent.py'], 'route_path' => '/artifacts/slave-agent.py', 'platform_family' => 'linux-bond'],
            'leigod_forward_relay' => ['paths' => ['slave-agent/leigod_forward_relay.py'], 'route_path' => '/artifacts/leikwan-leigod-forward-relay.py', 'platform_family' => 'linux-bond'],
            'sidecar_agent' => ['paths' => ['sidecar-agent/sidecar_agent.py'], 'route_path' => '/artifacts/sidecar-agent.py', 'platform_family' => 'linux-bond'],
            'sidecar_agent_linux_amd64' => ['paths' => ['sidecar-agent', 'client-agent/leikwan_uot.py', 'artifacts/python-agent/leikwan-sidecar-agent_linux_amd64'], 'route_path' => '/artifacts/python-agent/leikwan-sidecar-agent_linux_amd64', 'platform_family' => 'linux-bond'],
            'sidecar_agent_linux_arm64' => ['paths' => ['sidecar-agent', 'client-agent/leikwan_uot.py', 'artifacts/python-agent/leikwan-sidecar-agent_linux_arm64'], 'route_path' => '/artifacts/python-agent/leikwan-sidecar-agent_linux_arm64', 'platform_family' => 'linux-bond'],
            'gost_linux_amd64' => ['paths' => ['artifacts/gost/gost_linux_amd64'], 'route_path' => '/artifacts/gost/gost_linux_amd64', 'platform_family' => 'linux-bond', 'immutable_provenance' => true],
            'leigod_linux_amd64' => ['paths' => ['artifacts/leigod/leigod-linux-amd64'], 'route_path' => '/artifacts/leigod/leigod-linux-amd64', 'platform_family' => 'linux-bond', 'immutable_provenance' => true],
            'lguot_linux_amd64' => ['paths' => ['artifacts/lguot/lguot_linux_amd64'], 'route_path' => '/artifacts/lguot/lguot_linux_amd64', 'platform_family' => 'linux-bond', 'immutable_provenance' => true],
            'leikwan_bond_linux_amd64' => ['paths' => ['wstunnel-main/leikwan-bond', 'artifacts/wstunnel/leikwan-bond_linux_amd64'], 'route_path' => '/artifacts/wstunnel/leikwan-bond_linux_amd64', 'platform_family' => 'linux-bond'],
            'leikwan_bond_linux_arm64' => ['paths' => ['wstunnel-main/leikwan-bond', 'artifacts/wstunnel/leikwan-bond_linux_arm64'], 'route_path' => '/artifacts/wstunnel/leikwan-bond_linux_arm64', 'platform_family' => 'linux-bond'],
            'leikwan_uot_bond_linux_amd64' => ['paths' => ['wstunnel-main/leikwan-uot', 'artifacts/wstunnel/leikwan-uot-bond_linux_amd64'], 'route_path' => '/artifacts/wstunnel/leikwan-uot-bond_linux_amd64', 'platform_family' => 'linux-bond'],
            'leikwan_uot_bond_linux_arm64' => ['paths' => ['wstunnel-main/leikwan-uot', 'artifacts/wstunnel/leikwan-uot-bond_linux_arm64'], 'route_path' => '/artifacts/wstunnel/leikwan-uot-bond_linux_arm64', 'platform_family' => 'linux-bond'],
            'leikwan_ingress_linux_amd64' => ['paths' => ['wstunnel-main/leikwan-ingress', 'artifacts/wstunnel/leikwan-ingress_linux_amd64'], 'route_path' => '/artifacts/wstunnel/leikwan-ingress_linux_amd64', 'platform_family' => 'linux-bond'],
            'leikwan_ingress_linux_arm64' => ['paths' => ['wstunnel-main/leikwan-ingress', 'artifacts/wstunnel/leikwan-ingress_linux_arm64'], 'route_path' => '/artifacts/wstunnel/leikwan-ingress_linux_arm64', 'platform_family' => 'linux-bond'],
            'leikwan_udp_uot_merge_linux_amd64' => ['paths' => ['wstunnel-main/leikwan-uot', 'artifacts/wstunnel/leikwan-udp-uot-merge_linux_amd64'], 'route_path' => '/artifacts/wstunnel/leikwan-udp-uot-merge_linux_amd64', 'platform_family' => 'linux-bond'],
            'windows_client_app' => ['paths' => ['wstunnel-main/leikwan-client-win', 'artifacts/windows/leikwan-bridge-setup.exe'], 'route_path' => '/artifacts/leikwan-bridge-setup.exe', 'platform_family' => 'windows-bond'],
            'leikwan_bond_windows_amd64' => ['paths' => ['wstunnel-main/leikwan-bond', 'artifacts/wstunnel/leikwan-bond_windows_amd64.exe'], 'route_path' => '/artifacts/wstunnel/leikwan-bond_windows_amd64.exe', 'platform_family' => 'windows-bond'],
            'leikwan_uot_bond_windows_amd64' => ['paths' => ['wstunnel-main/leikwan-uot', 'artifacts/wstunnel/leikwan-uot-bond_windows_amd64.exe'], 'route_path' => '/artifacts/wstunnel/leikwan-uot-bond_windows_amd64.exe', 'platform_family' => 'windows-bond'],
        ];
        $this->routeOnlyHelpers = [
            'client_agent_update' => ['paths' => ['install/client-agent-update.sh'], 'route_path' => '/artifacts/client-agent-update.sh'],
            'sidecar_agent_update' => ['paths' => ['install/sidecar-agent-update.sh'], 'route_path' => '/artifacts/sidecar-agent-update.sh'],
            'slave_agent_update' => ['paths' => ['install/leikwan-slave-update'], 'route_path' => '/artifacts/slave-agent-update.sh'],
            'forward_strategy' => ['paths' => ['client-agent/forward_strategy.py', 'artifacts/forward-strategy.py'], 'route_path' => '/artifacts/forward-strategy.py'],
            'ultra_client_runtime' => [
                'paths' => ['tools/ultra-client', 'artifacts/ultra/ultra-client_linux_amd64'],
                'route_path' => '/artifacts/ultra/ultra-client_linux_amd64',
            ],
            'ultra_client_runtime_linux_arm64' => [
                'paths' => ['tools/ultra-client', 'artifacts/ultra/ultra-client_linux_arm64'],
                'route_path' => '/artifacts/ultra/ultra-client_linux_arm64',
                'platform_family' => 'linux-bond',
            ],
            'wstunnel_linux_arm64' => [
                'paths' => ['.build/tool-sources/wstunnel-v10.5.2', 'artifacts/wstunnel/wstunnel_linux_arm64'],
                'route_path' => '/artifacts/wstunnel/wstunnel_linux_arm64',
                'platform_family' => 'linux-bond',
            ],
            'client_agent_installer_linux_arm64' => [
                'paths' => ['tools/linux-arm64-installer', 'artifacts/install-client-agent-linux-arm64'],
                'route_path' => '/artifacts/install-client-agent-linux-arm64',
                'platform_family' => 'linux-bond',
            ],
            'ultra_client_runtime_windows' => [
                'paths' => ['tools/ultra-client', 'artifacts/ultra/ultra-client_windows_amd64.exe'],
                'route_path' => '/artifacts/ultra/ultra-client_windows_amd64.exe',
                'platform_family' => 'windows-bond',
            ],
            // ultra_client_runtime_macos_x64 is retired (Intel macOS dropped): its
            // artifact is frozen at runtime 2026.10.12.1 and is never rebuilt from source.
            'ultra_client_runtime_macos_arm64' => [
                'paths' => ['tools/ultra-client', 'artifacts/ultra/ultra-client_macos_arm64'],
                'route_path' => '/artifacts/ultra/ultra-client_macos_arm64',
                'platform_family' => 'macos-bond',
            ],
            'ultra_client_runtime_android_arm64' => [
                'paths' => ['tools/ultra-client', 'artifacts/ultra/ultra-client_android_arm64'],
                'route_path' => '/artifacts/ultra/ultra-client_android_arm64',
                'platform_family' => 'android-bond',
            ],
            'ultra_client_runtime_android_x86_64' => [
                'paths' => ['tools/ultra-client', 'artifacts/ultra/ultra-client_android_x86_64'],
                'route_path' => '/artifacts/ultra/ultra-client_android_x86_64',
                'platform_family' => 'android-bond',
            ],
            'tcp_accel_runtime' => ['paths' => ['tools/tcp-accel/tcp_accel.py'], 'route_path' => '/artifacts/leikwan-tcp-accel.py'],
            'tcp_accel_bridge_runtime' => ['paths' => ['slave-agent/leigod_tcp_accel_bridge.py'], 'route_path' => '/artifacts/leikwan-tcp-accel-bridge.py'],
            'tcp_accel_installer' => ['paths' => ['install/install-tcp-accel.sh'], 'route_path' => '/artifacts/install-tcp-accel.sh'],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function activationCriticalMap(): array
    {
        return $this->activationCritical;
    }

    /** @return array<string, array<string, mixed>> */
    public function routeOnlyHelperMap(): array
    {
        return $this->routeOnlyHelpers;
    }

    public function requiresImmutableProvenance(string $key): bool
    {
        return !empty(($this->activationCritical[$key] ?? [])['immutable_provenance']);
    }

    public function routePath(string $key): string
    {
        $entry = $this->activationCritical[$key] ?? $this->routeOnlyHelpers[$key] ?? [];
        return (string) ($entry['route_path'] ?? '');
    }

    public function sourceFingerprint(string $key): string
    {
        $entry = $this->activationCritical[$key] ?? $this->routeOnlyHelpers[$key] ?? null;
        if (!is_array($entry)) {
            return '';
        }
        $relativePaths = [];
        foreach (($entry['paths'] ?? []) as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }
            $absolute = $this->absolutePath($path);
            if (is_file($absolute)) {
                $relativePaths[] = $this->normalizePath($path);
            } elseif (is_dir($absolute)) {
                foreach ($this->filesUnder($absolute, $this->normalizePath($path)) as $relative) {
                    $relativePaths[] = $relative;
                }
            }
        }
        $relativePaths = array_values(array_unique($relativePaths));
        sort($relativePaths, SORT_STRING);
        $parts = [];
        foreach ($relativePaths as $relative) {
            $absolute = $this->absolutePath($relative);
            if (is_file($absolute)) {
                $parts[] = $relative . ':' . hash_file('sha256', $absolute);
            }
        }
        return hash('sha256', implode("\n", $parts));
    }

    public function buildOutputSha256(string $key): string
    {
        $routePath = $this->routePath($key);
        $manifestPath = $this->repoRoot . DIRECTORY_SEPARATOR . 'artifacts' . DIRECTORY_SEPARATOR . 'versions.json';
        if (is_file($manifestPath)) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (is_array($manifest) && isset($manifest[$key]) && is_array($manifest[$key])) {
                $entry = $manifest[$key];
                $path = is_string($entry['path'] ?? null) ? $entry['path'] : '';
                if ($path !== '') {
                    $absolute = $this->absolutePath($path);
                    if (is_file($absolute)) {
                        return hash_file('sha256', $absolute);
                    }
                }
            }
        }
        if ($routePath !== '') {
            $candidate = $this->absolutePath(ltrim($routePath, '/'));
            if (is_file($candidate)) {
                return hash_file('sha256', $candidate);
            }
        }
        return '';
    }

    private function absolutePath(string $relative): string
    {
        return $this->repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', trim($path, '/\\'));
    }

    /** @return list<string> */
    private function filesUnder(string $absoluteDir, string $relativeDir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absoluteDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            $relative = $relativeDir . '/' . str_replace('\\', '/', substr($file->getPathname(), strlen($absoluteDir) + 1));
            if (str_contains($relative, '/target/') || str_contains($relative, '/.git/')) {
                continue;
            }
            $files[] = $relative;
        }
        return $files;
    }
}
