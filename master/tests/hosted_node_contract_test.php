<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$root = __DIR__ . '/..';
$bootstrap = (string) file_get_contents($root . '/src/Bootstrap.php');
$base = (string) file_get_contents($root . '/src/Controllers/BaseController.php');
$clientAgent = (string) file_get_contents($root . '/src/Controllers/ClientAgentController.php');
$controller = (string) file_get_contents($root . '/src/Controllers/HostedNodeController.php');
$migration = (string) file_get_contents($root . '/migrations/20261012_add_client_hosted_nodes.sql');
$schema = (string) file_get_contents($root . '/migrations/schema.sql');
if ($bootstrap === '' || $clientAgent === '' || $controller === '' || $migration === '' || $schema === '') {
    fail('failed to read hosted node sources');
}

foreach ([
    "\$this->router->add('GET', '/api/client/hosted-nodes', [\$hostedNodes, 'clientList']);",
    "\$this->router->add('POST', '/api/client/hosted-nodes', [\$hostedNodes, 'clientCreate']);",
    "\$this->router->add('POST', '/api/client/hosted-nodes/batch', [\$hostedNodes, 'clientBatchCreate']);",
    "\$this->router->add('PUT', '/api/client/hosted-nodes/{id}', [\$hostedNodes, 'clientUpdate']);",
    "\$this->router->add('DELETE', '/api/client/hosted-nodes/{id}', [\$hostedNodes, 'clientDelete']);",
    "\$this->router->add('PUT', '/api/client/hosted-node-pairings/{forwardId}', [\$hostedNodes, 'clientPair']);",
    "\$this->router->add('GET', '/tenant/hosted-nodes', [\$hostedNodes, 'tenantPage']);",
    "\$this->router->add('POST', '/tenant/hosted-nodes', [\$hostedNodes, 'tenantCreate']);",
    "\$this->router->add('POST', '/tenant/hosted-nodes/pairings', [\$hostedNodes, 'tenantPair']);",
    "\$this->router->add('POST', '/tenant/hosted-nodes/{id}/delete', [\$hostedNodes, 'tenantDelete']);",
] as $route) {
    if (!str_contains($bootstrap, $route)) {
        fail('missing route: ' . $route);
    }
}

if (!str_contains($base, "'href' => '/tenant/hosted-nodes', 'label' => 'My Nodes'")) {
    fail('tenant navigation must link the hosted nodes page');
}

// Every client endpoint goes through the trusted-device gate before acting.
foreach (['clientList', 'clientCreate', 'clientBatchCreate', 'clientUpdate', 'clientDelete', 'clientPair'] as $method) {
    $start = strpos($controller, 'public function ' . $method . '(');
    $end = strpos($controller, 'public function ', $start + 10);
    $bodyText = substr($controller, (int) $start, (int) ($end ?: strlen($controller)) - (int) $start);
    if ($start === false || !str_contains($bodyText, '$client = $this->trustedClient($request);')) {
        fail($method . ' must start with the trusted-device gate');
    }
}
foreach (['Auth::requireClientAgent(', '->authorizeClientRequest(', '->requireActive($client, $request)', '->enforceFromRequest(', "'android'"] as $gate) {
    if (!str_contains($controller, $gate)) {
        fail('trusted-device gate is missing ' . $gate);
    }
}
// Desktop: same identity as the desktop pull, plus the platform's own device
// id format; an attested Android id never takes the desktop path.
foreach ([
    '$this->deviceControls->isManagedDeviceId($headerDeviceId)',
    "foreach (['windows', 'macos'] as \$family)",
    "'/^windows-client-([1-9][0-9]*)-[a-f0-9]{16}$/'",
    "'/^macos-client-[a-f0-9]{32}$/'",
] as $gate) {
    if (!str_contains($controller, $gate)) {
        fail('desktop gate is missing ' . $gate);
    }
}
if (str_contains($controller, "'linux'") || str_contains($controller, 'linux-[a-f0-9]')) {
    fail('Linux headless must stay closed until the Linux agent has a nodes feature');
}
foreach (['public function tenantCreate(', 'public function tenantDelete(', 'public function tenantPair('] as $method) {
    $start = strpos($controller, $method);
    $bodyText = substr($controller, (int) $start, 600);
    if ($start === false || !str_contains($bodyText, 'SessionAuth::validateCsrf($request);')) {
        fail($method . ' must validate CSRF');
    }
}

// The pull advertises only a change marker, never node material.
foreach ([
    "'hosted_nodes_revision' => (string) (\$snapshot['hosted_nodes_revision'] ?? ''),",
    "\$revisionExtra['hosted_nodes'] = \$hostedNodesRevision;",
    'if ($hostedNodesRevision !== \'\') {',
    'HostedNodeService::fromConfig($this->config, $this->db)->revisionForClient($client);',
] as $needle) {
    if (!str_contains($clientAgent, $needle)) {
        fail('pull contract missing: ' . $needle);
    }
}
if (str_contains($clientAgent, 'clientListing(')) {
    fail('the config pull must not embed hosted node credentials');
}

// Migration: two tables, no FKs (a failing FK would 500 every request via auto-migrations).
foreach (['CREATE TABLE IF NOT EXISTS client_hosted_nodes', 'CREATE TABLE IF NOT EXISTS client_hosted_node_pairings'] as $table) {
    if (!str_contains($migration, $table) || !str_contains($schema, $table)) {
        fail('migration and schema.sql must both create: ' . $table);
    }
}
$migrationSql = (string) preg_replace('/^\s*--.*$/m', '', $migration);
if (stripos($migrationSql, 'FOREIGN KEY') !== false || stripos($migrationSql, 'REFERENCES') !== false || stripos($migrationSql, 'ALTER TABLE') !== false) {
    fail('hosted node migration must only create new tables, without foreign keys');
}
foreach (['sealed_config TEXT NOT NULL', 'fingerprint CHAR(64) NOT NULL', 'UNIQUE KEY uniq_client_hosted_node_fingerprint (client_id, fingerprint)', 'PRIMARY KEY (client_id, forward_id)'] as $column) {
    if (!str_contains($migration, $column)) {
        fail('migration missing: ' . $column);
    }
}
foreach (['password', 'uuid', 'server_host', 'secret'] as $clearColumn) {
    if (preg_match('/^\s*' . $clearColumn . '\s/m', $migration) === 1) {
        fail('credentials must not get a clear column: ' . $clearColumn);
    }
}

foreach (['zh-Hans', 'zh-TW'] as $locale) {
    $dictionary = json_decode((string) file_get_contents($root . '/public/i18n/' . $locale . '.json'), true);
    foreach (['My Nodes', 'Add a node', 'Pairing', 'No hosted nodes yet.'] as $key) {
        if (trim((string) ($dictionary['text'][$key] ?? '')) === '') {
            fail('missing ' . $locale . ' translation: ' . $key);
        }
    }
}

echo "ok\n";
