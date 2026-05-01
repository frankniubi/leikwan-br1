<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$base = file_get_contents(__DIR__ . '/../src/Controllers/BaseController.php');
$admin = file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');
$tenant = file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');

if (!is_string($base) || $base === '') {
    fail('failed to read BaseController source');
}
if (!is_string($admin) || $admin === '') {
    fail('failed to read AdminController source');
}
if (!is_string($tenant) || $tenant === '') {
    fail('failed to read TenantController source');
}

if (!str_contains($base, 'protected function displayThrowableMessage(\Throwable $throwable): string')) {
    fail('base controller is missing protected displayThrowableMessage helper');
}

foreach ([
    'private function displayThrowableMessage(\Throwable $throwable): string',
] as $forbidden) {
    if (str_contains($admin, $forbidden)) {
        fail('admin controller still defines a private displayThrowableMessage override');
    }
    if (str_contains($tenant, $forbidden)) {
        fail('tenant controller still defines a private displayThrowableMessage override');
    }
}

echo "ok\n";
