<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Database\Database;

/**
 * Maintenance helpers for the cleanup-tasks cron. Extracted from the flat
 * cli/cleanup-tasks.php script so the dead-node finalization and stale-node
 * demotion logic can be exercised by a behavioral test against a real Database.
 */
final class TaskCleanup
{
    /**
     * Finalize endpoints stranded on a node that has been offline past the
     * threshold. A dead node never acks its destroy/publish tasks, so its
     * service_endpoints rows otherwise stay in "deleting"/"pending" forever
     * (e.g. node3 offline 4 days). This drives them to a terminal state:
     *   - deleting -> deleted
     *   - pending  -> error (with a last_error note)
     *
     * @return array{deleted:int,errored:int} rows transitioned (0/0 on dry run)
     */
    public static function finalizeOfflineNodeEndpoints(
        Database $db,
        string $offlineCutoff,
        string $now,
        int $offlineMinutes,
        bool $dryRun
    ): array {
        $offlineNodeFilter =
            ' node_id IN (SELECT id FROM nodes WHERE last_heartbeat_at IS NULL OR last_heartbeat_at < :cutoff)';

        $deletedCount = (int) ($db->fetchOne(
            'SELECT COUNT(*) AS cnt FROM service_endpoints WHERE health_status = :status AND' . $offlineNodeFilter,
            ['status' => 'deleting', 'cutoff' => $offlineCutoff]
        )['cnt'] ?? 0);

        $erroredCount = (int) ($db->fetchOne(
            'SELECT COUNT(*) AS cnt FROM service_endpoints WHERE health_status = :status AND' . $offlineNodeFilter,
            ['status' => 'pending', 'cutoff' => $offlineCutoff]
        )['cnt'] ?? 0);

        if ($dryRun) {
            return ['deleted' => 0, 'errored' => 0];
        }

        $deleted = 0;
        if ($deletedCount > 0) {
            $deleted = $db->execute(
                'UPDATE service_endpoints
                 SET health_status = :new_status,
                     updated_at = :now
                 WHERE health_status = :old_status AND' . $offlineNodeFilter,
                [
                    'new_status' => 'deleted',
                    'now' => $now,
                    'old_status' => 'deleting',
                    'cutoff' => $offlineCutoff,
                ]
            );
        }

        $errored = 0;
        if ($erroredCount > 0) {
            $errored = $db->execute(
                'UPDATE service_endpoints
                 SET health_status = :new_status,
                     last_error = :msg,
                     updated_at = :now
                 WHERE health_status = :old_status AND' . $offlineNodeFilter,
                [
                    'new_status' => 'error',
                    'msg' => 'finalized: node offline for >' . $offlineMinutes . 'm',
                    'now' => $now,
                    'old_status' => 'pending',
                    'cutoff' => $offlineCutoff,
                ]
            );
        }

        return ['deleted' => $deleted, 'errored' => $errored];
    }

    /**
     * Demote nodes whose last heartbeat is older than the offline threshold.
     * The heartbeat path keeps last_heartbeat_at fresh but never flips a node
     * back to "offline" when it stops reporting, so dead nodes used to linger
     * as "active" indefinitely. Re-promotion on the next heartbeat is out of
     * scope here (handled separately by the heartbeat update path).
     *
     * @return int number of nodes demoted (0 on dry run)
     */
    public static function demoteStaleNodes(
        Database $db,
        string $offlineCutoff,
        string $now,
        bool $dryRun
    ): int {
        $count = (int) ($db->fetchOne(
            'SELECT COUNT(*) AS cnt FROM nodes
             WHERE status != :offline
               AND (last_heartbeat_at IS NULL OR last_heartbeat_at < :cutoff)',
            ['offline' => 'offline', 'cutoff' => $offlineCutoff]
        )['cnt'] ?? 0);

        if ($dryRun || $count === 0) {
            return 0;
        }

        return $db->execute(
            'UPDATE nodes
             SET status = :offline,
                 updated_at = :now
             WHERE status != :offline
               AND (last_heartbeat_at IS NULL OR last_heartbeat_at < :cutoff)',
            [
                'offline' => 'offline',
                'now' => $now,
                'cutoff' => $offlineCutoff,
            ]
        );
    }
}
