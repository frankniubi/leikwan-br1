-- Devices whose reports a source review disproved carry strikes, and enough
-- strikes demote a device so its failures stop counting as independent
-- observers. Nothing cleared a strike, so one client bug could demote a device
-- permanently. A strike now decays after a window with no new rebuttal, and the
-- device gets its standing back once it is below the demotion threshold.
ALTER TABLE ultra_observer_devices
    ADD COLUMN IF NOT EXISTS last_decay_at DATETIME NULL AFTER last_rebuttal_at;
