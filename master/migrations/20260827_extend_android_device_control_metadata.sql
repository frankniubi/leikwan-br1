ALTER TABLE client_device_controls
    ADD COLUMN reason_code VARCHAR(64) NULL AFTER state;

ALTER TABLE client_device_controls
    ADD COLUMN changed_by_user_id BIGINT UNSIGNED NULL AFTER reason_code;

ALTER TABLE client_device_controls
    ADD COLUMN changed_at DATETIME NULL AFTER changed_by_user_id;

ALTER TABLE client_device_controls
    ADD COLUMN acknowledged_at DATETIME NULL AFTER last_acknowledged_command_version;
