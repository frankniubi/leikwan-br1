ALTER TABLE sidecar_points
    ADD COLUMN IF NOT EXISTS agent_config_json JSON NULL AFTER agent_version;
