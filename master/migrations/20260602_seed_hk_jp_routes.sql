-- 20260602_seed_hk_jp_routes.sql
-- Seed two named Forward Routes — HK and JP — each wrapping one existing forward combination:
--   HK route -> forward_combination id 1
--   JP route -> forward_combination id 2
-- Race is OFF (one combo each = a renamed/grouped selector, not yet a racing route). Idempotent:
-- guarded so re-running creates no duplicates. EXISTS guards skip the link if the combo is absent.
-- NOTE: run AFTER 20260602_add_forward_routes.sql. To later turn a route into a racing route, add a
-- second enabled combination (same backend_mode + sidecar_point_id) and set forward_routes.race_enabled=1.

INSERT INTO forward_routes (name, tenant_label, tenant_description, tenant_badge, race_enabled, is_active, sort_order, created_at, updated_at)
SELECT 'HK', 'HK', NULL, NULL, 0, 1, 10, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM forward_routes WHERE name = 'HK');

INSERT INTO forward_routes (name, tenant_label, tenant_description, tenant_badge, race_enabled, is_active, sort_order, created_at, updated_at)
SELECT 'JP', 'JP', NULL, NULL, 0, 1, 20, NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM forward_routes WHERE name = 'JP');

INSERT INTO forward_route_combinations (forward_route_id, forward_combination_id, enabled, sort_order, created_at, updated_at)
SELECT fr.id, 1, 1, 100, NOW(), NOW()
FROM forward_routes fr
WHERE fr.name = 'HK'
  AND EXISTS (SELECT 1 FROM forward_combinations WHERE id = 1)
  AND NOT EXISTS (SELECT 1 FROM forward_route_combinations frc WHERE frc.forward_route_id = fr.id AND frc.forward_combination_id = 1);

INSERT INTO forward_route_combinations (forward_route_id, forward_combination_id, enabled, sort_order, created_at, updated_at)
SELECT fr.id, 2, 1, 100, NOW(), NOW()
FROM forward_routes fr
WHERE fr.name = 'JP'
  AND EXISTS (SELECT 1 FROM forward_combinations WHERE id = 2)
  AND NOT EXISTS (SELECT 1 FROM forward_route_combinations frc WHERE frc.forward_route_id = fr.id AND frc.forward_combination_id = 2);
