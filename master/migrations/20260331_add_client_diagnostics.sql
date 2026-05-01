ALTER TABLE clients
    ADD COLUMN last_diag_requested_at DATETIME NULL AFTER last_sync_at;

ALTER TABLE clients
    ADD COLUMN last_diag_request_token VARCHAR(128) NULL AFTER last_diag_requested_at;

ALTER TABLE clients
    ADD COLUMN last_diag_status VARCHAR(32) NULL AFTER last_diag_request_token;

ALTER TABLE clients
    ADD COLUMN last_diag_run_at DATETIME NULL AFTER last_diag_status;

ALTER TABLE clients
    ADD COLUMN last_diag_result_json JSON NULL AFTER last_diag_run_at;

ALTER TABLE clients
    ADD COLUMN last_diag_error TEXT NULL AFTER last_diag_result_json;
