<?php
declare(strict_types=1);

function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }
$root = dirname(__DIR__, 2);
$artifactSource = file_get_contents($root . '/master/src/Controllers/ArtifactController.php');
$bootstrapSource = file_get_contents($root . '/master/src/Bootstrap.php');
$helper = file_get_contents($root . '/install/client-agent-update.sh');
$agent = file_get_contents($root . '/client-agent/client_agent.py');
if ($artifactSource === false || $bootstrapSource === false || $helper === false || $agent === false) fail('failed to read client update artifacts');
foreach (["public function clientAgentUpdate(Request \$request, array \$params): Response", "return \$this->serveRepoFile('install/client-agent-update.sh'", "\$this->router->add('GET', '/artifacts/client-agent-update.sh', [\$artifacts, 'clientAgentUpdate']);"] as $snippet) {
    if (!str_contains($artifactSource . $bootstrapSource, $snippet)) fail('missing client update route snippet: ' . $snippet);
}
foreach (['request_token', 'status', 'last_attempt_at', 'last_completed_request_token', 'installed_version'] as $snippet) {
    if (!str_contains($helper, $snippet)) fail('client update helper missing state field: ' . $snippet);
}
if (!str_contains($agent, 'agent_sha256')) fail('client heartbeat must report agent_sha256 for artifact gates');
if (!str_contains($agent, 'wstunnel_sha256') || !str_contains($agent, 'wstunnel_version')) fail('client heartbeat must report wstunnel provenance');
if (!str_contains($agent, 'agent_update_state')) fail('client heartbeat must report update helper state');
if (!str_contains($helper, '/artifacts/install-client-agent.sh')) fail('client update helper must route through artifact controller installer');
$artifactController = file_get_contents(__DIR__ . '/../src/Controllers/ArtifactController.php');
if ($artifactController === false || !str_contains($artifactController, "'install_forward_runtime'")) fail('client manifest must advertise install_forward_runtime');
echo "ok\n";
