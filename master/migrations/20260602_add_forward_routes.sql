-- 20260602_add_forward_routes.sql
-- Forward Route = a user-facing bundle of forward combinations. The ingress slave auto-races across
-- the route's enabled combinations (cnix / leigod; same backend_mode per B0) and fails over when a
-- basis degrades. Admin can disable racing and enable/disable each combination in a route.

CREATE TABLE forward_routes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(191) NOT NULL,
    tenant_label VARCHAR(191) NULL,
    tenant_description TEXT NULL,
    tenant_badge VARCHAR(64) NULL,
    race_enabled TINYINT(1) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 100,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE forward_route_combinations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    forward_route_id BIGINT UNSIGNED NOT NULL,
    forward_combination_id BIGINT UNSIGNED NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 100,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_route_combo_route FOREIGN KEY (forward_route_id) REFERENCES forward_routes(id),
    CONSTRAINT fk_route_combo_combo FOREIGN KEY (forward_combination_id) REFERENCES forward_combinations(id),
    UNIQUE KEY uniq_route_combination (forward_route_id, forward_combination_id)
);

CREATE TABLE service_plan_forward_routes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    plan_id BIGINT UNSIGNED NOT NULL,
    forward_route_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_plan_route_plan FOREIGN KEY (plan_id) REFERENCES service_plans(id),
    CONSTRAINT fk_plan_route_route FOREIGN KEY (forward_route_id) REFERENCES forward_routes(id),
    UNIQUE KEY uniq_plan_forward_route (plan_id, forward_route_id)
);

-- Bind a forward to a Route (kept alongside forward_combination_id during transition; the latter is
-- seeded from the route's active/primary combination for back-compat). active_combination_id records
-- the currently-winning basis reported by the slave for admin observability + stable port seeding.
ALTER TABLE forwards
    ADD COLUMN forward_route_id BIGINT UNSIGNED NULL AFTER forward_combination_id,
    ADD COLUMN active_combination_id BIGINT UNSIGNED NULL AFTER forward_route_id;
