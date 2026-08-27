<?php
declare(strict_types=1);

function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }
function assertContains(string $source, string $needle, string $message): void { if (!str_contains($source, $needle)) fail($message . ': ' . $needle); }

$admin = (string) file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');
$provisioning = (string) file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');
$schema = (string) file_get_contents(__DIR__ . '/../migrations/schema.sql');

assertContains($admin, 'name="geo_lock_enabled"', 'plan form must expose geo lock switch');
assertContains($admin, 'geo_lock_enabled', 'admin plan controller must pass geo lock field');
assertContains($provisioning, 'geo_lock_enabled', 'provisioning savePlan must persist geo lock field');
assertContains($provisioning, 'geo_lock_enabled = :geo_lock_enabled', 'plan UPDATE must persist geo lock field');
assertContains($schema, 'geo_lock_enabled TINYINT(1) NOT NULL DEFAULT 0', 'schema geo lock default');

echo "ok\n";
