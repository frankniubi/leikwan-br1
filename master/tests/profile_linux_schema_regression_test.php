<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$schema = file_get_contents(__DIR__ . '/../migrations/schema.sql');
$migration = file_get_contents(__DIR__ . '/../migrations/20260622_add_client_profile_linux_fields.sql');

foreach ([
    [$schema, 'server_hostname VARCHAR(255) NULL'],
    [$schema, 'server_public_ip VARCHAR(64) NULL'],
    [$schema, "server_ip_state ENUM('fresh', 'stale', 'missing') NOT NULL DEFAULT 'missing'"],
    [$schema, 'server_ip_last_synced_at DATETIME NULL'],
    [$schema, 'allow_paid_lines TINYINT(1) NOT NULL DEFAULT 0'],
    [$schema, 'selected_carriers_json JSON NULL'],
    [$schema, "runtime_platform_scope VARCHAR(32) NOT NULL DEFAULT 'all'"],
    [$migration, 'ALTER TABLE services ADD COLUMN server_hostname VARCHAR(255) NULL'],
    [$migration, 'ALTER TABLE clients ADD COLUMN allow_paid_lines TINYINT(1) NOT NULL DEFAULT 0'],
    [$migration, "ALTER TABLE forwards ADD COLUMN target_source VARCHAR(32) NOT NULL DEFAULT 'manual'"],
    [$migration, 'ALTER TABLE forwards ADD COLUMN target_service_id BIGINT UNSIGNED NULL'],
] as [$haystack, $needle]) {
    if (!is_string($haystack) || !str_contains($haystack, $needle)) {
        fail('missing profile-linux schema snippet: ' . $needle);
    }
}

echo "ok\n";
