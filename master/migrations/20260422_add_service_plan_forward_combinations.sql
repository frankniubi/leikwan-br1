CREATE TABLE IF NOT EXISTS service_plan_forward_combinations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    plan_id BIGINT UNSIGNED NOT NULL,
    forward_combination_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_plan_forward_combination_plan FOREIGN KEY (plan_id) REFERENCES service_plans(id),
    CONSTRAINT fk_plan_forward_combination_combo FOREIGN KEY (forward_combination_id) REFERENCES forward_combinations(id),
    UNIQUE KEY uniq_plan_forward_combination (plan_id, forward_combination_id)
);
