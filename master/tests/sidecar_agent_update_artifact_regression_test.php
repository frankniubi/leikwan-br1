<?php
declare(strict_types=1);

function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }
$root = dirname(__DIR__, 2);
$artifactSource = file_get_contents($root . '/master/src/Controllers/ArtifactController.php');
$bootstrapSource = file_get_contents($root . '/master/src/Bootstrap.php');
$helper = file_get_contents($root . '/install/sidecar-agent-update.sh');
$agent = file_get_contents($root . '/sidecar-agent/sidecar_agent.py');
if ($artifactSource === false || $bootstrapSource === false || $helper === false || $agent === false) fail('failed to read sidecar update artifacts');
foreach (["public function sidecarAgentUpdate(Request \$request, array \$params): Response", "return \$this->serveRepoFile('install/sidecar-agent-update.sh'", "\$this->router->add('GET', '/artifacts/sidecar-agent-update.sh', [\$artifacts, 'sidecarAgentUpdate']);"] as $snippet) {
    if (!str_contains($artifactSource . $bootstrapSource, $snippet)) fail('missing sidecar update route snippet: ' . $snippet);
}
foreach (['request_token', 'installed_bond_sha256', 'last_completed_request_token', 'installed_version'] as $snippet) {
    if (!str_contains($helper, $snippet)) fail('sidecar update helper missing state field: ' . $snippet);
}
if (!str_contains($agent, 'agent_sha256')) fail('sidecar heartbeat must report agent_sha256 for artifact gates');
if (!str_contains($agent, 'agent_update_state')) fail('sidecar heartbeat must report update helper state');
if (!str_contains($helper, '/artifacts/install-bond-sidecar.sh')) fail('sidecar update helper must route through artifact controller installer');
if (!str_contains($artifactSource, "'sidecar_agent_linux_amd64'")) fail('sidecar manifest must advertise the amd64 frozen agent');
echo "ok\n";
