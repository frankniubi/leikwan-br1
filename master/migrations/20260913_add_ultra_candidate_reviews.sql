-- Automatic re-review of quarantined candidates by the source host, plus the
-- per-device credibility that decides how much weight a client carries when it
-- reports a candidate as broken.
--
-- A client on a bad local network reports healthy lines as dead; the source
-- host sees the line from the provider side and can disprove it. A review that
-- passes clears the quarantine and rebuts the devices that reported it, so a
-- device that keeps being rebutted stops being able to quarantine a line on its
-- own.

CREATE TABLE IF NOT EXISTS ultra_candidate_reviews (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    candidate_key_hash CHAR(64) NOT NULL,
    source_id VARCHAR(191) NOT NULL,
    line_id INT UNSIGNED NOT NULL,
    protocol VARCHAR(16) NOT NULL,
    state VARCHAR(16) NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    requested_at DATETIME NOT NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    result_json LONGTEXT NULL,
    INDEX idx_ultra_candidate_reviews_state (state, requested_at),
    INDEX idx_ultra_candidate_reviews_source (source_id, state, requested_at),
    INDEX idx_ultra_candidate_reviews_candidate (candidate_key_hash, requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ultra_observer_devices (
    observer_hash CHAR(64) NOT NULL PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    device_id VARCHAR(191) NOT NULL,
    strikes INT UNSIGNED NOT NULL DEFAULT 0,
    demoted_at DATETIME NULL,
    last_rebuttal_at DATETIME NULL,
    last_seen_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_ultra_observer_devices_client (client_id, last_seen_at),
    INDEX idx_ultra_observer_devices_demoted (demoted_at, strikes)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Backfill the observer mapping for devices that reported before this table
-- existed. The reputation events only store the hash, but the session table
-- still carries the pair it is derived from (sha256("client_id:device_id")),
-- so existing devices become mappable immediately instead of after their next
-- upload. Without this a demoted device could not be named in the console.
INSERT INTO ultra_observer_devices (observer_hash, client_id, device_id, strikes, last_seen_at, created_at)
SELECT LOWER(SHA2(CONCAT(client_id, ':', device_id), 256)) AS observer_hash,
       client_id,
       SUBSTRING(device_id, 1, 191) AS device_id,
       0,
       UTC_TIMESTAMP(),
       UTC_TIMESTAMP()
  FROM client_device_sessions
 WHERE device_id IS NOT NULL
   AND device_id <> ''
   AND (last_seen_at IS NULL OR last_seen_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY))
ON DUPLICATE KEY UPDATE last_seen_at = VALUES(last_seen_at);