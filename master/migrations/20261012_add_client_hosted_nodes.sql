-- 2026-10-12 Hosted user nodes (bring-your-own Shadowsocks / SOCKS5 / VLESS
-- nodes stored by the panel for a client) and their forward pairings.
--
-- Keyed by clients.id: every trusted device of the client receives the same
-- nodes and the same forward -> node pairing. The node's server, port and
-- credentials live only in sealed_config (AEAD, associated data = client id,
-- see HostedNodeVault); name and protocol are the only clear columns.
-- fingerprint is a keyed HMAC of the connection identity that makes uploads
-- idempotent per client without revealing anything.
--
-- No foreign keys on purpose: production clients/forwards were created by
-- older schema revisions, and a failing FK would fail the migration (and with
-- auto-migrations, every request). HostedNodeService deletes pairings with
-- their node and ignores rows whose client or forward is gone.
CREATE TABLE IF NOT EXISTS client_hosted_nodes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(191) NOT NULL,
    protocol VARCHAR(16) NOT NULL,
    sealed_config TEXT NOT NULL,
    fingerprint CHAR(64) NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    source VARCHAR(16) NOT NULL DEFAULT 'console',
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_client_hosted_node_fingerprint (client_id, fingerprint),
    INDEX idx_client_hosted_nodes_client (client_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS client_hosted_node_pairings (
    client_id BIGINT UNSIGNED NOT NULL,
    forward_id BIGINT UNSIGNED NOT NULL,
    node_id BIGINT UNSIGNED NOT NULL,
    source VARCHAR(16) NOT NULL DEFAULT 'console',
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (client_id, forward_id),
    INDEX idx_client_hosted_node_pairings_node (node_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
