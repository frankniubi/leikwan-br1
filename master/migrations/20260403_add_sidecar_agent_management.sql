ALTER TABLE sidecar_points
    ADD COLUMN agent_update_request_token VARCHAR(128) NULL AFTER agent_version;

ALTER TABLE sidecar_points
    ADD COLUMN agent_update_requested_at DATETIME NULL AFTER agent_update_request_token;
