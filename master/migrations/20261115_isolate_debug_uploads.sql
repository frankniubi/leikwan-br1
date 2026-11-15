ALTER TABLE client_debug_chunks
    ADD COLUMN IF NOT EXISTS upload_id VARCHAR(36) NOT NULL DEFAULT '';

-- The original unnamed UNIQUE constraint is named after its first column by
-- MySQL/MariaDB. Fresh schemas already use the named per-upload constraint.
ALTER TABLE client_debug_chunks DROP INDEX IF EXISTS client_id;

CREATE UNIQUE INDEX idx_client_debug_chunks_upload
    ON client_debug_chunks (client_id, collection_uuid, upload_id, sequence);
