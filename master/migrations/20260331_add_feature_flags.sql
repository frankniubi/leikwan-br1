ALTER TABLE users
    ADD COLUMN feature_flags_json JSON NULL AFTER role;

ALTER TABLE service_plans
    ADD COLUMN feature_flags_json JSON NULL AFTER is_active;
