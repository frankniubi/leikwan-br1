<?php
declare(strict_types=1);

// Source-level wiring for the Client Rule API: routes, handlers, heartbeat capability
// recording, and the pull merge + revision marker (behavior is covered by
// route_direction_client_rules_test.php).

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$bootstrap = file_get_contents(__DIR__ . '/../src/Bootstrap.php') ?: '';
$controller = file_get_contents(__DIR__ . '/../src/Controllers/ClientAgentController.php') ?: '';

foreach ([
    "add('POST', '/api/client/client-rules', [\$clientAgent, 'createClientRule'])",
    "add('PUT', '/api/client/client-rules/{id}', [\$clientAgent, 'updateClientRule'])",
    "add('DELETE', '/api/client/client-rules/{id}', [\$clientAgent, 'deleteClientRule'])",
] as $route) {
    if (!str_contains($bootstrap, $route)) {
        fail('Bootstrap missing route ' . $route);
    }
}

foreach ([
    'public function createClientRule(',
    'public function updateClientRule(',
    'public function deleteClientRule(',
    'CLIENT_RULE_WRITE_LIMIT_PER_MINUTE',
    "'supports_route_direction_rules' => \$request->input('supports_route_direction_rules')",
    "'supports_exact_destination_rules' => \$request->input('supports_exact_destination_rules')",
    "foreach (['supports_mptcp', 'supports_route_direction_rules', 'supports_exact_destination_rules'] as \$boolKey)",
    '[$forwards, $routeRulePayload] = $this->routeRulePullParts($clientId, $client, $forwards);',
    "\$revisionExtra['client_rules']",
    "'route_rule_payload' => \$routeRulePayload,",
    "array_merge(\$responsePayload, \$snapshot['route_rule_payload'])",
] as $snippet) {
    if (!str_contains($controller, $snippet)) {
        fail('ClientAgentController missing ' . $snippet);
    }
}

// Every write handler runs the same device gate as configs/pull before touching rules.
foreach (['createClientRule', 'updateClientRule', 'deleteClientRule'] as $handler) {
    if (preg_match('/function ' . $handler . '\([^{]*\{\s*\$client = Auth::requireClientAgent\(\$request, \$this->db\);\s*\$this->enforceClientRequest\(\$client, \$request, \'request\'\);/', $controller) !== 1) {
        fail($handler . ' must authenticate and enforce the device gate first');
    }
}

echo "ok\n";
