ALTER TABLE service_plans
    ADD COLUMN bandwidth_step_mbps INT NOT NULL DEFAULT 0 AFTER traffic_price_per_gb;

ALTER TABLE service_plans
    ADD COLUMN bandwidth_step_price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER bandwidth_step_mbps;

ALTER TABLE service_plans
    ADD COLUMN included_client_count INT NOT NULL DEFAULT 0 AFTER bandwidth_step_price;

ALTER TABLE service_plans
    ADD COLUMN extra_client_price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER included_client_count;
