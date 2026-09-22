-- 2026-09-23 batch availability test: lift every dispatch_class='low' to
-- 'normal' so all Ultra lines are probed at equal priority. Permanent
-- re-classification will be re-introduced only after per-line verification
-- via 10.10.10.171 (leigod source VM) + material freshness + probe loss.
-- Reversible: re-derive via UltraInventoryService::dispatchClass() if needed.
UPDATE ultra_lines SET dispatch_class = 'normal' WHERE dispatch_class = 'low';
