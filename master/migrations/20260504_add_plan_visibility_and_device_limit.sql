ALTER TABLE service_plans ADD COLUMN is_tenant_visible TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active;

ALTER TABLE service_plans ADD COLUMN default_client_online_device_limit INT NOT NULL DEFAULT 1 AFTER extra_client_price;
