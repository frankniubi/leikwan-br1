<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$repoRoot = dirname(__DIR__, 2);
$contractPath = $repoRoot . '/master/src/Support/LeigodOnlyRuntimeContract.php';
if (!is_file($contractPath)) {
    fail('expected LeigodOnlyRuntimeContract.php to exist');
}
require_once $contractPath;

$contract = \Leikwan\Master\Support\LeigodOnlyRuntimeContract::class;

$valid = [
    'backend_mode' => 'leigod_mode',
    'client_mode' => 'bond',
    'runtime_preference' => 'single',
    'bond_ingress_mode' => 'plain_tcp_mux',
    'active_family' => 'standalone_bond',
    'metadata' => ['rollout' => 'task40_activation'],
];

$normalized = $contract::validate($valid, 'valid_contract');
$expected = [
    'backend_mode' => 'leigod_mode',
    'client_mode' => 'bond',
    'runtime_preference' => 'single',
    'bond_ingress_mode' => 'plain_tcp_mux',
    'active_family' => 'standalone_bond',
];
if ($normalized !== $expected) {
    fail('expected normalized locked runtime contract');
}
$contract::assertActiveContract($valid, 'valid_assertion');

function assertRuntimeContractThrows(string $label, array $row, string $context, string $needle): void
{
    $contract = \Leikwan\Master\Support\LeigodOnlyRuntimeContract::class;
    try {
        $contract::validate($row, $context);
    } catch (\InvalidArgumentException $exception) {
        $message = $exception->getMessage();
        foreach (['leigod_only_runtime_contract_violation', $context, $needle] as $required) {
            if (!str_contains($message, $required)) {
                fail($label . ' message missing ' . $required . ': ' . $message);
            }
        }
        return;
    }
    fail($label . ' should have thrown');
}

$missingActiveFamily = $valid;
unset($missingActiveFamily['active_family']);
assertRuntimeContractThrows('missing active_family', $missingActiveFamily, 'missing_field_case', 'active_family');

$mismatchedBackend = $valid;
$mismatchedBackend['backend_mode'] = 'leigod_plus';
assertRuntimeContractThrows('mismatched backend_mode', $mismatchedBackend, 'mismatch_case', 'backend_mode');

foreach ([
    'current_mode backend marker' => ['patch' => ['backend_mode' => 'current_mode'], 'marker' => 'current_mode'],
    'cf_mode field marker' => ['patch' => ['cf_mode' => 'cf_mode'], 'marker' => 'cf_mode'],
    'native_mptcp runtime marker' => ['patch' => ['runtime_preference' => 'native_mptcp'], 'marker' => 'native_mptcp'],
    'legacy mptcp relay marker' => ['patch' => ['transport_family' => 'leikwan-mptcp-relay'], 'marker' => 'leikwan-mptcp-relay'],
    'platform default fallback marker' => ['patch' => ['fallback_mode' => 'platform_default'], 'marker' => 'platform_default'],
    'mptcp fallback marker' => ['patch' => ['runtime_family' => 'mptcp_preferred_bond_fallback'], 'marker' => 'mptcp_preferred_bond_fallback'],
    'bond fallback marker' => ['patch' => ['mode' => 'bond_fallback'], 'marker' => 'bond_fallback'],
    'cloudflare metadata marker' => ['patch' => ['metadata' => ['transport' => 'cloudflare']], 'marker' => 'cloudflare'],
    'wss notes marker' => ['patch' => ['notes' => 'do not revive wss transport'], 'marker' => 'wss'],
    'classic sidecar string marker' => ['patch' => ['metadata' => ['legacy' => 'classic sidecar']], 'marker' => 'classic sidecar'],
    'classic_sidecar string marker' => ['patch' => ['metadata' => ['legacy' => 'classic_sidecar']], 'marker' => 'classic_sidecar'],
    'forbidden metadata array key' => ['patch' => ['metadata' => ['cloudflare' => false]], 'marker' => 'cloudflare'],
    'forbidden nested array key' => ['patch' => ['metadata' => ['runtime' => ['wss' => false]]], 'marker' => 'wss'],
    'forbidden runtime metadata nested value outside scanned fields' => ['patch' => ['runtime_metadata' => ['legacy' => 'cloudflare']], 'marker' => 'cloudflare'],
    'forbidden top-level key containing current_mode' => ['patch' => ['shadow_current_mode_marker' => false], 'marker' => 'current_mode'],
] as $label => $case) {
    assertRuntimeContractThrows(
        $label,
        array_replace_recursive($valid, $case['patch']),
        'forbidden_' . str_replace([' ', '-'], '_', $label),
        $case['marker']
    );
}

echo "ok\n";
