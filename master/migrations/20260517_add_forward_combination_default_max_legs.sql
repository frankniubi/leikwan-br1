-- Reuse helper_group_cap as the panel-facing "Max Legs Used (cap)" knob.
-- The column already exists; this migration only adds a comment for operators.
ALTER TABLE forward_combinations
    MODIFY COLUMN helper_group_cap INT NOT NULL DEFAULT 4
    COMMENT 'Max legs used for bond/CF Bond/MPTCP. 0 = unlimited. Default 4.';
