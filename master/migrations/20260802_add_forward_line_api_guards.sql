-- 20260802_add_forward_line_api_guards.sql
-- Persist only hashed API idempotency metadata and a monotonic resource revision.

ALTER TABLE forwards
    ADD COLUMN api_revision BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER service_id,
    ADD COLUMN api_idempotency_key_hash CHAR(64) NULL AFTER api_revision,
    ADD COLUMN api_request_hash CHAR(64) NULL AFTER api_idempotency_key_hash,
    ADD UNIQUE KEY uniq_forward_service_idempotency (service_id, api_idempotency_key_hash);
