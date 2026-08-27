<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertContains(string $haystack, string $needle, string $label): void
{
    if (!str_contains($haystack, $needle)) {
        fail($label . ': missing ' . $needle);
    }
}

function tableBlock(string $sql, string $table): string
{
    $pattern = '/CREATE TABLE IF NOT EXISTS ' . preg_quote($table, '/') . '\s*\((.*?)\n\)(?:\s+ENGINE=[^;]+)?;/si';
    if (preg_match($pattern, $sql, $match) !== 1) {
        fail('missing table ' . $table);
    }
    return (string) $match[1];
}

$root = dirname(__DIR__);
$schema = file_get_contents($root . '/migrations/schema.sql');
$migration = file_get_contents($root . '/migrations/20260828_add_panel_access_policy.sql');
if (!is_string($schema) || !is_string($migration)) {
    fail('access-policy schema or migration is unreadable');
}

foreach (['fresh schema' => $schema, 'migration' => $migration] as $label => $sql) {
    assertContains($sql, 'geo_lock_enabled TINYINT(1) NOT NULL DEFAULT 0', $label);
    foreach ([
        'access_blacklist_rules',
        'user_geo_lock_profiles',
        'access_location_challenges',
        'access_location_observations',
        'access_policy_events',
        'account_access_bans',
        'access_reset_generations',
        'access_reset_devices',
    ] as $table) {
        tableBlock($sql, $table);
    }

    foreach ([
        'UNIQUE KEY uniq_access_blacklist_entity',
        'UNIQUE KEY uniq_access_location_challenge_token',
        'UNIQUE KEY uniq_access_policy_attempt',
        'UNIQUE KEY uniq_access_reset_device (reset_generation_id, client_id, device_uuid)',
        'blocked_until',
        'block_origin',
        'release_policy',
        'attempt_id',
        'payload_digest',
    ] as $needle) {
        assertContains($sql, $needle, $label);
    }
}

$newTables = [
    'access_blacklist_rules',
    'user_geo_lock_profiles',
    'access_location_challenges',
    'access_location_observations',
    'access_policy_events',
    'account_access_bans',
    'access_reset_generations',
    'access_reset_devices',
];
foreach ($newTables as $table) {
    $block = tableBlock($migration, $table);
    foreach (['agent_token', 'password_hash', 'provider_ip', 'provider_secret'] as $forbidden) {
        if (preg_match('/\b' . preg_quote($forbidden, '/') . '\b/i', $block) === 1) {
            fail($table . ' must not persist credential/provider material: ' . $forbidden);
        }
    }
}

assertContains($migration, 'ALTER TABLE service_plans', 'migration');
assertContains($migration, 'ALTER TABLE client_device_controls', 'migration');
assertContains($schema, 'status VARCHAR(32) NOT NULL DEFAULT \'active\'', 'fresh schema users status');

echo "ok\n";
