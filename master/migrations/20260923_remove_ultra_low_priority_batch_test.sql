-- One-time cleanup of legacy dispatch classes inferred from provider/title
-- markers. Those labels were not per-line transport-health evidence. Historical
-- values cannot be reconstructed from this table alone; use the deployment
-- backup if an audit is needed. New imports may mark a line low only when the
-- source explicitly declares that per-line classification.
UPDATE ultra_lines SET dispatch_class = 'normal' WHERE dispatch_class = 'low';
