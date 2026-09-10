ALTER TABLE clients
    ADD COLUMN debug_collection_uuid VARCHAR(64) NULL;

ALTER TABLE clients
    ADD COLUMN debug_collection_expires_at DATETIME NULL;

ALTER TABLE clients
    ADD COLUMN debug_collection_status VARCHAR(16) NOT NULL DEFAULT 'closed';

ALTER TABLE clients
    ADD COLUMN debug_collection_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0;

ALTER TABLE clients
    ADD COLUMN debug_collection_next_sequence BIGINT UNSIGNED NOT NULL DEFAULT 0;

CREATE UNIQUE INDEX idx_clients_debug_collection_uuid
    ON clients (debug_collection_uuid);

CREATE TABLE IF NOT EXISTS client_debug_chunks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    collection_uuid VARCHAR(64) NOT NULL,
    sequence BIGINT UNSIGNED NOT NULL,
    chunk_sha256 CHAR(64) NOT NULL,
    byte_length INT UNSIGNED NOT NULL,
    storage_path VARCHAR(768) NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE (client_id, collection_uuid, sequence)
);

CREATE INDEX idx_client_debug_chunks_collection
    ON client_debug_chunks (client_id, collection_uuid);
