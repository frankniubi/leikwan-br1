ALTER TABLE service_plans
    ADD COLUMN traffic_reset_price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER traffic_price_per_gb;
