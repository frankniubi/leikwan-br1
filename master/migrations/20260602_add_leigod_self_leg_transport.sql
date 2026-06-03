-- 20260602_add_leigod_self_leg_transport.sql
-- LeiGod = a 3rd slave<->sidecar WG outer-transport. Selected per forward_combination.
ALTER TABLE forward_combinations
  ADD COLUMN self_leg_outer_transport ENUM('cnix','cf','leigod') NOT NULL DEFAULT 'cnix'
    COMMENT 'slave<->sidecar WG outer transport basis' AFTER self_leg_cf_transport,
  ADD COLUMN leigod_line_id INT UNSIGNED NULL
    COMMENT 'chosen LeiGod line; slave SOCKS = 127.0.0.1:(20000+leigod_line_id)' AFTER self_leg_outer_transport,
  ADD COLUMN leigod_socks_user VARCHAR(64) NULL COMMENT 'panel-minted gost SOCKS user' AFTER leigod_line_id,
  ADD COLUMN leigod_socks_pass VARCHAR(128) NULL COMMENT 'panel-minted gost SOCKS pass (secret)' AFTER leigod_socks_user;
-- Per-slave LeiGod account metadata lives in nodes.agent_config_json->'$.leigod'
-- (account_label, expired_time, session_present); the session.json blob is delivered
-- via management state, never a DB column.
