<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertTrue(bool $value, string $message): void
{
    if (!$value) {
        fail($message);
    }
}

$path = __DIR__ . '/../migrations/20260827_add_android_device_control.sql';
if (!is_file($path)) {
    fail('Android device-control migration is missing');
}

$sql = (string) file_get_contents($path);
foreach ([
    'client_device_controls',
    'android_device_sessions',
    'android_device_challenges',
    'android_enrollment_codes',
    'client_device_control_audits',
    'command_version',
    'identity_key_hash',
    'session_hash',
    'nonce_hash',
    'code_hash',
] as $required) {
    assertTrue(str_contains($sql, $required), "expected migration to contain {$required}");
}

assertTrue(
    preg_match('/state\\s+ENUM\\s*\\(\\s*\'active\'\\s*,\\s*\'kicked\'\\s*,\\s*\'banned\'\\s*\\)/i', $sql) === 1,
    'expected active, kicked, and banned device states'
);
assertTrue(
    preg_match('/UNIQUE KEY\\s+uniq_client_device_control\\s*\\(\\s*client_id\\s*,\\s*device_id\\s*\\)/i', $sql) === 1,
    'expected a unique client/device control row'
);
assertTrue(
    preg_match('/\\b(provider_ip|credential|password|agent_token)\\b/i', $sql) !== 1,
    'migration must not persist provider material or agent credentials'
);

echo "ok\n";
