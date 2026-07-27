CREATE TABLE leigod_lines (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    line_id INT UNSIGNED NOT NULL,
    label VARCHAR(191) NOT NULL,
    notes TEXT NULL,
    tcp_probe_host VARCHAR(255) NOT NULL,
    tcp_probe_port INT UNSIGNED NOT NULL,
    udp_probe_host VARCHAR(255) NULL,
    udp_probe_port INT UNSIGNED NULL,
    udp_probe_method VARCHAR(32) NULL,
    config_sha256 CHAR(64) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 100,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_leigod_external_line (line_id)
);

CREATE TABLE forward_combination_leigod_lines (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    forward_combination_id BIGINT UNSIGNED NOT NULL,
    leigod_line_id BIGINT UNSIGNED NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 100,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_fcll_combination FOREIGN KEY (forward_combination_id) REFERENCES forward_combinations(id),
    CONSTRAINT fk_fcll_line FOREIGN KEY (leigod_line_id) REFERENCES leigod_lines(id),
    UNIQUE KEY uniq_combination_leigod_line (forward_combination_id, leigod_line_id)
);

CREATE TABLE node_leigod_line_health (
    node_id BIGINT UNSIGNED NOT NULL,
    leigod_line_id BIGINT UNSIGNED NOT NULL,
    config_sha256 CHAR(64) NOT NULL,
    service_state VARCHAR(32) NOT NULL,
    tcp_status VARCHAR(32) NOT NULL,
    tcp_connect_ms INT UNSIGNED NULL,
    udp_status VARCHAR(32) NOT NULL,
    consecutive_successes INT UNSIGNED NOT NULL DEFAULT 0,
    consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(500) NULL,
    checked_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (node_id, leigod_line_id),
    CONSTRAINT fk_nllh_node FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE CASCADE,
    CONSTRAINT fk_nllh_line FOREIGN KEY (leigod_line_id) REFERENCES leigod_lines(id),
    INDEX idx_nllh_line_freshness (leigod_line_id, checked_at)
);

INSERT INTO leigod_lines (
    line_id,
    label,
    notes,
    tcp_probe_host,
    tcp_probe_port,
    udp_probe_host,
    udp_probe_port,
    udp_probe_method,
    config_sha256,
    is_active,
    sort_order,
    created_at,
    updated_at
)
SELECT DISTINCT
    fc.leigod_line_id,
    CONCAT('Legacy line ', fc.leigod_line_id),
    NULL,
    '',
    0,
    NULL,
    NULL,
    NULL,
    REPEAT('0', 64),
    0,
    100,
    NOW(),
    NOW()
FROM forward_combinations fc
WHERE fc.leigod_line_id BETWEEN 1 AND 45535
ON DUPLICATE KEY UPDATE
    line_id = VALUES(line_id);

INSERT INTO forward_combination_leigod_lines (
    forward_combination_id,
    leigod_line_id,
    enabled,
    sort_order,
    created_at,
    updated_at
)
SELECT
    fc.id,
    ll.id,
    1,
    100,
    NOW(),
    NOW()
FROM forward_combinations fc
INNER JOIN leigod_lines ll ON ll.line_id = fc.leigod_line_id
WHERE fc.leigod_line_id BETWEEN 1 AND 45535
ON DUPLICATE KEY UPDATE
    leigod_line_id = VALUES(leigod_line_id);
