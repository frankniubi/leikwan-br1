<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/Controllers/BaseController.php';
require_once __DIR__ . '/../src/Controllers/ClientAgentController.php';
require_once __DIR__ . '/../src/Support/UltraCandidateReputationService.php';

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

// Without candidate_hashes the rule covers every candidate of the forward's
// own lines, with the exact hash the lease plane issues (checked against a
// real production candidate: PEK->FRA line 693, tcp).
$wildcard = json_encode([
    'PEK -> FRA' => ['prefix_bytes' => 4, 'delay_ms' => 300],
], JSON_THROW_ON_ERROR);
$lines = [
    ['source_id' => 'source-home-171', 'line_id' => 693, 'ingress_zone' => 'beijing', 'destination_zone' => 'frankfurt'],
    ['source_id' => 'source-home-171', 'line_id' => 455, 'ingress_zone' => 'beijing', 'destination_zone' => 'frankfurt'],
];
$derived = ClientAgentController::tcpFirstFlightForGrant($wildcard, $forward, $grant + ['transport' => 'tcp'], $lines);
if (!is_array($derived)
    || $derived['candidate_hashes'] !== [
        'd414cb742989356f4af0211214240f3c9de4c0224315dba0311770457b9f5967',
        'feb4fba311c51430e59320f8f105b167fbb8baa2d9a9f6818cfd25cdcdbca61d',
    ]) {
    throw new RuntimeException('derived first-flight candidates do not match the lease-plane hashes: ' . json_encode($derived));
}
$combined = ClientAgentController::tcpFirstFlightForGrant($wildcard, $forward, $grant + ['transport' => 'tcp+udp'], $lines);
if (!is_array($combined) || in_array('d414cb742989356f4af0211214240f3c9de4c0224315dba0311770457b9f5967', $combined['candidate_hashes'], true)) {
    throw new RuntimeException('a tcp+udp forward must use tcp+udp candidate hashes');
}
if (ClientAgentController::tcpFirstFlightForGrant($wildcard, $forward, $grant + ['transport' => 'udp'], $lines) !== null) {
    throw new RuntimeException('a UDP-only forward received TCP first-flight');
}
if (ClientAgentController::tcpFirstFlightForGrant($wildcard, $forward, $grant + ['transport' => 'tcp'], []) !== null) {
    throw new RuntimeException('a forward without lines received first-flight');
}
echo "PASS\n";
