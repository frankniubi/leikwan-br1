ALTER TABLE forward_combinations
    ADD COLUMN self_leg_cf_transport VARCHAR(16) NOT NULL DEFAULT 'cf_direct'
        COMMENT 'Self-leg transport under CF Bond. Values: cf_direct (default) | cf_wg.'
    AFTER helper_escalation_policy;
