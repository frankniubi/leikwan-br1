ALTER TABLE clients
    ADD COLUMN default_endpoint_mode VARCHAR(32) NOT NULL DEFAULT 'slave_group' AFTER allow_inbound_proxy;

ALTER TABLE clients
    ADD COLUMN default_node_group_key VARCHAR(191) NULL AFTER default_endpoint_mode;

ALTER TABLE clients
    ADD COLUMN default_forward_chain_id BIGINT UNSIGNED NULL AFTER default_node_group_key;

ALTER TABLE clients
    ADD CONSTRAINT fk_clients_default_chain FOREIGN KEY (default_forward_chain_id) REFERENCES forward_chains(id);
