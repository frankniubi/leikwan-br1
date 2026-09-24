-- Operator override for a line's dispatch class. The source inventory rewrites
-- dispatch_class on every upload, so an operator decision needs its own
-- column; NULL follows the source. Only per-line transport evidence justifies
-- a demotion (see 20260923_remove_ultra_low_priority_batch_test.sql).
ALTER TABLE ultra_lines
    ADD COLUMN IF NOT EXISTS admin_dispatch_class VARCHAR(16) NULL DEFAULT NULL AFTER dispatch_class,
    ADD COLUMN IF NOT EXISTS admin_dispatch_note VARCHAR(255) NULL DEFAULT NULL AFTER admin_dispatch_class;

-- 2026-09-24 acceptance matrix, SHA -> LAX: individual carrier connections on
-- line 694 slow to 1.3-2.0 Mbps for tens of seconds while their siblings stay
-- healthy; External-UoT UDP through it lost 7.9-13.9 % (Windows, macOS). The
-- same direction on line 431 was clean.
UPDATE ultra_lines
   SET admin_dispatch_class = 'low',
       admin_dispatch_note = '2026-09-24: unstable carrier connections (UoT UDP loss 7.9-13.9 % on SHA->LAX); prefer other lines'
 WHERE line_id = 694 AND admin_dispatch_class IS NULL;
