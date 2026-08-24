ALTER TABLE ultra_sources ADD COLUMN display_label VARCHAR(191) NULL;
ALTER TABLE ultra_sources ADD COLUMN priority INT NOT NULL DEFAULT 100;
ALTER TABLE ultra_sources ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1;
CREATE INDEX idx_ultra_sources_priority ON ultra_sources (is_active, priority, source_id);
UPDATE ultra_sources
SET display_label = COALESCE(NULLIF(display_label, ''), source_id),
    priority = CASE WHEN source_id = 'source-home-171' THEN 10 ELSE priority END,
    is_active = 1
WHERE display_label IS NULL OR display_label = '' OR source_id = 'source-home-171';
