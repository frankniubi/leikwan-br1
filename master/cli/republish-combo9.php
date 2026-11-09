<?php
declare(strict_types=1);
$baseDir = '/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master';
spl_autoload_register(static function (string $class) use ($baseDir): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = $baseDir . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});
require_once $baseDir . '/src/Bootstrap.php';
use Leikwan\Master\Bootstrap;
$app = new Bootstrap($baseDir . '/.env');
$prov = $app->provisioning();
echo "Republishing active forwards for combination 9...\n";
$prov->republishActiveForwardsForCombination(9);
echo "Done.\n";
