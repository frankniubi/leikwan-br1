-- 20260802_reconcile_service_plan_forward_routes.sql
-- Reconcile route authorization from the legacy combination allowlist. Each statement is
-- independently retryable because MigrationRunner executes and commits statements one by one.

-- Revoke stale or inactive route authorizations first so interrupted execution fails closed.
DELETE spfr
FROM service_plan_forward_routes spfr
WHERE NOT EXISTS (
    SELECT 1
    FROM service_plan_forward_combinations spfc
    INNER JOIN forward_combinations fc ON fc.id = spfc.forward_combination_id
    INNER JOIN forward_route_combinations frc ON frc.forward_combination_id = fc.id
    INNER JOIN forward_routes fr ON fr.id = frc.forward_route_id
    WHERE spfc.plan_id = spfr.plan_id
      AND frc.forward_route_id = spfr.forward_route_id
      AND fc.is_active = 1
      AND frc.enabled = 1
      AND fr.is_active = 1
);

-- Fill every currently valid route authorization without relying on duplicate-key suppression.
INSERT INTO service_plan_forward_routes (plan_id, forward_route_id, created_at, updated_at)
SELECT DISTINCT spfc.plan_id, frc.forward_route_id, NOW(), NOW()
FROM service_plan_forward_combinations spfc
INNER JOIN forward_combinations fc ON fc.id = spfc.forward_combination_id
INNER JOIN forward_route_combinations frc ON frc.forward_combination_id = fc.id
INNER JOIN forward_routes fr ON fr.id = frc.forward_route_id
WHERE fc.is_active = 1
  AND frc.enabled = 1
  AND fr.is_active = 1
  AND NOT EXISTS (
      SELECT 1
      FROM service_plan_forward_routes spfr
      WHERE spfr.plan_id = spfc.plan_id
        AND spfr.forward_route_id = frc.forward_route_id
  );
