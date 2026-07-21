<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    fail('PHP warning/error during leg policy test: ' . $message . ' at ' . $file . ':' . $line);
});

$repoRoot = dirname(__DIR__, 2);
$policyPath = $repoRoot . '/master/src/Support/StandaloneBondLegPolicy.php';
if (!is_file($policyPath)) {
    fail('expected StandaloneBondLegPolicy.php to exist');
}
require_once $policyPath;

$policy = \Leikwan\Master\Support\StandaloneBondLegPolicy::class;

function leg(int $nodeId, int $endpointId, array $overrides = []): array
{
    $base = [
        'forward_id' => 47,
        'desired_generation' => 12,
        'node_id' => $nodeId,
        'endpoint_id' => $endpointId,
        'protocol' => 'tcp',
        'logical_leg_key' => 'bond:47:tcp:' . $nodeId . ':' . $endpointId,
        'usable' => true,
        'enabled' => true,
        'status' => 'active',
    ];
    return array_replace($base, $overrides);
}

function assertOutcome(array $classification, string $outcome, string $label): void
{
    if (($classification['outcome'] ?? null) !== $outcome) {
        fail($label . ' expected outcome ' . $outcome . ', got ' . (string) ($classification['outcome'] ?? '<missing>'));
    }
}

function assertInvalidReason(string $label, array $rows, string $reason): void
{
    $policy = \Leikwan\Master\Support\StandaloneBondLegPolicy::class;
    $classification = $policy::classify(47, 12, $rows);
    assertOutcome($classification, 'invalid_speed_plus_leg_set', $label);
    if (($classification['reason'] ?? '') !== $reason) {
        fail($label . ' expected reason ' . $reason . ', got ' . (string) ($classification['reason'] ?? '<missing>'));
    }
}

$missingNodeId = leg(1, 101);
unset($missingNodeId['node_id']);
assertInvalidReason('missing node_id', [$missingNodeId], 'missing_node_id');
assertInvalidReason('null node_id', [leg(1, 101, ['node_id' => null])], 'missing_node_id');

$missingEndpointId = leg(1, 101);
unset($missingEndpointId['endpoint_id']);
assertInvalidReason('missing endpoint_id', [$missingEndpointId], 'missing_endpoint_id');
assertInvalidReason('null endpoint_id', [leg(1, 101, ['endpoint_id' => null])], 'missing_endpoint_id');

$noUsable = $policy::classify(47, 12, [
    leg(1, 101, ['usable' => false]),
    leg(2, 102, ['status' => 'disabled']),
]);
assertOutcome($noUsable, 'invalid_speed_plus_leg_set', 'no usable slaves');
if (($noUsable['reason'] ?? '') !== 'no_usable_legs' || ($noUsable['usable_slave_count'] ?? -1) !== 0) {
    fail('expected no usable legs reason and zero usable slave count');
}

$single = $policy::classify(47, 12, [leg(1, 101), leg(2, 102, ['enabled' => false])]);
assertOutcome($single, 'single_leg_only', 'single usable slave');
if (($single['usable_slave_count'] ?? null) !== 1 || ($single['canonical_leg_keys'] ?? []) !== ['bond:47:tcp:1:101']) {
    fail('expected single canonical usable leg');
}

$eligible = $policy::classify(47, 12, [leg(8, 208), leg(2, 102), leg(5, 105)]);
assertOutcome($eligible, 'speed_plus_eligible', 'eligible distinct slaves');
if (($eligible['usable_slave_count'] ?? null) !== 3 || ($eligible['usable_leg_count'] ?? null) !== 3) {
    fail('expected three usable distinct slaves');
}
if (($eligible['distinct_node_ids'] ?? []) !== [2, 5, 8]) {
    fail('expected sorted distinct node ids');
}
if (($eligible['canonical_leg_keys'] ?? []) !== ['bond:47:tcp:2:102', 'bond:47:tcp:5:105', 'bond:47:tcp:8:208']) {
    fail('expected sorted canonical leg keys');
}

$oneNodeMultiEndpoint = $policy::classify(47, 12, [leg(7, 701), leg(7, 702)]);
assertOutcome($oneNodeMultiEndpoint, 'speed_plus_requires_distinct_slave_nodes', 'one slave with multiple endpoints');

$tooManyRows = [];
for ($i = 1; $i <= 9; $i++) {
    $tooManyRows[] = leg($i, 100 + $i);
}
$tooMany = $policy::classify(47, 12, $tooManyRows);
assertOutcome($tooMany, 'too_many_speed_plus_legs', 'nine distinct usable slaves');

foreach ([
    'duplicate key' => [leg(1, 101), leg(1, 101)],
    'wrong forward' => [leg(1, 101, ['forward_id' => 48])],
    'wrong generation' => [leg(1, 101, ['desired_generation' => 13])],
    'wrong protocol field' => [leg(1, 101, ['protocol' => 'udp', 'logical_leg_key' => 'bond:47:udp:1:101'])],
    'wrong protocol key' => [leg(1, 101, ['logical_leg_key' => 'bond:47:udp:1:101'])],
    'malformed key' => [leg(1, 101, ['logical_leg_key' => 'not-a-logical-leg-key'])],
    'missing forward id' => [leg(1, 101, ['forward_id' => null])],
    'missing generation' => [leg(1, 101, ['desired_generation' => null])],
    'missing protocol' => [leg(1, 101, ['protocol' => null])],
    'missing logical leg key' => [leg(1, 101, ['logical_leg_key' => null])],
] as $label => $rows) {
    $classification = $policy::classify(47, 12, $rows);
    assertOutcome($classification, 'invalid_speed_plus_leg_set', $label);
    if (($classification['reason'] ?? '') === '') {
        fail($label . ' expected deterministic invalid reason');
    }
}

$hashA = $policy::canonicalLegSetHash(47, 12, [leg(1, 101), leg(2, 102), leg(3, 103)]);
$hashB = $policy::canonicalLegSetHash(47, 12, [leg(3, 103), leg(1, 101), leg(2, 102)]);
if (!is_string($hashA) || strlen($hashA) !== 64 || !ctype_xdigit($hashA)) {
    fail('expected sha256 hex canonical leg-set hash');
}
if ($hashA !== $hashB) {
    fail('expected canonical leg-set hash to be stable independent of row order');
}
$hashDifferentGeneration = $policy::canonicalLegSetHash(47, 13, [
    leg(1, 101, ['desired_generation' => 13]),
    leg(2, 102, ['desired_generation' => 13]),
]);
if ($hashA === $hashDifferentGeneration) {
    fail('expected generation to participate in canonical leg-set hash');
}

$invalidHashA = $policy::canonicalLegSetHash(47, 12, [
    leg(1, 101),
    leg(2, 102, ['logical_leg_key' => 'not-a-logical-leg-key']),
]);
$invalidHashB = $policy::canonicalLegSetHash(47, 12, [
    leg(2, 102, ['logical_leg_key' => 'not-a-logical-leg-key']),
    leg(1, 101),
]);
if ($invalidHashA !== $invalidHashB) {
    fail('expected invalid canonical leg-set hash to be stable independent of row order');
}

echo "ok\n";
