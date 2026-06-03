-- 20260603_add_forward_combination_leigod_uot_impl.sql
-- leigod_uot_impl selects the UoT carrier for the LeiGod arm of a forward_combination:
--   'gost'  (default, unchanged) — gost UDP-over-TCP SOCKS server/client
--   'lguot' (CPU-efficient batched recvmmsg/sendmmsg + token auth; ~2.4x throughput at 1/3 CPU
--            on a 2-vCPU slave: 178 vs 73 Mbit/s, measured node19<->nrt1 line 453).
-- Published to BOTH the slave and sidecar uplink for the lane (both ends must agree).
ALTER TABLE forward_combinations
  ADD COLUMN leigod_uot_impl ENUM('gost','lguot') NOT NULL DEFAULT 'gost'
    COMMENT 'LeiGod arm UoT carrier: gost (default) or lguot (CPU-efficient)' AFTER leigod_socks_pass;
