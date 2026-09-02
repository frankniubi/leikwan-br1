<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use Leikwan\Master\Support\UltraInventoryService;

function failDirection(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

foreach (['intl_to_cn', 'cn_to_intl', 'domestic', 'unknown'] as $value) {
    if (!in_array($value, UltraInventoryService::DIRECTION_CLASSES, true)) {
        failDirection('direction class is missing: ' . $value);
    }
}

$migration = (string) file_get_contents(__DIR__ . '/../migrations/20260901_add_native_socks5_state_and_plan_flag.sql');
if (!preg_match("/direction_class\\s+VARCHAR\\(32\\)\\s+NOT NULL\\s+DEFAULT\\s+'unknown'/i", $migration)) {
    failDirection('migration does not add direction_class with a fail-closed default');
}
if (!str_contains($migration, "'intl_to_cn'")) {
    failDirection('migration does not constrain the international-to-mainland marker');
}

$schema = (string) file_get_contents(__DIR__ . '/../migrations/schema.sql');
if (!preg_match("/direction_class\\s+VARCHAR\\(32\\)\\s+NOT NULL\\s+DEFAULT\\s+'unknown'/i", $schema)) {
    failDirection('schema does not document direction_class');
}

$inventory = (string) file_get_contents(__DIR__ . '/../src/Support/UltraInventoryService.php');
foreach (['DIRECTION_CLASSES', "'direction_class' => self::DIRECTION_CLASSES", 'direction_class = :direction_class'] as $needle) {
    if (!str_contains($inventory, $needle)) {
        failDirection('inventory policy boundary is missing: ' . $needle);
    }
}

$admin = (string) file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');
foreach (['direction_class', 'International -> Mainland China', 'Class / UDP / SOCKS5 / Direction'] as $needle) {
    if (!str_contains($admin, $needle)) {
        failDirection('admin direction marker surface is missing: ' . $needle);
    }
}

echo "ok\n";
