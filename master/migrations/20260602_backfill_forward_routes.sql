-- 20260602_backfill_forward_routes.sql
-- Wrap each legacy forward_combination in a single-combination Forward Route (race disabled), then
-- bind every legacy forward to its combination's singleton route. Idempotent: guarded so re-running
-- does not create duplicate routes or re-bind forwards that already have a route.

-- 1. One singleton route per combination that does not yet belong to any route.
INSERT INTO forward_routes (name, tenant_label, tenant_description, tenant_badge, race_enabled, is_active, sort_order, created_at, updated_at)
SELECT CONCAT('route-combo-', fc.id), fc.tenant_label, fc.tenant_description, fc.tenant_badge, 0, fc.is_active, fc.sort_order, NOW(), NOW()
FROM forward_combinations fc
WHERE NOT EXISTS (
    SELECT 1 FROM forward_route_combinations frc WHERE frc.forward_combination_id = fc.id
);

-- 2. Link each combination into its singleton route (enabled).
INSERT INTO forward_route_combinations (forward_route_id, forward_combination_id, enabled, sort_order, created_at, updated_at)
SELECT fr.id, fc.id, 1, 100, NOW(), NOW()
FROM forward_combinations fc
JOIN forward_routes fr ON fr.name = CONCAT('route-combo-', fc.id)
WHERE NOT EXISTS (
    SELECT 1 FROM forward_route_combinations frc WHERE frc.forward_route_id = fr.id AND frc.forward_combination_id = fc.id
);

-- 3. Bind legacy forwards (no route yet) to their combination's singleton route.
UPDATE forwards f
JOIN forward_routes fr ON fr.name = CONCAT('route-combo-', f.forward_combination_id)
SET f.forward_route_id = fr.id, f.active_combination_id = f.forward_combination_id
WHERE f.forward_route_id IS NULL AND f.forward_combination_id IS NOT NULL;

-- 4. Migrate plan->combination gating to plan->route gating (singleton routes).
INSERT INTO service_plan_forward_routes (plan_id, forward_route_id, created_at, updated_at)
SELECT spfc.plan_id, fr.id, NOW(), NOW()
FROM service_plan_forward_combinations spfc
JOIN forward_routes fr ON fr.name = CONCAT('route-combo-', spfc.forward_combination_id)
WHERE NOT EXISTS (
    SELECT 1 FROM service_plan_forward_routes spfr WHERE spfr.plan_id = spfc.plan_id AND spfr.forward_route_id = fr.id
);
