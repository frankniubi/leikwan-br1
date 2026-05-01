CREATE TABLE ssl_certificate_tasks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    certificate_id BIGINT UNSIGNED NOT NULL,
    task_type VARCHAR(64) NOT NULL DEFAULT 'issue',
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    requested_by_user_id BIGINT UNSIGNED NULL,
    output_log LONGTEXT NULL,
    error_message TEXT NULL,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_ssl_certificate_tasks_certificate FOREIGN KEY (certificate_id) REFERENCES ssl_certificates(id),
    INDEX idx_ssl_certificate_tasks_status (status),
    INDEX idx_ssl_certificate_tasks_certificate (certificate_id)
);
