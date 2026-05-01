<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$controllerPath = __DIR__ . '/../src/Controllers/SidecarAgentController.php';
$controllerSource = file_get_contents($controllerPath);
if (!is_string($controllerSource) || $controllerSource === '') {
    fail('failed to read SidecarAgentController source');
}

if (!str_contains($controllerSource, "'config_patch' => [")) {
    fail('expected sidecar heartbeat management to include config_patch');
}

if (!str_contains($controllerSource, "'state_dir' => '/var/lib/leikwan-sidecar-'")) {
    fail('expected sidecar config_patch to set a point-specific state_dir');
}

if (!str_contains($controllerSource, "'runtime_config_path' => '/etc/leikwan-sidecar-'")) {
    fail('expected sidecar config_patch to set a point-specific runtime_config_path');
}

if (!str_contains($controllerSource, "'udp_uot_sidepath' => \$runtimeState['udp_uot_sidepath'] ?? null")) {
    fail('expected sidecar heartbeat management to preserve udp_uot_sidepath in config_patch');
}

if (!str_contains($controllerSource, "'mode' => \$hasMptcpRuntime ? 'mptcp' : 'bond'")) {
    fail('expected sidecar heartbeat management mode to switch between bond and mptcp');
}

$provisioningPath = __DIR__ . '/../src/Support/ProvisioningService.php';
$provisioningSource = file_get_contents($provisioningPath);
if (!is_string($provisioningSource) || $provisioningSource === '') {
    fail('failed to read ProvisioningService source');
}

if (!str_contains($provisioningSource, "stableLocalUotUdpPort")) {
    fail('expected ProvisioningService to define stableLocalUotUdpPort');
}

if (!str_contains($provisioningSource, "'local_uot_udp_port' =>")) {
    fail('expected ProvisioningService to emit local_uot_udp_port in MPTCP legs');
}

if (!str_contains($provisioningSource, "getBool('MPTCP_ALLOW_TCP_FALLBACK', true)")) {
    fail('expected ProvisioningService MPTCP client config to default allow_tcp_fallback to true');
}

$sidecarAgentPath = __DIR__ . '/../../sidecar-agent/sidecar_agent.py';
$sidecarAgentSource = file_get_contents($sidecarAgentPath);
if (!is_string($sidecarAgentSource) || $sidecarAgentSource === '') {
    fail('failed to read sidecar_agent.py source');
}

if (!str_contains($sidecarAgentSource, '"allow_tcp_fallback": True')) {
    fail('expected sidecar-agent default allow_tcp_fallback to be True');
}

echo "ok\n";
