<?php
declare(strict_types=1);

function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }
function assert_true(bool $condition, string $message): void { if (!$condition) fail($message); }
function run_cli(array $args): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../cli/leigod-only-activation.php');
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg($arg);
    }
    $output = [];
    $code = 0;
    exec($cmd, $output, $code);
    $text = implode("\n", $output);
    $json = json_decode($text, true);
    if (!is_array($json)) {
        fail('CLI did not return JSON. code=' . $code . ' output=' . $text);
    }
    return ['code' => $code, 'json' => $json, 'text' => $text];
}
function assert_no_side_effects(array $json, string $label): void
{
    $calls = $json['calls'] ?? [];
    foreach (['artifact_probe', 'metadata_probe', 'lock', 'mutator', 'publish_queue'] as $key) {
        $value = (int) ($calls[$key] ?? -1);
        if ($value !== 0) {
            fail($label . ' expected zero ' . $key . ' calls, got ' . $value . ' payload=' . json_encode($json));
        }
    }
}

$plan = run_cli(['plan', '--dry-run', '--scope=global', '--output=json']);
assert_true($plan['code'] === 0, 'plan dry-run should exit 0: ' . $plan['text']);
assert_true(($plan['json']['ok'] ?? null) === true && ($plan['json']['dry_run_only'] ?? null) === true, 'plan dry-run should be read-only ok: ' . $plan['text']);
assert_no_side_effects($plan['json'], 'plan dry-run');

$record = run_cli(['plan', '--dry-run', '--record', '--scope=global', '--output=json']);
assert_true($record['code'] === 0, 'plan dry-run record should exit 0: ' . $record['text']);
assert_true(isset($record['json']['plan_id']) && isset($record['json']['plan_hash']), 'recorded dry-run should include plan id/hash: ' . $record['text']);
assert_no_side_effects($record['json'], 'plan dry-run record');

$canary = run_cli(['canary-status', '--platform-family=linux-bond', '--output=json']);
assert_true($canary['code'] === 0, 'canary status should exit 0: ' . $canary['text']);
assert_true(($canary['json']['write_mode'] ?? null) === 'read_only', 'canary status must be read-only: ' . $canary['text']);
assert_no_side_effects($canary['json'], 'canary status');

foreach ([
    ['plan', '--dry-run', '--scope=global', '--platform-family=android-bond', '--output=json'],
    ['canary-status', '--platform-family=android-bond', '--output=json'],
    ['activate', '--plan-id=plan-1', '--confirm-plan-hash=' . str_repeat('a', 64), '--scope=global', '--owner-token=owner', '--fencing-token=fence', '--platform-family=android-bond'],
] as $args) {
    $result = run_cli($args);
    assert_true($result['code'] !== 0, 'android command should fail: ' . implode(' ', $args) . ' output=' . $result['text']);
    assert_true(($result['json']['error'] ?? '') === 'unsupported_platform_family:android-bond', 'android command should return unsupported platform error: ' . $result['text']);
    assert_no_side_effects($result['json'], 'android ' . $args[0]);
}

foreach ([
    ['args' => ['activate', '--plan-id=plan-1', '--scope=global', '--owner-token=owner', '--fencing-token=fence'], 'error' => 'confirm_plan_hash_required'],
    ['args' => ['activate', '--plan-id=plan-1', '--confirm-plan-hash=' . str_repeat('a', 64), '--scope=global', '--fencing-token=fence'], 'error' => 'owner_token_required'],
    ['args' => ['activate', '--plan-id=plan-1', '--confirm-plan-hash=' . str_repeat('a', 64), '--scope=global', '--owner-token=owner'], 'error' => 'fencing_token_required'],
    ['args' => ['activate', '--plan-id=plan-1', '--confirm-plan-hash=' . str_repeat('a', 64), '--scope=global', '--owner-token=owner', '--fencing-token=fence'], 'error' => 'plan_hash_unchanged_required'],
    ['args' => ['rollback', '--batch-id=batch-1', '--confirm-batch-id=batch-1', '--owner-token=owner', '--fencing-token=fence', '--dry-run'], 'error' => 'confirm_batch_hash_required'],
    ['args' => ['rollback', '--batch-id=batch-1', '--confirm-batch-hash=' . str_repeat('b', 64), '--owner-token=owner', '--fencing-token=fence', '--dry-run'], 'error' => 'confirm_batch_id_required'],
    ['args' => ['rollback', '--batch-id=batch-1', '--confirm-batch-hash=' . str_repeat('b', 64), '--confirm-batch-id=batch-1', '--fencing-token=fence', '--dry-run'], 'error' => 'owner_token_required'],
    ['args' => ['rollback', '--batch-id=batch-1', '--confirm-batch-hash=' . str_repeat('b', 64), '--confirm-batch-id=batch-1', '--owner-token=owner', '--dry-run'], 'error' => 'fencing_token_required'],
    ['args' => ['rollback', '--batch-id=batch-1', '--confirm-batch-hash=' . str_repeat('b', 64), '--confirm-batch-id=batch-1', '--owner-token=owner', '--fencing-token=fence', '--dry-run'], 'error' => 'batch_hash_unchanged_required'],
] as $case) {
    $result = run_cli($case['args']);
    assert_true($result['code'] !== 0, 'guard command should fail: ' . implode(' ', $case['args']) . ' output=' . $result['text']);
    assert_true(($result['json']['error'] ?? '') === $case['error'], 'expected error ' . $case['error'] . ' got ' . ($result['json']['error'] ?? '<missing>') . ' output=' . $result['text']);
    assert_no_side_effects($result['json'], $case['error']);
}

$lockInspect = run_cli(['lock-inspect', '--scope=global', '--output=json']);
assert_true($lockInspect['code'] === 0 && ($lockInspect['json']['ok'] ?? null) === true, 'lock inspect should be read-only ok: ' . $lockInspect['text']);
assert_no_side_effects($lockInspect['json'], 'lock inspect');

$releaseExpired = run_cli(['release-expired', '--scope=global', '--output=json']);
assert_true($releaseExpired['code'] === 0 && ($releaseExpired['json']['ok'] ?? null) === true, 'release expired dry surface should be ok: ' . $releaseExpired['text']);
assert_no_side_effects($releaseExpired['json'], 'release expired');

echo "ok\n";
