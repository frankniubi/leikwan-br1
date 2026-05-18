ALTER TABLE forward_combinations
    ADD COLUMN desired_latency_ms INT NOT NULL DEFAULT 0 AFTER self_leg_cf_transport,
    ADD COLUMN allow_public_self_leg_donation TINYINT(1) NOT NULL DEFAULT 0 AFTER desired_latency_ms,
    ADD COLUMN donation_qualify_window_seconds INT NOT NULL DEFAULT 60 AFTER allow_public_self_leg_donation,
    ADD COLUMN donation_revoke_window_seconds INT NOT NULL DEFAULT 30 AFTER donation_qualify_window_seconds,
    ADD COLUMN donation_min_open_ports INT NOT NULL DEFAULT 1 AFTER donation_revoke_window_seconds;
