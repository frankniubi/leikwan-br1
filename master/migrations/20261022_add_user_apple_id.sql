-- 2026-10-22 Apple ID for the iOS TestFlight invite.
--
-- A tenant submits their Apple ID on the Clients page before the TestFlight
-- invite link unlocks. One account keeps one Apple ID: the column is written
-- once (from NULL), and the value stays visible on Account Security and in the
-- admin tenant directory so the invite can be traced back to an account.
ALTER TABLE users
    ADD COLUMN apple_id VARCHAR(191) NULL AFTER wallet_warning_sent,
    ADD COLUMN apple_id_submitted_at DATETIME NULL AFTER apple_id;
