ALTER TABLE forward_combinations
    ADD COLUMN slave_wg_ideal_latency_ms INT NULL AFTER desired_latency_ms;
