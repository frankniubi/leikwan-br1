<?php
declare(strict_types=1);

function fail_slave_mptcp_desired_state(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$serviceSource = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');
$adminSource = file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');
$nodeSource = file_get_contents(__DIR__ . '/../src/Controllers/NodeController.php');
$schemaSource = file_get_contents(__DIR__ . '/../migrations/schema.sql');

if ($serviceSource === false || $adminSource === false || $nodeSource === false || $schemaSource === false) {
    fail_slave_mptcp_desired_state('failed to read panel sources');
}

foreach ([
    'schema' => 'slave_wg_ideal_latency_ms INT NULL',
    'admin field' => 'name="slave_wg_ideal_latency_ms"',
    'admin label' => 'Ideal slave WG inner RTT',
    'service persistence' => 'slave_wg_ideal_latency_ms = :slave_wg_ideal_latency_ms',
    'client config query' => 'combo.slave_wg_ideal_latency_ms AS forward_combination_slave_wg_ideal_latency_ms',
    'active endpoint republish desired latency query' => 'combo.desired_latency_ms AS forward_combination_desired_latency_ms',
    'client config output' => "'slave_wg_ideal_latency_ms' =>",
    'payload policy' => "'slave_wg_latency_policy' =>",
    'payload fingerprint' => "'underlay_fingerprint' =>",
    'payload generation' => "'underlay_generation' =>",
    'underlay predicate' => 'private function forwardNeedsSlaveMptcpUnderlay',
    'fingerprint helper' => 'private function stableDesiredStateFingerprint',
    'health ingest' => 'public function recordMptcpUnderlayHealth',
] as $label => $snippet) {
    if (!str_contains($serviceSource . $adminSource . $nodeSource . $schemaSource, $snippet)) {
        fail_slave_mptcp_desired_state("missing {$label}: {$snippet}");
    }
}

if (str_contains($serviceSource, "'mptcp_underlay' => \$clientMode === 'mptcp' ? \$this->buildMptcpSlaveUnderlayPayload")) {
    fail_slave_mptcp_desired_state('mptcp_underlay publish payload must use the desired-state predicate, not client_mode only');
}

if (!str_contains($serviceSource, "\$this->forwardNeedsSlaveMptcpUnderlay(\$clientMode, \$runtimePreference)")
    || !str_contains($serviceSource, 'mptcp_preferred_bond_fallback')) {
    fail_slave_mptcp_desired_state('MPTCP underlay must also be generated for mptcp_preferred_bond_fallback runtime preference');
}

$desiredLatencyAlias = 'combo.desired_latency_ms AS forward_combination_desired_latency_ms';
if (substr_count($serviceSource, $desiredLatencyAlias) < 6) {
    fail_slave_mptcp_desired_state('all active publish/reconcile/client payload paths must select forward_combination_desired_latency_ms for slave WG latency policy fallback');
}

$sidecarMethodPos = strpos($serviceSource, 'public function mptcpSidecarManagementForPoint');
if ($sidecarMethodPos === false) {
    fail_slave_mptcp_desired_state('mptcpSidecarManagementForPoint missing');
}
$sidecarHelperPos = strpos($serviceSource, 'private function mptcpSidecarTransitLegsForPoint');
$sidecarMethod = substr($serviceSource, $sidecarHelperPos !== false ? $sidecarHelperPos : $sidecarMethodPos, 14000);
foreach (['LEFT JOIN forward_combinations combo', 'combo.runtime_preference', 'mptcp_preferred_bond_fallback', "'peer_keepalive_seconds' => 0"] as $snippet) {
    if (!str_contains($sidecarMethod, $snippet)) {
        fail_slave_mptcp_desired_state('sidecar management missing MPTCP fallback/keepalive invariant: ' . $snippet);
    }
}

if (!str_contains($nodeSource, "recordMptcpUnderlayHealth((int) \$node['id'], \$body)")) {
    fail_slave_mptcp_desired_state('node heartbeat must ingest mptcp_underlay_health');
}
if (!str_contains($nodeSource, "\$config['mptcp_underlay_health'] = \$body['mptcp_underlay_health'];")) {
    fail_slave_mptcp_desired_state('node heartbeat must retain mptcp_underlay_health in agent_config_json for sampling');
}

echo "ok\n";
