-- 20260603_promote_leigod_mode.sql
-- Promote leigod from a self-leg basis to a first-class Forward Mode (backend_mode='leigod_mode').
-- The leigod_mode contract = DIRECT client->slave ingress (like current_mode, no Cloudflare, no
-- self-leg CF primary leg) + a LeiGod slave<->sidecar self-leg via the lguot UoT carrier.
-- Convert every combination whose self-leg basis is leigod to leigod_mode and strip ALL Cloudflare
-- coupling. Idempotent (safe to re-run).

-- 1. Combinations: flip to leigod_mode + drop CF ingress/self-leg coupling; default carrier lguot.
UPDATE forward_combinations
SET backend_mode = 'leigod_mode',
    cf_location_group_id = NULL,
    allow_private_self_leg = 0,
    self_leg_cf_transport = 'cf_direct',
    leigod_uot_impl = 'lguot',
    updated_at = NOW()
WHERE self_leg_outer_transport = 'leigod'
  AND backend_mode <> 'leigod_mode';

-- 2. Forwards bound to those combos: clear the stale per-forward CF location group, otherwise
--    forwardUsesCfMode() ('cf_location_group_id > 0') keeps reporting CF on e.g. forwards 65/67
--    even after the combo flips -- the CF-ingress drag would survive. Also align the denormalized
--    forwards.backend_mode. CRITICAL: without this the cutover is incomplete.
UPDATE forwards f
JOIN forward_combinations fc ON fc.id = f.forward_combination_id
SET f.cf_location_group_id = NULL,
    f.backend_mode = 'leigod_mode'
WHERE fc.backend_mode = 'leigod_mode'
  AND (f.cf_location_group_id IS NOT NULL OR f.backend_mode <> 'leigod_mode');
