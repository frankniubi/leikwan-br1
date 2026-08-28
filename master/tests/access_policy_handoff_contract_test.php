<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function requireText(string $source, string $needle, string $label): void
{
    if (!str_contains($source, $needle)) {
        fail($label . ' missing: ' . $needle);
    }
}

$handoffPath = __DIR__ . '/../../docs/handoffs/2026-08-28-panel-access-policy-distribution-handoff.md';
$fixturePath = __DIR__ . '/fixtures/access-policy-v1.json';
$handoff = is_file($handoffPath) ? (string) file_get_contents($handoffPath) : '';
$fixtureJson = is_file($fixturePath) ? (string) file_get_contents($fixturePath) : '';
if ($handoff === '' || $fixtureJson === '') {
    fail('handoff or fixture is missing');
}
$fixture = json_decode($fixtureJson, true);
if (!is_array($fixture)) {
    fail('handoff fixture is not valid JSON');
}

foreach ([
    'protocol_version: 1', 'Android', 'Windows', 'macOS', 'iOS',
    'effective_path', 'physical_no_proxy', 'attempt_id', 'five minutes',
    '30 minutes', 'jitter', '24 hours', 'three', 'account BAN',
    'daily', 'Kick-all', 'manual_tenant', 'manual_admin', 'policy', 'province_reset',
    'Panel cannot bypass a remote client proxy',
    'access_blacklisted', 'direct_location_check_required', 'location_check_unavailable',
    'geo_lock_violation', 'device_temporarily_banned', 'account_banned',
] as $needle) {
    requireText($handoff, $needle, 'handoff');
}

foreach ([
    'protocol_version', 'platforms', 'challenge', 'effective_foreign', 'physical_direct',
    'allowed', 'violation', 'temporary_ban', 'account_ban', 'reset',
] as $key) {
    if (!array_key_exists($key, $fixture)) {
        fail('fixture missing scenario: ' . $key);
    }
}
if (($fixture['protocol_version'] ?? null) !== 1) {
    fail('fixture protocol version must be 1');
}
foreach (['android', 'windows', 'macos', 'ios'] as $platform) {
    if (!in_array($platform, $fixture['platforms'] ?? [], true)) {
        fail('fixture missing platform: ' . $platform);
    }
}

echo "ok\n";
