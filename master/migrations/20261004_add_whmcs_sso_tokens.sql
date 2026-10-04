-- 2026-10-04 WHMCS server module single sign-on.
--
-- POST /api/whmcs/sso (webhook token) issues a 60-second, single-use link that
-- signs the WHMCS client's bound panel account in at GET /auth/whmcs/sso.
-- Only the SHA-256 of the token is stored. Expired rows are pruned on issue.
CREATE TABLE IF NOT EXISTS whmcs_sso_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    token_hash CHAR(64) NOT NULL UNIQUE,
    user_id BIGINT UNSIGNED NOT NULL,
    service_id BIGINT UNSIGNED NULL,
    redirect_path VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_whmcs_sso_tokens_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
