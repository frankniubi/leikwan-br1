ALTER TABLE nodes
    ADD COLUMN agent_version VARCHAR(64) NULL AFTER api_token;
