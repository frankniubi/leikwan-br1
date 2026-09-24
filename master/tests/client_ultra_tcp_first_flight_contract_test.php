<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/Controllers/BaseController.php';
require_once __DIR__ . '/../src/Controllers/ClientAgentController.php';

use Leikwan\Master\Controllers\ClientAgentController;

$generation = str_repeat('a', 64);
$candidate = str_repeat('b', 64);
$grant = ['policy_generation' => $generation, 'expires_at' => gmdate('c', time() + 240)];
$forward = ['target' => '157.228.138.23:62102', 'forward_combination_direction' => 'PEK -> FRA'];
$raw = json_encode([
    'PEK -> FRA' => ['prefix_bytes' => 4, 'delay_ms' => 300, 'candidate_hashes' => [$candidate]],
    'PEK -> MOS' => ['prefix_bytes' => 4, 'delay_ms' => 300, 'candidate_hashes' => [$candidate]],
], JSON_THROW_ON_ERROR);
$actual = ClientAgentController::tcpFirstFlightForGrant($raw, $forward, $grant);
if (!is_array($actual)
    || $actual['target'] !== $forward['target']
    || $actual['policy_generation'] !== $generation
    || $actual['candidate_hashes'] !== [$candidate]
    || $actual['prefix_bytes'] !== 4
    || $actual['delay_ms'] !== 300) {
    throw new RuntimeException('first-flight opt-in was not bound to the grant');
}
if (ClientAgentController::tcpFirstFlightForGrant($raw, [
    'target' => $forward['target'],
    'forward_combination_direction' => 'SHA -> LAX',
], $grant) !== null) {
    throw new RuntimeException('unlisted direction received compatibility mode');
}
if (ClientAgentController::tcpFirstFlightForGrant(json_encode([
    'PEK -> FRA' => ['prefix_bytes' => 4, 'delay_ms' => 300],
], JSON_THROW_ON_ERROR), $forward, $grant) !== null) {
    throw new RuntimeException('unscoped candidates received compatibility mode');
}
if (ClientAgentController::tcpFirstFlightForGrant($raw, $forward, [
    'policy_generation' => $generation,
    'expires_at' => gmdate('c', time() - 1),
]) !== null) {
    throw new RuntimeException('expired grant received compatibility mode');
}
$scoped = static fn (array $targets): string => json_encode([
    'PEK -> FRA' => ['prefix_bytes' => 4, 'delay_ms' => 300, 'candidate_hashes' => [$candidate], 'targets' => $targets],
], JSON_THROW_ON_ERROR);
if (!is_array(ClientAgentController::tcpFirstFlightForGrant($scoped([strtoupper($forward['target'])]), $forward, $grant))) {
    throw new RuntimeException('a listed target did not receive compatibility mode');
}
if (ClientAgentController::tcpFirstFlightForGrant($scoped(['198.51.100.9:443']), $forward, $grant) !== null) {
    throw new RuntimeException('an unlisted target received compatibility mode');
}
if (ClientAgentController::tcpFirstFlightForGrant($scoped([]), $forward, $grant) !== null) {
    throw new RuntimeException('an empty target allowlist received compatibility mode');
}
echo "PASS\n";
