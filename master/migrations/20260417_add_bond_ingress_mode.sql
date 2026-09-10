ALTER TABLE forwards
    ADD COLUMN bond_ingress_mode VARCHAR(32) NOT NULL DEFAULT 'wss_path_mux' AFTER bond_remote_port;
