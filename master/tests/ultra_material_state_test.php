<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', (string) $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use Leikwan\Master\Support\UltraInventoryService;

function failUltraMaterialState(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertUltraMaterialState(string $expected, ?string $expiresAt, int $now): void
{
    $actual = UltraInventoryService::materialState($expiresAt, $now);
    if ($actual !== $expected) {
        failUltraMaterialState(sprintf(
            'expected %s for %s, got %s',
            $expected,
            var_export($expiresAt, true),
            $actual,
        ));
    }
}

$now = 1_900_000_000;
assertUltraMaterialState('not_acquired', null, $now);
assertUltraMaterialState('not_acquired', '', $now);
assertUltraMaterialState('expired', gmdate('Y-m-d H:i:s', $now - 1), $now);
assertUltraMaterialState('expiring', gmdate('Y-m-d H:i:s', $now + 300), $now);
assertUltraMaterialState('ready', gmdate('Y-m-d H:i:s', $now + 901), $now);
assertUltraMaterialState('expired', 'not-a-timestamp', $now);
if (UltraInventoryService::materialState(gmdate('Y-m-d H:i:s', $now - 1), $now, 600, false) !== 'not_required') {
    failUltraMaterialState('unused material must be reported as not required instead of expired');
}
if (UltraInventoryService::materialStateLabel('not_required') !== 'not required') {
    failUltraMaterialState('unused material must have an explicit not-required label');
}

$admin = (string) file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');
if (!str_contains($admin, 'UltraInventoryService::materialState')) {
    failUltraMaterialState('admin page must use the shared material state classifier');
}
if (!str_contains($admin, 'UltraInventoryService::materialStateLabel')) {
    failUltraMaterialState('admin page must render the shared material state label');
}
$inventory = (string) file_get_contents(__DIR__ . '/../src/Support/UltraInventoryService.php');
if (UltraInventoryService::materialStateLabel('not_acquired') !== 'not acquired') {
    failUltraMaterialState('shared material state label must distinguish material that was never acquired');
}
if (!str_contains($inventory, "'not_acquired' => max(0")) {
    failUltraMaterialState('summary must expose the not-acquired count');
}
if (!str_contains($inventory, "'expired' => max(0")) {
    failUltraMaterialState('summary must expose the expired count');
}
if (!str_contains($inventory, "'not_required' => max(0")) {
    failUltraMaterialState('summary must expose the not-required count');
}
if (!str_contains($inventory, 'material_required')) {
    failUltraMaterialState('line inventory must expose whether material renewal is required');
}
if (!str_contains($admin, 'Material not acquired') || !str_contains($admin, 'Material expired')) {
    failUltraMaterialState('admin summary must explain missing and expired material separately');
}
if (!str_contains($admin, 'Material not required')) {
    failUltraMaterialState('admin summary must distinguish unused lines from renewal failures');
}

echo "ok\n";
