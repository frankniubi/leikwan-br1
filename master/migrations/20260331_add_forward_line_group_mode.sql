ALTER TABLE forwards
    ADD COLUMN line_group_mode VARCHAR(32) NOT NULL DEFAULT 'selected' AFTER line_client_group_id;

ALTER TABLE forwards
    ADD COLUMN line_client_group_ids_json JSON NULL AFTER line_group_mode;
