<?php
declare(strict_types=1);

$baseDir = __DIR__ . '/..';

spl_autoload_register(static function (string $class) use ($baseDir): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = $baseDir . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

require_once $baseDir . '/src/Bootstrap.php';

use Leikwan\Master\Bootstrap;

$envPath = $baseDir . '/.env';
if (!is_file($envPath)) {
    echo "Error: .env not found at {$envPath}\n";
    exit(1);
}

// Parse arguments
$args = [];
for ($index = 1; $index < $argc; $index += 2) {
    $key = $argv[$index] ?? '';
    $value = $argv[$index + 1] ?? '';
    if (str_starts_with($key, '--')) {
        $args[substr($key, 2)] = $value;
    }
}

$retentionDays = max(1, (int) ($args['retention-days'] ?? 7));
// Ultra grant rows are one-shot nonces that die with the five minute policy
// TTL. The only reasons to keep one are the replay grace window (300 s) and the
// abuse rate windows (60 s), so this is an hours-scale table, not a days-scale
// one. Inheriting --retention-days (7d) let it reach 364k rows / 328 MB, of
// which 198 were live: every lease issue ended up full-scanning the table
// (0.20-1.23 s per query, 1.1M rows/s, 15.9k disk page reads/s) because no
// index could answer client_id + time from the index alone.
$ultraLeaseRetentionHours = max(1, (int) ($args['ultra-lease-retention-hours'] ?? 6));
if (isset($args['ultra-lease-retention-days'])) {
    // Legacy knob, still honoured as days for any operator runbook that passes it.
    $ultraLeaseRetentionHours = max(1, (int) $args['ultra-lease-retention-days'] * 24);
}
// Refusals are an audit trail an operator reads after the fact, so they default
// to 30 days rather than the operational window.
$ultraRefusalRetentionDays = max(1, (int) ($args['ultra-refusal-retention-days'] ?? 30));
$offlineMinutes = max(5, (int) ($args['offline-minutes'] ?? 60));
$leaseTimeoutMinutes = max(1, (int) ($args['lease-timeout'] ?? 10));
$dryRun = isset($args['dry-run']);

$app = new Bootstrap($envPath);
$db = $app->db();

$now = gmdate('Y-m-d H:i:s');
$retentionCutoff = gmdate('Y-m-d H:i:s', time() - $retentionDays * 86400);
$ultraLeaseCutoff = gmdate('Y-m-d H:i:s', time() - $ultraLeaseRetentionHours * 3600);
$ultraRefusalCutoff = gmdate('Y-m-d H:i:s', time() - $ultraRefusalRetentionDays * 86400);
$offlineCutoff = gmdate('Y-m-d H:i:s', time() - $offlineMinutes * 60);
$leaseCutoff = gmdate('Y-m-d H:i:s', time() - $leaseTimeoutMinutes * 60);

echo "[cleanup-tasks] " . ($dryRun ? '(DRY RUN) ' : '') . "retention={$retentionDays}d ultra-lease-retention={$ultraLeaseRetentionHours}h offline={$offlineMinutes}m lease-timeout={$leaseTimeoutMinutes}m\n";

try {
    // 1. Delete completed/failed provision_tasks older than retention period
    $countCompleted = (int) ($db->fetchOne(
        'SELECT COUNT(*) AS cnt FROM provision_tasks WHERE status IN (:s1, :s2) AND updated_at < :cutoff',
        ['s1' => 'completed', 's2' => 'failed', 'cutoff' => $retentionCutoff]
    )['cnt'] ?? 0);

    if (!$dryRun && $countCompleted > 0) {
        $deleted = $db->execute(
            'DELETE FROM provision_tasks WHERE status IN (:s1, :s2) AND updated_at < :cutoff',
            ['s1' => 'completed', 's2' => 'failed', 'cutoff' => $retentionCutoff]
        );
        echo "  purged {$deleted} completed/failed tasks older than {$retentionDays} days\n";
    } else {
        echo "  would purge {$countCompleted} completed/failed tasks older than {$retentionDays} days\n";
    }

    // 2. Cancel pending tasks for offline nodes (no heartbeat within threshold)
    $countOffline = (int) ($db->fetchOne(
        'SELECT COUNT(*) AS cnt FROM provision_tasks pt
         INNER JOIN nodes n ON n.id = pt.node_id
         WHERE pt.status = :status
           AND (n.last_heartbeat_at IS NULL OR n.last_heartbeat_at < :cutoff)',
        ['status' => 'pending', 'cutoff' => $offlineCutoff]
    )['cnt'] ?? 0);

    if (!$dryRun && $countOffline > 0) {
        $cancelled = $db->execute(
            'UPDATE provision_tasks pt
             INNER JOIN nodes n ON n.id = pt.node_id
             SET pt.status = :new_status,
                 pt.error_message = :msg,
                 pt.updated_at = :now
             WHERE pt.status = :old_status
               AND (n.last_heartbeat_at IS NULL OR n.last_heartbeat_at < :cutoff)',
            [
                'new_status' => 'failed',
                'msg' => 'cancelled: node offline for >' . $offlineMinutes . 'm',
                'now' => $now,
                'old_status' => 'pending',
                'cutoff' => $offlineCutoff,
            ]
        );
        echo "  cancelled {$cancelled} pending tasks for offline nodes\n";
    } else {
        echo "  would cancel {$countOffline} pending tasks for offline nodes\n";
    }

    // 2a-2. FIX I: cancel OBSOLETE pending provision_tasks that bury the real publishes of live
    //       forwards. A 'publish' onto a 'deleted'/'deleting' endpoint RESURRECTS it to 'active'
    //       (NodeController::taskResult), which reconcile then re-deletes -> flap; a 'destroy' of an
    //       already-'deleted' endpoint and any orphan-FK pending task are dead weight at the
    //       oldest-first head of the queue. Cancelling these drains the backlog so agents reach the
    //       newest real fwd publishes. Never touches active/pending endpoints or a 'deleting'
    //       endpoint's destroy (the finalizer).
    $obsolete = \Leikwan\Master\Support\TaskCleanup::cancelObsoletePendingTasks($db, $now, $dryRun);
    if ($dryRun) {
        echo "  would cancel {$obsolete['cancelled']} obsolete pending tasks (publish->deleted/deleting, destroy->deleted, orphan-FK)\n";
    } else {
        echo "  cancelled {$obsolete['cancelled']} obsolete pending tasks (resurrection-hazard publishes + dead destroys + orphan-FK)\n";
    }

    // 2b. Finalize endpoints stranded on offline nodes (deleting -> deleted, pending -> error).
    //     A dead node never acks its destroy/publish tasks, so its endpoints otherwise stay
    //     stuck forever. Drive them to a terminal state.
    $endpointFinalize = \Leikwan\Master\Support\TaskCleanup::finalizeOfflineNodeEndpoints(
        $db,
        $offlineCutoff,
        $now,
        $offlineMinutes,
        $dryRun
    );
    if ($dryRun) {
        echo "  would finalize stranded endpoints on offline nodes (deleting->deleted, pending->error)\n";
    } else {
        echo "  finalized {$endpointFinalize['deleted']} endpoints (deleting->deleted) and {$endpointFinalize['errored']} endpoints (pending->error) on offline nodes\n";
    }

    // 2b-2. Finalize flap-artifact endpoints stuck in 'deleting' on LIVE nodes: either a newer
    //       active/pending endpoint exists for the same (forward_id,node_id) (duplicate) or the
    //       endpoint's most recent destroy task already completed. Conservative; never races an
    //       in-flight teardown.
    $deletingFinalize = \Leikwan\Master\Support\TaskCleanup::finalizeStaleDeletingEndpoints(
        $db,
        $now,
        $dryRun
    );
    if ($dryRun) {
        echo "  would finalize flap-artifact 'deleting' endpoints (redundant duplicates or completed-destroy)\n";
    } else {
        echo "  finalized {$deletingFinalize['deleted']} flap-artifact 'deleting' endpoints (deleting->deleted)\n";
    }

    // 2b-3. FIX J: finalize ORPHANED 'deleting' endpoints whose destroy can never run because the
    //       node is provably gone (missing/stale heartbeat) and which carry no live sibling -- e.g.
    //       the aliyun fwd65 endpoints whose destroy tasks are queued to unreachable nodes. Finalize
    //       deleting->deleted and cancel the lingering pending destroy. Conservative: skips fresh
    //       nodes, running/completed destroys, and endpoints with a live sibling.
    $orphanFinalize = \Leikwan\Master\Support\TaskCleanup::finalizeOrphanedDeletingEndpoints(
        $db,
        $offlineCutoff,
        $now,
        $offlineMinutes,
        $dryRun
    );
    if ($dryRun) {
        echo "  would finalize {$orphanFinalize['deleted']} orphaned 'deleting' endpoints + cancel {$orphanFinalize['cancelled']} unrunnable destroys (node gone, no live sibling)\n";
    } else {
        echo "  finalized {$orphanFinalize['deleted']} orphaned 'deleting' endpoints (deleting->deleted) and cancelled {$orphanFinalize['cancelled']} unrunnable destroy tasks\n";
    }

    // 2c. Demote nodes whose last heartbeat is older than the offline threshold so dead
    //     nodes stop showing as "active". Re-promotion happens on the next heartbeat.
    $demoted = \Leikwan\Master\Support\TaskCleanup::demoteStaleNodes($db, $offlineCutoff, $now, $dryRun);
    if ($dryRun) {
        echo "  would demote stale nodes to offline\n";
    } else {
        echo "  demoted {$demoted} stale nodes to offline\n";
    }

    // 3. Reset stale running tasks (lease expired) back to pending for retry
    $countStale = (int) ($db->fetchOne(
        'SELECT COUNT(*) AS cnt FROM provision_tasks
         WHERE status = :status
           AND lease_expires_at IS NOT NULL
           AND lease_expires_at < :cutoff',
        ['status' => 'running', 'cutoff' => $leaseCutoff]
    )['cnt'] ?? 0);

    if (!$dryRun && $countStale > 0) {
        $reset = $db->execute(
            'UPDATE provision_tasks
             SET status = :new_status,
                 lease_expires_at = NULL,
                 updated_at = :now
             WHERE status = :old_status
               AND lease_expires_at IS NOT NULL
               AND lease_expires_at < :cutoff',
            [
                'new_status' => 'pending',
                'now' => $now,
                'old_status' => 'running',
                'cutoff' => $leaseCutoff,
            ]
        );
        echo "  reset {$reset} stale running tasks (lease expired >{$leaseTimeoutMinutes}m)\n";
    } else {
        echo "  would reset {$countStale} stale running tasks\n";
    }

    // 4. Fail tasks with too many attempts (stuck in retry loop)
    $maxAttempts = 10;
    $countRetryExhausted = (int) ($db->fetchOne(
        'SELECT COUNT(*) AS cnt FROM provision_tasks
         WHERE status IN (:s1, :s2) AND attempt_count >= :max',
        ['s1' => 'pending', 's2' => 'running', 'max' => $maxAttempts]
    )['cnt'] ?? 0);

    if (!$dryRun && $countRetryExhausted > 0) {
        $exhausted = $db->execute(
            'UPDATE provision_tasks
             SET status = :new_status,
                 error_message = :msg,
                 lease_expires_at = NULL,
                 updated_at = :now
             WHERE status IN (:s1, :s2) AND attempt_count >= :max',
            [
                'new_status' => 'failed',
                'msg' => 'cancelled: exceeded ' . $maxAttempts . ' attempts',
                'now' => $now,
                's1' => 'pending',
                's2' => 'running',
                'max' => $maxAttempts,
            ]
        );
        echo "  failed {$exhausted} tasks exceeding {$maxAttempts} attempts\n";
    } else {
        echo "  would fail {$countRetryExhausted} tasks exceeding {$maxAttempts} attempts\n";
    }

    // 5. Deduplicate: keep only the latest pending task per (node_id, endpoint_id, action)
    $dupeRows = $db->fetchAll(
        'SELECT node_id, endpoint_id, action, COUNT(*) AS cnt, MAX(id) AS keep_id
         FROM provision_tasks
         WHERE status = :status
         GROUP BY node_id, endpoint_id, action
         HAVING cnt > 1',
        ['status' => 'pending']
    );
    $totalDupes = 0;
    foreach ($dupeRows as $row) {
        $totalDupes += (int) $row['cnt'] - 1;
    }

    if (!$dryRun && $totalDupes > 0) {
        $deduped = 0;
        foreach ($dupeRows as $row) {
            $deduped += $db->execute(
                'DELETE FROM provision_tasks
                 WHERE node_id = :node_id
                   AND endpoint_id = :endpoint_id
                   AND action = :action
                   AND status = :status
                   AND id < :keep_id',
                [
                    'node_id' => $row['node_id'],
                    'endpoint_id' => $row['endpoint_id'],
                    'action' => $row['action'],
                    'status' => 'pending',
                    'keep_id' => $row['keep_id'],
                ]
            );
        }
        echo "  deduped {$deduped} redundant pending tasks\n";
    } else {
        echo "  would dedupe {$totalDupes} redundant pending tasks\n";
    }

    // 6. Clean up old ssl_certificate_tasks
    $countSsl = (int) ($db->fetchOne(
        'SELECT COUNT(*) AS cnt FROM ssl_certificate_tasks WHERE status IN (:s1, :s2) AND updated_at < :cutoff',
        ['s1' => 'completed', 's2' => 'failed', 'cutoff' => $retentionCutoff]
    )['cnt'] ?? 0);

    if (!$dryRun && $countSsl > 0) {
        $deletedSsl = $db->execute(
            'DELETE FROM ssl_certificate_tasks WHERE status IN (:s1, :s2) AND updated_at < :cutoff',
            ['s1' => 'completed', 's2' => 'failed', 'cutoff' => $retentionCutoff]
        );
        echo "  purged {$deletedSsl} old ssl certificate tasks\n";
    } else {
        echo "  would purge {$countSsl} old ssl certificate tasks\n";
    }

    // 7. Clean up expired password reset tokens
    $countTokens = (int) ($db->fetchOne(
        'SELECT COUNT(*) AS cnt FROM password_reset_tokens WHERE expires_at < :now',
        ['now' => $now]
    )['cnt'] ?? 0);

    if (!$dryRun && $countTokens > 0) {
        $deletedTokens = $db->execute(
            'DELETE FROM password_reset_tokens WHERE expires_at < :now',
            ['now' => $now]
        );
        echo "  purged {$deletedTokens} expired password reset tokens\n";
    } else {
        echo "  would purge {$countTokens} expired password reset tokens\n";
    }

    // 8. Clean up old node heartbeat rows (keep only last 24h)
    $heartbeatCutoff = gmdate('Y-m-d H:i:s', time() - 86400);
    $countHeartbeats = (int) ($db->fetchOne(
        'SELECT COUNT(*) AS cnt FROM node_heartbeats WHERE created_at < :cutoff',
        ['cutoff' => $heartbeatCutoff]
    )['cnt'] ?? 0);

    if (!$dryRun && $countHeartbeats > 0) {
        $deletedHb = $db->execute(
            'DELETE FROM node_heartbeats WHERE created_at < :cutoff',
            ['cutoff' => $heartbeatCutoff]
        );
        echo "  purged {$deletedHb} old heartbeat rows (>24h)\n";
    } else {
        echo "  would purge {$countHeartbeats} old heartbeat rows (>24h)\n";
    }

    // 9. Purge old bond leg reputation events (batched to avoid long locks)
    $countRepEvents = (int) ($db->fetchOne(
        'SELECT COUNT(*) AS cnt FROM bond_leg_reputation_events WHERE observed_at < :cutoff',
        ['cutoff' => $retentionCutoff]
    )['cnt'] ?? 0);

    if (!$dryRun && $countRepEvents > 0) {
        $deletedRepEvents = $db->execute(
            'DELETE FROM bond_leg_reputation_events WHERE observed_at < :cutoff LIMIT 10000',
            ['cutoff' => $retentionCutoff]
        );
        echo "  purged {$deletedRepEvents} old bond leg reputation events (>{$retentionDays}d)\n";
    } else {
        echo "  would purge {$countRepEvents} old bond leg reputation events (>{$retentionDays}d)\n";
    }

    // 10. Reclaim expired, unrevoked Ultra nonce rows after a bounded
    //     retention window. Expired rows cannot authorize a lease after the
    //     five-minute policy TTL, and deleting them avoids marking a policy
    //     generation revoked (which would reject future leases for that
    //     generation). Revoked rows are retained as generation tombstones.
    $countUltraLeases = (int) ($db->fetchOne(
        'SELECT COUNT(*) AS cnt FROM ultra_client_leases
         WHERE revoked_at IS NULL
           AND expires_at < :now
           AND created_at < :cutoff',
        ['now' => $now, 'cutoff' => $ultraLeaseCutoff]
    )['cnt'] ?? 0);

    if (!$dryRun && $countUltraLeases > 0) {
        $reclaimedUltraLeases = 0;
        do {
            $deletedUltraLeases = $db->execute(
                'DELETE FROM ultra_client_leases
                 WHERE revoked_at IS NULL
                   AND expires_at < :now
                   AND created_at < :cutoff
                 ORDER BY id
                 LIMIT 10000',
                ['now' => $now, 'cutoff' => $ultraLeaseCutoff]
            );
            $reclaimedUltraLeases += $deletedUltraLeases;
        } while ($deletedUltraLeases === 10000);
        echo "  reclaimed {$reclaimedUltraLeases} expired unrevoked Ultra lease rows (>{$ultraLeaseRetentionHours}h)\n";
    } else {
        echo "  would reclaim {$countUltraLeases} expired unrevoked Ultra lease rows (>{$ultraLeaseRetentionHours}h)\n";
    }

    // 11. Trim the Ultra lease refusal audit. The table only exists to explain a
    //     refusal to an operator (admin -> Ultra -> Refusals), so it keeps a
    //     longer window than the operational tables and is trimmed in bounded
    //     batches to stay safe on a busy panel.
    $countUltraRefusals = (int) ($db->fetchOne(
        'SELECT COUNT(*) AS cnt FROM ultra_lease_refusals WHERE created_at < :cutoff',
        ['cutoff' => $ultraRefusalCutoff]
    )['cnt'] ?? 0);

    if (!$dryRun && $countUltraRefusals > 0) {
        $trimmedUltraRefusals = 0;
        do {
            $deletedUltraRefusals = $db->execute(
                'DELETE FROM ultra_lease_refusals WHERE created_at < :cutoff ORDER BY id LIMIT 10000',
                ['cutoff' => $ultraRefusalCutoff]
            );
            $trimmedUltraRefusals += $deletedUltraRefusals;
        } while ($deletedUltraRefusals === 10000);
        echo "  trimmed {$trimmedUltraRefusals} Ultra lease refusal rows (>{$ultraRefusalRetentionDays}d)\n";
    } else {
        echo "  would trim {$countUltraRefusals} Ultra lease refusal rows (>{$ultraRefusalRetentionDays}d)\n";
    }

    // Summary: show remaining queue depth
    $remaining = $db->fetchOne(
        'SELECT COUNT(*) AS cnt FROM provision_tasks WHERE status IN (:s1, :s2)',
        ['s1' => 'pending', 's2' => 'running']
    );
    echo "[cleanup-tasks] done. remaining queued tasks: " . (int) ($remaining['cnt'] ?? 0) . "\n";

} catch (Throwable $e) {
    fwrite(STDERR, "cleanup-tasks failed: {$e->getMessage()}\n");
    exit(1);
}
