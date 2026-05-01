ALTER TABLE forwards
    ADD COLUMN bond_remote_host VARCHAR(255) NULL AFTER target_port;

ALTER TABLE forwards
    ADD COLUMN bond_remote_port INT NULL AFTER bond_remote_host;
