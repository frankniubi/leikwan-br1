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
     * Finalize service_endpoints stuck in 'deleting' that are FLAP ARTIFACTS, draining the
     * accumulated backlog (e.g. ~1733 endpoints left in 'deleting'). This is independent of the
     * offline-node path: these rows are on live nodes whose destroy already completed, or are
     * duplicate rows superseded by a newer endpoint.
     *
     * Conservative finalization (deleting -> deleted) ONLY when:
     *   (a) there is another service_endpoints row for the same (forward_id, node_id) that is
     *       active or pending -- so this 'deleting' row is provably redundant; OR
     *   (b) the endpoint's most recent destroy provision_task is 'completed'.
     *
     * A lone 'deleting' endpoint whose destroy task is still pending/running (or which has no
     * destroy task and no active/pending sibling) is LEFT untouched so we never race an in-flight
     * teardown. Dry-run aware like the sibling finalize methods.
     *
     * @return array{deleted:int} rows transitioned (0 on dry run)
     */
    public static function finalizeStaleDeletingEndpoints(
        Database $db,
        string $now,
        bool $dryRun
    ): array {
        $candidates = $db->fetchAll(
            'SELECT id, forward_id, node_id
             FROM service_endpoints
             WHERE health_status = :status
             ORDER BY id ASC',
            ['status' => 'deleting']
        );

        $finalized = 0;
        foreach ($candidates as $candidate) {
            $endpointId = (int) ($candidate['id'] ?? 0);
            $forwardId = (int) ($candidate['forward_id'] ?? 0);
            $nodeId = (int) ($candidate['node_id'] ?? 0);
            if ($endpointId <= 0) {
                continue;
            }

            $shouldFinalize = false;

            // (a) Provably redundant: a sibling active/pending endpoint exists for this
            //     (forward_id, node_id), so the 'deleting' row is a leftover flap artifact.
            $siblingCount = (int) ($db->fetchOne(
                'SELECT COUNT(*) AS cnt FROM service_endpoints
                 WHERE forward_id = :forward_id
                   AND node_id = :node_id
                   AND id <> :id
                   AND health_status IN (:active, :pending)',
                [
                    'forward_id' => $forwardId,
                    'node_id' => $nodeId,
                    'id' => $endpointId,
                    'active' => 'active',
                    'pending' => 'pending',
                ]
            )['cnt'] ?? 0);
            if ($siblingCount > 0) {
                $shouldFinalize = true;
            }

            // (b) Most recent destroy task GENUINELY executed by the agent and completed:
            //     teardown is done, the row is safe to terminalize. We require attempt_count > 0
            //     AND a result that is not a collapse-supersession, because
            //     collapsePendingProvisionTasksForNode marks a *pending* destroy 'completed' with a
            //     'superseded_by_new_publish_task' note WITHOUT the slave ever running the teardown.
            //     Trusting a bare 'completed' would let such a tombstone finalize an endpoint whose
            //     slave-side listener/selector was never torn down -> orphaned config. Requiring a
            //     real agent ack (attempt_count > 0) + non-superseded result makes guard (b) safe by
            //     design, not by accident.
            if (!$shouldFinalize) {
                $latestDestroy = $db->fetchOne(
                    'SELECT status, attempt_count, result_json FROM provision_tasks
                     WHERE endpoint_id = :endpoint_id
                       AND action = :action
                     ORDER BY id DESC
                     LIMIT 1',
                    ['endpoint_id' => $endpointId, 'action' => 'destroy']
                );
                if (is_array($latestDestroy)
                    && (string) ($latestDestroy['status'] ?? '') === 'completed'
                    && (int) ($latestDestroy['attempt_count'] ?? 0) > 0
                    && !str_contains((string) ($latestDestroy['result_json'] ?? ''), 'superseded')
                ) {
                    $shouldFinalize = true;
                }
            }

            if (!$shouldFinalize) {
                continue;
            }

            if ($dryRun) {
                $finalized++;
                continue;
            }

            $finalized += $db->execute(
                'UPDATE service_endpoints
                 SET health_status = :new_status,
                     updated_at = :now
                 WHERE id = :id AND health_status = :old_status',
                [
                    'new_status' => 'deleted',
                    'now' => $now,
                    'id' => $endpointId,
                    'old_status' => 'deleting',
                ]
            );
        }

        if ($dryRun) {
            return ['deleted' => 0];
        }

        return ['deleted' => $finalized];
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
