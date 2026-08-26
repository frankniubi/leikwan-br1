ALTER TABLE service_plans
    ADD COLUMN max_forward_rule_quantity INT NOT NULL DEFAULT 64 AFTER max_client_profiles;
