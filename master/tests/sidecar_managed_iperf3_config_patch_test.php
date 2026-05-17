<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Controllers/SidecarAgentController.php');
if (!is_string($source) || $source === '') {
    fail('failed to read SidecarAgentController source');
}

if (!str_contains($source, "'managed_iperf3_ports' =>")) {
    fail('expected sidecar management config_patch to pass managed_iperf3_ports from agent_config_json');
}

if (!str_contains($source, "\$runtimeState['managed_iperf3_ports']")) {
    fail('expected managed_iperf3_ports to be DB/config driven, not hard-coded');
}

if (!str_contains($source, '$sidecarAgentUpdatesEnabled')) {
    fail('expected sidecar agent artifact updates to be gated by sidecar_agent_update_enabled');
}

if (!str_contains($source, '\'desired_sidecar_agent_sha256\' => $sidecarAgentUpdatesEnabled ?')) {
    fail('expected desired_sidecar_agent_sha256 to be empty unless sidecar agent update is enabled');
}

echo "ok\n";
