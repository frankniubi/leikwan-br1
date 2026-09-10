ALTER TABLE clients
    ADD COLUMN agent_config_json JSON NULL AFTER last_diag_error;

ALTER TABLE clients
    ADD COLUMN agent_update_request_token VARCHAR(128) NULL AFTER agent_config_json;

ALTER TABLE clients
    ADD COLUMN agent_update_requested_at DATETIME NULL AFTER agent_update_request_token;

ALTER TABLE nodes
    ADD COLUMN agent_config_json JSON NULL AFTER supports_filtering;

ALTER TABLE nodes
    ADD COLUMN agent_update_request_token VARCHAR(128) NULL AFTER agent_config_json;

ALTER TABLE nodes
    ADD COLUMN agent_update_requested_at DATETIME NULL AFTER agent_update_request_token;
