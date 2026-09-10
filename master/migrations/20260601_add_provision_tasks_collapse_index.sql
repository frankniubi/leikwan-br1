-- Composite index backing collapsePendingProvisionTasksForNode's WHERE predicate
-- (node_id, action, service_id, forward_id, endpoint_id, status). Without it MySQL
-- resolved the UPDATE via an index_merge intersect of two single-column indexes and
-- locked the matched rows in inconsistent order across concurrent node heartbeats,
-- producing InnoDB deadlocks (SQLSTATE 40001 / error 1213) that 500'd the heartbeat
-- and froze the task queue fleet-wide. Idempotent: the MigrationRunner ignores a
-- "duplicate key name" error, so re-running on a DB that already has the prod hotfix
-- is a no-op.
ALTER TABLE provision_tasks
    ADD INDEX idx_provision_tasks_collapse (node_id, action, service_id, forward_id, endpoint_id, status);
