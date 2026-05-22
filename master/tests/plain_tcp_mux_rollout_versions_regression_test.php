<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$repoRoot = dirname(__DIR__, 2);
$clientSource = file_get_contents($repoRoot . '/client-agent/client_agent.py');
$slaveSource = file_get_contents($repoRoot . '/slave-agent/slave_agent.py');
$versionsRaw = file_get_contents($repoRoot . '/artifacts/versions.json');
$versions = is_string($versionsRaw) ? json_decode($versionsRaw, true) : null;

if (!is_string($clientSource) || !preg_match('/AGENT_VERSION = "([^"]+)"/', $clientSource, $clientMatch)) {
    fail('failed to read client agent version');
}

if (!is_string($slaveSource) || !preg_match('/AGENT_VERSION = "([^"]+)"/', $slaveSource, $slaveMatch)) {
    fail('failed to read slave agent version');
}

if (!is_array($versions)) {
    fail('failed to parse versions manifest');
}

if (($versions['client_agent']['version'] ?? null) !== $clientMatch[1]) {
    fail('client_agent.version must match client_agent.py AGENT_VERSION');
}

if (($versions['slave_agent']['version'] ?? null) !== $slaveMatch[1]) {
    fail('slave_agent.version must match slave_agent.py AGENT_VERSION');
}

if (($versions['slave_preconnect_relay']['path'] ?? null) !== 'slave-agent/preconnect_relay.py') {
    fail('slave_preconnect_relay artifact must point to slave-agent/preconnect_relay.py');
}

$installSlave = file_get_contents($repoRoot . '/install/install-slave.sh');
if (!is_string($installSlave) || !str_contains($installSlave, 'leikwan-preconnect-relay.py')) {
    fail('install-slave.sh must install the preconnect relay helper');
}

$bootstrap = file_get_contents($repoRoot . '/master/src/Bootstrap.php');
if (!is_string($bootstrap) || !str_contains($bootstrap, '/artifacts/leikwan-preconnect-relay.py')) {
    fail('Bootstrap must expose the preconnect relay artifact route');
}

echo "ok\n";
