ALTER TABLE service_plans
    ADD COLUMN description TEXT NULL AFTER product_code;

ALTER TABLE service_plans
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER default_traffic_limit_gb;

ALTER TABLE node_groups
    ADD COLUMN description TEXT NULL AFTER name;

CREATE TABLE service_plan_node_groups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    plan_id BIGINT UNSIGNED NOT NULL,
    node_group_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_plan_group_plan FOREIGN KEY (plan_id) REFERENCES service_plans(id),
    CONSTRAINT fk_plan_group_node_group FOREIGN KEY (node_group_id) REFERENCES node_groups(id),
    UNIQUE KEY uniq_plan_node_group (plan_id, node_group_id)
);
