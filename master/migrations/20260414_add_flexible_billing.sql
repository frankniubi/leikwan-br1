-- Module B: Flexible per-node/per-sidecar traffic billing

CREATE TABLE IF NOT EXISTS traffic_prices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    resource_type ENUM('slave', 'sidecar') NOT NULL,
    node_id INT NULL COMMENT 'nodes.id (slave) or sidecar_points.id (sidecar)',
    node_group_id INT NULL COMMENT 'node_groups.id (slave only)',
    price_per_gb DECIMAL(10, 4) NOT NULL DEFAULT 0.0000,
    currency VARCHAR(8) NOT NULL DEFAULT 'CNY',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS service_plan_sidecar_points (
    plan_id INT NOT NULL,
    sidecar_point_id INT NOT NULL,
    PRIMARY KEY (plan_id, sidecar_point_id)
);

ALTER TABLE users
    ADD COLUMN wallet_low_balance_threshold DECIMAL(10,2) NOT NULL DEFAULT 5.00,
    ADD COLUMN wallet_warning_sent TINYINT(1) NOT NULL DEFAULT 0;
