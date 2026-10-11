-- 2026-10-11 Per-combination forward target allowlist.
--
-- A combination with a non-empty list (one CIDR per line) only accepts forwards
-- whose target IP falls inside one of the networks, e.g. an IX route such as
-- SHIXP that can only reach member networks. NULL keeps the combination
-- unrestricted, which is every existing row.
ALTER TABLE forward_combinations ADD COLUMN allowed_target_cidrs MEDIUMTEXT NULL;
