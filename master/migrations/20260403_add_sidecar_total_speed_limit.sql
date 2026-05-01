ALTER TABLE sidecar_points
    ADD COLUMN total_speed_limit_mbps INT NULL AFTER bind_host;
