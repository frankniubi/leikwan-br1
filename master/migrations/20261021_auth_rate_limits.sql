-- 2026-10-21 Brute-force limits on the credential endpoints.
--
-- auth_rate_limits holds AuthRateLimiter's fixed windows for login, password
-- reset and Buy station binding. bucket_key is sha256(scope, identifier), so
-- no email or IP address is stored; expired rows are pruned by the limiter.
--
-- whmcs_login_challenges.failed_attempts burns a binding code after five
-- wrong submissions (WhmcsIdentityService::MAX_CHALLENGE_ATTEMPTS).
CREATE TABLE IF NOT EXISTS auth_rate_limits (
    bucket_key CHAR(64) NOT NULL PRIMARY KEY,
    window_started_at BIGINT UNSIGNED NOT NULL,
    hits INT UNSIGNED NOT NULL DEFAULT 0,
    expires_at BIGINT UNSIGNED NOT NULL,
    INDEX idx_auth_rate_limits_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE whmcs_login_challenges ADD COLUMN failed_attempts INT UNSIGNED NOT NULL DEFAULT 0;
