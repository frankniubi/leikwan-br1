<?php
declare(strict_types=1);
require_once __DIR__ . '/leigod_only_rollback_test_helper.php';

$pdo = rollback_db();
$seed = seed_rollback($pdo);
$svc = new LeigodOnlyRollbackService($pdo);
$result = $svc->apply(7, $seed['batch_hash'], $seed['owner'], $seed['fence']);

if (($result['status'] ?? '') !== 'rolled_back') fail('apply did not report rolled_back');
if (($result['restored_forward_ids'] ?? []) !== [47, 48]) fail('restored forwards mismatch');
foreach ([47, 48] as $fid) {
    $slotKey = $seed['slot_keys'][$fid];
    $desiredSlotKey = $seed['desired_slot_keys'][$fid];
    $pointerSlotKey = $seed['pointer_slot_keys'][$fid];
    $identity = $seed['identities'][$fid];

    if (str_contains($slotKey, 'rollback:') || str_starts_with($slotKey, 'post:')) fail('fixture used synthetic current slot ' . $slotKey);
    $superseded = $pdo->countRows('leigod_only_activation_assignments', fn(array $r): bool => (int)$r['forward_id'] === $fid && ($r['current_slot_key'] ?? null) === null && ($r['superseded_at'] ?? null) !== null && (string)$r['artifact_snapshot_hash'] === $seed['post_artifact']);
    if ((int)$superseded !== 1) fail('post current assignment not superseded ' . $fid);
    $new = $pdo->countRows('leigod_only_activation_assignment_current_slots', fn(array $r): bool => ($r['slot_key'] ?? '') === $slotKey && (int)$r['desired_generation'] === 8);
    if ((int)$new !== 1) fail('restored current slot missing ' . $fid);
    $slots = $pdo->rows('leigod_only_activation_assignment_current_slots', fn(array $r): bool => ($r['slot_key'] ?? '') === $slotKey);
    $slot = $slots[0] ?? [];
    foreach (['assignment_hash', 'desired_leg_set_hash'] as $hashField) { if (!preg_match('/^[a-f0-9]{64}$/', (string)($slot[$hashField] ?? ''))) fail('restored current slot missing ' . $hashField . ' ' . $fid); }
    if (($slot['protocol'] ?? '') !== 'tcp') fail('restored current slot protocol mismatch ' . $fid);
    if (($slot['active_family'] ?? '') !== 'standalone_bond') fail('restored current slot active family mismatch ' . $fid);
    if (($slot['client_id'] ?? null) !== $identity['client_id'] || ($slot['client_device_session_id'] ?? null) !== $identity['client_device_session_id']) fail('restored current slot identity mismatch ' . $fid);
    if (array_key_exists('desktop_client_session_id', $slot)) fail('restored current slot wrote desktop identity outside schema boundary ' . $fid);
    $assignments = $pdo->rows('leigod_only_activation_assignments', fn(array $r): bool => ($r['current_slot_key'] ?? '') === $slotKey);
    if (count($assignments) !== 1) fail('restored assignment row missing ' . $fid);
    $assignment = $assignments[0];
    foreach (['assignment_hash', 'desired_leg_set_hash'] as $hashField) { if (!preg_match('/^[a-f0-9]{64}$/', (string)($assignment[$hashField] ?? ''))) fail('restored assignment missing ' . $hashField . ' ' . $fid); }
    if (($assignment['protocol'] ?? '') !== 'tcp') fail('restored assignment protocol mismatch ' . $fid);
    if (($assignment['active_family'] ?? '') !== 'standalone_bond') fail('restored assignment active family mismatch ' . $fid);
    if (($assignment['row_expires_at'] ?? '') === '') fail('restored assignment row_expires_at missing ' . $fid);
    if (($assignment['client_id'] ?? null) !== $identity['client_id'] || ($assignment['client_device_session_id'] ?? null) !== $identity['client_device_session_id']) fail('restored assignment identity mismatch ' . $fid);
    if (array_key_exists('desktop_client_session_id', $assignment)) fail('restored assignment wrote desktop identity outside schema boundary ' . $fid);
    $runtime = json_decode((string)($assignment['runtime_contract_json'] ?? ''), true);
    if (!is_array($runtime) || ($runtime['backend_mode'] ?? '') !== 'leigod_mode' || ($runtime['active_family'] ?? '') !== 'standalone_bond') fail('restored assignment runtime contract mismatch ' . $fid);
    $desired = $pdo->rows('leigod_only_forward_desired_state_fences', fn(array $r): bool => (int)$r['forward_id'] === $fid && (string)($r['current_slot_key'] ?? '') === $desiredSlotKey);
    if (count($desired) !== 1) fail('canonical desired current slot missing ' . $fid);
    if (($desired[0]['client_id'] ?? null) !== $identity['client_id'] || ($desired[0]['client_device_session_id'] ?? null) !== $identity['client_device_session_id'] || ($desired[0]['desktop_client_session_id'] ?? null) !== $identity['desktop_client_session_id']) fail('desired identity mismatch ' . $fid);
    $pointer = $pdo->rows('leigod_only_artifact_pointer_states', fn(array $r): bool => (int)$r['forward_id'] === $fid && (string)($r['current_slot_key'] ?? '') === $pointerSlotKey);
    if (count($pointer) !== 1) fail('canonical artifact pointer current slot missing ' . $fid);
    if (($pointer[0]['client_id'] ?? null) !== $identity['client_id'] || ($pointer[0]['client_device_session_id'] ?? null) !== $identity['client_device_session_id'] || ($pointer[0]['desktop_client_session_id'] ?? null) !== $identity['desktop_client_session_id']) fail('artifact pointer identity mismatch ' . $fid);
}
if ($pdo->countRows('leigod_only_forward_desired_state_fences', fn(array $r): bool => (int)$r['desired_generation'] === 8 && (string)$r['desired_state_hash'] === $seed['prev_desired']) !== 2) fail('previous desired fences not restored');
if ($pdo->countRows('leigod_only_artifact_pointer_states', fn(array $r): bool => (string)$r['artifact_snapshot_hash'] === $seed['prev_artifact']) !== 2) fail('previous artifact pointers not restored');
if (rollback_count_rows($pdo, 'leigod_only_fenced_publish_jobs') !== 2) fail('publish jobs not enqueued per forward');
if (rollback_count_rows($pdo, 'leigod_only_activation_audit_events') !== 1) fail('audit event missing');

echo "ok\n";
