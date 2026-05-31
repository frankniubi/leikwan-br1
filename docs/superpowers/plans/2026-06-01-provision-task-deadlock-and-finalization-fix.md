# Plan: provision_tasks deadlock + heartbeat resilience + endpoint finalization

**Date:** 2026-06-01
**Incident:** Fleet-wide provisioning freeze since 2026-05-31 19:07. Forwards 65/66 stuck `pending`; 1733 endpoints stuck `deleting`; 3156 `provision_tasks` stuck `pending` (lease NULL, attempt 0).

## Root cause (confirmed live)
`NodeController::heartbeat` (master/src/Controllers/NodeController.php:222-233) runs `reconcileActiveForwardsForGroup` (x2) + `republishActiveEndpointsForNode` **inline and unguarded**. Inside, `queueTask` → `collapsePendingProvisionTasksForNode` (ProvisioningService.php:4565-4612) runs an `UPDATE provision_tasks ... WHERE node_id,action,service_id,forward_id,endpoint_id,status`. There was **no composite index** for that predicate, so MySQL used `index_merge intersect(idx_provision_tasks_node_status, fk_provision_tasks_endpoint)` — which row-locks via two indexes in inconsistent order. Concurrent heartbeats (every node, ~30s) → **InnoDB deadlock SQLSTATE[40001]/1213** → uncaught → heartbeat HTTP 500 (index.php emits HTML, no log) → agent skips its pull/ack cycle → tasks never drain → backlog grows → deadlocks worsen → permanent freeze. Creating forwards 65/66 pushed the backlog over the contention threshold at 19:07.

The "deleting never finalizes" and "65/66 pending" are the SAME freeze (destroy/publish task results never complete). Verified: `taskResult` (NodeController:559-578) DOES set endpoint active/deleted on completion — finalization works once the pipeline drains.

## P0 — operational hotfix (DONE, applied to prod 2026-06-01)
- Added online composite index: `ALTER TABLE provision_tasks ADD INDEX idx_provision_tasks_collapse (node_id, action, service_id, forward_id, endpoint_id, status)`. EXPLAIN now `type=ref, rows=1` — no index_merge → no deadlock. Unfreezes the fleet.

## Code hardening (this workflow; TDD + adversarial verify; deploy after)
Target: master in main repo C:\Users\frankniubi\Downloads\leikwan-br1.

1. **Schema migration for the index** — add `idx_provision_tasks_collapse` to master/migrations/schema.sql + a numbered migration, idempotent (IF NOT EXISTS pattern used by repo), so fresh installs + codebase match prod.
2. **Heartbeat resilience (cascade-stopper)** — wrap the inline `reconcileActiveForwardsForGroup`/`republishActiveEndpointsForNode` calls in `NodeController::heartbeat` in try/catch that `error_log`s and continues, so a DB deadlock or any single-forward throw can never 500 the heartbeat or block `tasks/pull`/`ack`. Heartbeat must still return the node's tasks even if reconcile fails.
3. **Deadlock retry (defense-in-depth)** — in `Database::execute` (or a focused wrapper used by `queueTask`/`collapsePendingProvisionTasksForNode`), retry on SQLSTATE 40001 (deadlock) up to 3x with tiny backoff; rethrow after.
4. **Dead-node finalization + stale-node demotion** (master/cli/cleanup-tasks.php) — when cancelling pending tasks for nodes offline > threshold, ALSO finalize their endpoints (destroy→deleted; pending publish→error) so dead-node (e.g. node3, offline 4d) endpoints don't linger forever; and flip `nodes.status` to offline when heartbeat age exceeds threshold (currently never demoted).

## Verification
- Behavioral PHP test scripts (repo style, standalone, exit non-zero on fail): deadlock-retry wrapper retries then succeeds; heartbeat-resilience returns tasks despite a throwing reconcile; cleanup finalizes a dead-node's deleting endpoint + demotes status. No source-grep tests.
- Live: confirm heartbeat 500s stopped, `provision_tasks` pending count drains, forwards 65/66 endpoints reach `active`, deleting backlog shrinks.

## Out of scope / follow-up
- Per-heartbeat re-queue churn (unstable fingerprint/allocator order, Agent 2) — latent amplifier, not the deadlock cause; revisit if churn remains high after the above.
