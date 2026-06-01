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
     * FIX I: drain the OBSOLETE pending-task backlog that buries the real publishes of live
     * forwards. Agents pull oldest-first (ORDER BY id ASC LIMIT 20), so a 30h-old head-of-queue of
     * tasks that reference 'deleted'/'deleting'/missing endpoints is ground forever and the newest
     * real publishes (e.g. fwd65/66 on node2/node19) never get claimed. Worse, an agent executing a
     * 'publish' whose endpoint is 'deleted'/'deleting' RESURRECTS that endpoint to 'active'
     * (NodeController::taskResult sets health_status='active' on publish success keyed by
     * task.endpoint_id), which reconcileForwardEndpoints then re-deletes -> flap/churn. So obsolete
     * publishes are actively HARMFUL, not merely wasteful.
     *
     * Cancels (status='completed', result_json note 'obsolete_endpoint_terminal',
     * error_message=NULL, lease_expires_at=NULL) PENDING provision_tasks that:
     *   (1) action='publish' whose endpoint is 'deleted' OR 'deleting'  (resurrection hazard)
     *   (2) action='destroy' whose endpoint is 'deleted'                (already terminal)
     *   (3) any pending task whose endpoint_id has NO service_endpoints row (orphan FK)
     *
     * NEVER cancels:
     *   - action='destroy' for a 'deleting' endpoint (that destroy is the finalizer that completes
     *     teardown -- handled by finalizeOrphanedDeletingEndpoints only when its node is gone);
     *   - ANY task whose endpoint is 'active' or 'pending' (live work, incl. the real fwd65/66
     *     publishes onto their pending endpoints).
     *
     * SAFETY (verified against reconcileForwardEndpoints, ProvisioningService.php ~L5570): that
     * method builds $existingByNode from a query that EXCLUDES health_status IN ('deleted',
     * 'deleting'), so a desired node whose only endpoint is deleted/deleting is treated as missing
     * and gets a BRAND-NEW endpoint row + fresh publish task (L5590-5608) -- cancelling the obsolete
     * task for the OLD deleted/deleting endpoint cannot starve a live forward. The re-publish/drift
     * path (L5616-5636) only touches endpoints IN $existingByNode (active/pending/error/degraded),
     * which is exactly the set this method leaves alone. No live forward loses a needed task.
     *
     * Idempotent (a cancelled task is no longer 'pending'). Dry-run returns the count it WOULD
     * cancel without mutating. Uses a single set-based UPDATE keyed on the same predicate as the
     * count, joining service_endpoints via LEFT JOIN so orphan-FK rows are captured.
     *
     * @return array{cancelled:int} tasks cancelled (0 on dry run only for the side effect; the
     *         returned count always reflects what WOULD/DID happen)
     */
    public static function cancelObsoletePendingTasks(
        Database $db,
        string $now,
        bool $dryRun
    ): array {
        // Predicate shared by the count and the UPDATE. A LEFT JOIN lets us reach orphan-FK rows
        // (no matching service_endpoints) via se.id IS NULL.
        // NOTE: distinct named placeholders per occurrence (:health_deleted vs :health_deleted_destroy
        // both bind 'deleted') so the statement does not rely on PDO::ATTR_EMULATE_PREPARES=true; with
        // native prepares a repeated named placeholder raises HY093.
        $predicate =
            " pt.status = :pending
              AND (
                    se.id IS NULL
                 OR (pt.action = :publish AND se.health_status IN (:health_deleted, :health_deleting))
                 OR (pt.action = :destroy AND se.health_status = :health_deleted_destroy)
              )";

        $count = (int) ($db->fetchOne(
            'SELECT COUNT(*) AS cnt
             FROM provision_tasks pt
             LEFT JOIN service_endpoints se ON se.id = pt.endpoint_id
             WHERE' . $predicate,
            [
                'pending' => 'pending',
                'publish' => 'publish',
                'destroy' => 'destroy',
                'health_deleted' => 'deleted',
                'health_deleting' => 'deleting',
                'health_deleted_destroy' => 'deleted',
            ]
        )['cnt'] ?? 0);

        if ($dryRun || $count === 0) {
            return ['cancelled' => $dryRun ? $count : 0];
        }

        $cancelled = $db->execute(
            'UPDATE provision_tasks pt
             LEFT JOIN service_endpoints se ON se.id = pt.endpoint_id
             SET pt.status = :new_status,
                 pt.result_json = :result_json,
                 pt.error_message = NULL,
                 pt.lease_expires_at = NULL,
                 pt.updated_at = :now
             WHERE' . $predicate,
            [
                'new_status' => 'completed',
                'result_json' => '{"note":"obsolete_endpoint_terminal"}',
                'now' => $now,
                'pending' => 'pending',
                'publish' => 'publish',
                'destroy' => 'destroy',
                'health_deleted' => 'deleted',
                'health_deleting' => 'deleting',
                'health_deleted_destroy' => 'deleted',
            ]
        );

        return ['cancelled' => $cancelled];
    }

    /**
     * FIX J (narrow, safe variant): finalize a 'deleting' endpoint to 'deleted' AND cancel its
     * lingering 'pending' destroy task when that destroy can NEVER complete because the node is
     * effectively gone -- the destroy is ORPHANED. This covers e.g. the aliyun fwd65 endpoints
     * 3254(node12)/3255(node13)/3256(node14): they sit 'deleting' with a 'destroy' queued to nodes
     * that are UNREACHABLE (load 24-30, cannot pull), so the destroy is unrunnable forever and
     * finalizeStaleDeletingEndpoints leaves them (its guard requires a *completed* destroy or a
     * live sibling).
     *
     * Finalize ONLY when ALL hold (mirrors the conservatism of the sibling finalize methods):
     *   - the endpoint's latest 'destroy' provision_task is still 'pending' (queued but unrun); AND
     *   - the endpoint's node is provably gone: its nodes row is MISSING, or last_heartbeat_at IS
     *     NULL / older than the offline cutoff (> offlineMinutes); AND
     *   - NO sibling endpoint for the same (forward_id, node_id) is 'active' or 'pending'.
     *
     * On finalize: endpoint deleting->deleted, and the pending destroy task ->'completed' (note
     * 'orphaned_destroy_unrunnable', error_message=NULL, lease_expires_at=NULL).
     *
     * WHY THIS VARIANT IS SAFE: the chosen membership-agnostic variant keys purely on node
     * staleness + absence of a live sibling, which strictly implies the broad "node is no longer a
     * desired member" case for the aliyun nodes (their forward_combination_slaves.is_active=0 AND
     * they are unreachable) without the cost/risk of the membership join. A stale node has no agent
     * to run the teardown, so whatever slave-side selector/listener it still holds is moot and
     * cannot affect live traffic; and because no live sibling endpoint exists on that node, we are
     * not stripping config out from under an active endpoint. Endpoints on FRESH nodes, with a
     * running/completed destroy, or with a live sibling are LEFT untouched so we never race an
     * in-flight or still-runnable teardown.
     *
     * Idempotent + dry-run aware (dry run reports the counts it WOULD apply without mutating).
     *
     * @return array{deleted:int,cancelled:int}
     */
    public static function finalizeOrphanedDeletingEndpoints(
        Database $db,
        string $offlineCutoff,
        string $now,
        int $offlineMinutes,
        bool $dryRun
    ): array {
        $candidates = $db->fetchAll(
            'SELECT id, forward_id, node_id
             FROM service_endpoints
             WHERE health_status = :status
             ORDER BY id ASC',
            ['status' => 'deleting']
        );

        $deleted = 0;
        $cancelled = 0;
        foreach ($candidates as $candidate) {
            $endpointId = (int) ($candidate['id'] ?? 0);
            $forwardId = (int) ($candidate['forward_id'] ?? 0);
            $nodeId = (int) ($candidate['node_id'] ?? 0);
            if ($endpointId <= 0) {
                continue;
            }

            // Node must be provably gone: missing row OR stale heartbeat.
            $node = $db->fetchOne(
                'SELECT last_heartbeat_at FROM nodes WHERE id = :id LIMIT 1',
                ['id' => $nodeId]
            );
            $nodeGone = false;
            if (!is_array($node)) {
                $nodeGone = true;
            } else {
                $heartbeat = $node['last_heartbeat_at'] ?? null;
                if ($heartbeat === null || $heartbeat === '' || (string) $heartbeat < $offlineCutoff) {
                    $nodeGone = true;
                }
            }
            if (!$nodeGone) {
                continue;
            }

            // No live sibling endpoint for this (forward_id, node_id).
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
                continue;
            }

            // Latest destroy task must be 'pending' (queued but unrun). A running/completed/missing
            // destroy is not an orphan we finalize here.
            $latestDestroy = $db->fetchOne(
                'SELECT id, status FROM provision_tasks
                 WHERE endpoint_id = :endpoint_id
                   AND action = :action
                 ORDER BY id DESC
                 LIMIT 1',
                ['endpoint_id' => $endpointId, 'action' => 'destroy']
            );
            if (!is_array($latestDestroy) || (string) ($latestDestroy['status'] ?? '') !== 'pending') {
                continue;
            }
            $destroyTaskId = (int) ($latestDestroy['id'] ?? 0);

            if ($dryRun) {
                $deleted++;
                if ($destroyTaskId > 0) {
                    $cancelled++;
                }
                continue;
            }

            $applied = $db->execute(
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
            if ($applied <= 0) {
                continue; // raced -- endpoint changed under us; do not cancel its destroy
            }
            $deleted += $applied;

            if ($destroyTaskId > 0) {
                $cancelled += $db->execute(
                    'UPDATE provision_tasks
                     SET status = :new_status,
                         result_json = :result_json,
                         error_message = NULL,
                         lease_expires_at = NULL,
                         updated_at = :now
                     WHERE id = :id AND status = :old_status',
                    [
                        'new_status' => 'completed',
                        'result_json' => '{"note":"orphaned_destroy_unrunnable","offline_minutes":' . $offlineMinutes . '}',
                        'now' => $now,
                        'id' => $destroyTaskId,
                        'old_status' => 'pending',
                    ]
                );
            }
        }

        return ['deleted' => $deleted, 'cancelled' => $cancelled];
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
