<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Controllers/HealthController.php');
if (!is_string($source) || $source === '') {
    fail('failed to read HealthController source');
}

if (str_contains($source, '/admin?token=MASTER_ADMIN_TOKEN')) {
    fail('health landing still advertises the removed /admin?token access path');
}

if (!str_contains($source, '/login')) {
    fail('health landing should point operators to /login for admin access');
}

echo "ok\n";
