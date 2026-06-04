-- 20260604_leigod_mode_true_mptcp.sql
-- Phase 2: leigod_mode selects true MPTCP client->slave ingress by default.
-- Explicit bond remains bond. Combo-backed forwards read denormalized forwards.client_mode, so keep
-- forward rows synchronized with their normalized combinations. Idempotent (safe to re-run).

-- 1. Legacy/invalid leigod_mode combinations were effectively "single" in the UI, but leigod_mode
--    now means true MPTCP unless the operator explicitly chose bond.
UPDATE forward_combinations
SET client_mode = 'mptcp',
    runtime_preference = 'single',
    updated_at = NOW()
WHERE backend_mode = 'leigod_mode'
  AND (client_mode IS NULL OR client_mode NOT IN ('mptcp', 'bond'));

-- 2. leigod_mode+mptcp is true MPTCP, not the hybrid MPTCP-preferred bond fallback runtime.
UPDATE forward_combinations
SET runtime_preference = 'single',
    updated_at = NOW()
WHERE backend_mode = 'leigod_mode'
  AND client_mode = 'mptcp'
  AND runtime_preference = 'mptcp_preferred_bond_fallback';

-- 3. Combo-backed forwards use denormalized ingress columns at publish/runtime time. Align them with
--    normalized leigod_mode combinations, including forwards.client_mode; keep this UPDATE a no-op
--    once the fields already match.
UPDATE forwards f
JOIN forward_combinations fc ON fc.id = f.forward_combination_id
SET f.client_mode = fc.client_mode,
    f.backend_mode = fc.backend_mode,
    f.cf_location_group_id = NULL,
    f.updated_at = NOW()
WHERE fc.backend_mode = 'leigod_mode'
  AND fc.client_mode IN ('mptcp', 'bond')
  AND (
      f.client_mode IS NULL
      OR f.client_mode <> fc.client_mode
      OR f.backend_mode IS NULL
      OR f.backend_mode <> fc.backend_mode
      OR f.cf_location_group_id IS NOT NULL
  );
