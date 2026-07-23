<?php
declare(strict_types=1);

function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }
$root = dirname(__DIR__, 2);
$artifactSource = file_get_contents($root . '/master/src/Controllers/ArtifactController.php');
$bootstrapSource = file_get_contents($root . '/master/src/Bootstrap.php');
$helper = file_get_contents($root . '/install/leikwan-slave-update');
$agent = file_get_contents($root . '/slave-agent/slave_agent.py');
if ($artifactSource === false || $bootstrapSource === false || $helper === false || $agent === false) fail('failed to read slave update artifacts');
foreach (["public function slaveAgentUpdate(Request \$request, array \$params): Response", "return \$this->serveRepoFile('install/leikwan-slave-update'", "\$this->router->add('GET', '/artifacts/slave-agent-update.sh', [\$artifacts, 'slaveAgentUpdate']);"] as $snippet) {
    if (!str_contains($artifactSource . $bootstrapSource, $snippet)) fail('missing slave update route snippet: ' . $snippet);
}
foreach (['request_token', 'failure_count', 'last_completed_request_token', 'installed_version'] as $snippet) {
    if (!str_contains($helper, $snippet)) fail('slave update helper missing state field: ' . $snippet);
}
if (!str_contains($agent, 'agent_sha256')) fail('slave heartbeat must report agent_sha256 for artifact gates');
if (!str_contains($agent, 'agent_update_state')) fail('slave heartbeat must report update helper state');
if (!str_contains($helper, '/artifacts/install-slave.sh')) fail('slave update helper must route through artifact controller installer');
echo "ok\n";
