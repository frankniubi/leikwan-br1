-- Change default client_mode from 'multi-path' to 'auto'
-- Existing forwards keep their current mode; only new forwards default to 'auto'.
ALTER TABLE forwards ALTER COLUMN client_mode SET DEFAULT 'auto';
