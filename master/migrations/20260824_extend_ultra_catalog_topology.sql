ALTER TABLE ultra_lines
    ADD COLUMN IF NOT EXISTS catalog_ingress_label VARCHAR(191) NULL;

ALTER TABLE ultra_lines
    ADD COLUMN IF NOT EXISTS catalog_destination_label VARCHAR(191) NULL;
