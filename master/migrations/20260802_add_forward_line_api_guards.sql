-- 20260802_add_forward_line_api_guards.sql
-- Persist only hashed API idempotency metadata and a monotonic resource revision.

ALTER TABLE forwards
    ADD COLUMN api_revision BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER service_id;

ALTER TABLE forwards
    ADD COLUMN api_idempotency_key_hash CHAR(64) NULL AFTER api_revision;

ALTER TABLE forwards
    ADD COLUMN api_request_hash CHAR(64) NULL AFTER api_idempotency_key_hash;

ALTER TABLE forwards
    ADD COLUMN user_deleted_at DATETIME NULL AFTER api_request_hash;

ALTER TABLE forwards
    ADD COLUMN balance_suspended_at DATETIME NULL AFTER user_deleted_at;

-- Preserve explicit user deletions first. These rows must never be revived by
-- the balance-reactivation flow.
UPDATE forwards AS f
SET f.user_deleted_at = COALESCE(
    (
        SELECT MAX(a.created_at)
        FROM audit_logs AS a
        WHERE a.action = 'forward.deleted'
          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(a.context_json, '$.forward_id')) AS UNSIGNED) = f.id
          AND a.created_at >= f.created_at
    ),
    f.updated_at,
    NOW()
)
WHERE f.enabled = 0
  AND f.user_deleted_at IS NULL
  AND EXISTS (
      SELECT 1
      FROM audit_logs AS a
      WHERE a.action = 'forward.deleted'
        AND CAST(JSON_UNQUOTE(JSON_EXTRACT(a.context_json, '$.forward_id')) AS UNSIGNED) = f.id
        AND a.created_at >= f.created_at
  );

-- Historical billing audits identify only a user, not each affected forward.
-- Leave unclassified disabled rows with both reason columns NULL so they cannot
-- be guessed into an automatically reactivatable state.

ALTER TABLE forwards
    ADD UNIQUE KEY uniq_forward_service_idempotency (service_id, api_idempotency_key_hash);
