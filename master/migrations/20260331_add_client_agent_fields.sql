ALTER TABLE clients
    ADD COLUMN agent_token VARCHAR(128) NULL AFTER group_id;

ALTER TABLE clients
    ADD COLUMN agent_version VARCHAR(64) NULL AFTER agent_token;

ALTER TABLE clients
    ADD COLUMN last_sync_at DATETIME NULL AFTER agent_version;
