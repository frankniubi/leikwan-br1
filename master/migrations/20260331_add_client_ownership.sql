ALTER TABLE clients
    ADD COLUMN owner_user_id BIGINT UNSIGNED NULL AFTER group_id;

ALTER TABLE clients
    ADD CONSTRAINT fk_clients_owner_user FOREIGN KEY (owner_user_id) REFERENCES users(id);
