ALTER TABLE sidecar_points
    ADD COLUMN bond_listen_port INT NULL AFTER listen_port,
    ADD COLUMN mptcp_listen_port INT NULL AFTER bond_listen_port;

UPDATE sidecar_points
SET bond_listen_port = CASE
        WHEN bond_listen_port IS NULL OR bond_listen_port <= 0 THEN listen_port
        ELSE bond_listen_port
    END,
    mptcp_listen_port = CASE
        WHEN mptcp_listen_port IS NULL OR mptcp_listen_port <= 0 THEN COALESCE(
            CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(agent_config_json, '$.wireguard_listen_port')), '') AS UNSIGNED),
            listen_port
        )
        ELSE mptcp_listen_port
    END;

ALTER TABLE sidecar_points
    MODIFY COLUMN bond_listen_port INT NOT NULL,
    MODIFY COLUMN mptcp_listen_port INT NOT NULL;
