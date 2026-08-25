ALTER TABLE client_device_sessions
    ADD COLUMN client_version VARCHAR(64) NULL AFTER platform;

ALTER TABLE client_device_sessions
    ADD COLUMN os_release VARCHAR(191) NULL AFTER client_version;
