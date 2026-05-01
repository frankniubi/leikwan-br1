ALTER TABLE clients
    ADD COLUMN is_default_for_owner TINYINT(1) NOT NULL DEFAULT 0 AFTER owner_user_id;

ALTER TABLE clients
    ADD INDEX idx_clients_owner_default_status (owner_user_id, is_default_for_owner, status);
