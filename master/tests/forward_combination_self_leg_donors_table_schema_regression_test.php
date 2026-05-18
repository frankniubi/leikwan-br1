<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$schema = file_get_contents(__DIR__ . '/../migrations/schema.sql') ?: '';
$migration = '';
foreach ([
    __DIR__ . '/../migrations/20260518_add_forward_combination_donation_and_latency.sql',
    __DIR__ . '/../migrations/20260518_add_forward_combination_slaves.sql',
    __DIR__ . '/../migrations/20260518_add_forward_combination_self_leg_donors.sql',
] as $path) {
    if (is_file($path)) {
        $migration .= file_get_contents($path) ?: '';
    }
}

foreach ([
    'ADD COLUMN desired_latency_ms INT NOT NULL DEFAULT 0',
    'ADD COLUMN allow_public_self_leg_donation TINYINT(1) NOT NULL DEFAULT 0',
    'ADD COLUMN donation_qualify_window_seconds INT NOT NULL DEFAULT 60',
    'ADD COLUMN donation_revoke_window_seconds INT NOT NULL DEFAULT 30',
    'ADD COLUMN donation_min_open_ports INT NOT NULL DEFAULT 1',
    'CREATE TABLE IF NOT EXISTS forward_combination_slaves',
    "selection_kind VARCHAR(32) NOT NULL DEFAULT 'admin_pin'",
    'CONSTRAINT fk_fc_slaves_combo',
    'CONSTRAINT fk_fc_slaves_node',
    'UNIQUE KEY uniq_fc_slave_node',
    'CREATE TABLE IF NOT EXISTS forward_combination_self_leg_donors',
    'self_leg_donor_identity_table',
    'client_id BIGINT UNSIGNED NOT NULL',
    'desktop_client_session_id BIGINT UNSIGNED NULL',
    'donor_public_host VARCHAR(255) NULL',
    'last_self_check_json JSON NULL',
    'last_reverse_probe_json JSON NULL',
    'CONSTRAINT fk_fc_self_leg_donors_combo',
    'CONSTRAINT fk_fc_self_leg_donors_client',
    'UNIQUE KEY uniq_fc_self_leg_donor_session',
] as $snippet) {
    if (!str_contains($schema, $snippet) && !str_contains($migration, $snippet)) {
        fail('missing snippet: ' . $snippet);
    }
}

echo "ok
";
