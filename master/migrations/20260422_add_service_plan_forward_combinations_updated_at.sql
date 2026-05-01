ALTER TABLE service_plan_forward_combinations
    ADD COLUMN updated_at DATETIME NOT NULL AFTER created_at;
