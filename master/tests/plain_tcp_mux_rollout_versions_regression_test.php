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

echo "ok\n";
