ALTER TABLE nodes
    ADD COLUMN machine_id VARCHAR(191) NULL AFTER private_ip;

ALTER TABLE nodes
    ADD INDEX idx_nodes_machine (machine_id);
