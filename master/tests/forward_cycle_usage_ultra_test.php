<?php
declare(strict_types=1);

/**
 * Per-line (per-forward) cycle usage must include Ultra forwards. They hold no
 * service_endpoints, so their bytes live only in ultra_usage_events; without
 * merging those the tenant forward list showed 0 for every Ultra line.
 */

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Support\ProvisioningService;

function failUltraUsage(string $message): never
{
    fwrite(STDERR, 'forward cycle usage: ' . $message . PHP_EOL);
    exit(1);
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    failUltraUsage('pdo_sqlite is required');
}

$root = realpath(__DIR__ . '/..');
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'Leikwan' . chr(92) . 'Master' . chr(92);
    if (str_starts_with($class, $prefix)) {
        $path = $root . '/src/' . str_replace(chr(92), '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lk-ultra-usage-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700, true);
$envPath = $tmp . '/.env';
file_put_contents($envPath, "DB_DRIVER=sqlite\nDB_NAME={$tmp}/db.sqlite\n");

try {
    $config = new Config($envPath);
    $db = new Database($config);
    $pdo = $db->pdo();
    $pdo->exec('CREATE TABLE forwards (id INTEGER PRIMARY KEY, service_id INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE service_endpoints (id INTEGER PRIMARY KEY, forward_id INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE traffic_usage_hourly (id INTEGER PRIMARY KEY, service_id INTEGER, endpoint_id INTEGER, period_start TEXT, bytes_in INTEGER, bytes_out INTEGER)');
    $pdo->exec('CREATE TABLE ultra_usage_events (id INTEGER PRIMARY KEY, forward_id INTEGER, bytes_in INTEGER, bytes_out INTEGER, created_at TEXT)');

    // service 7: forward 1 = slave line, forward 2 = Ultra line; service 8 = someone else.
    $pdo->exec('INSERT INTO forwards (id, service_id) VALUES (1, 7), (2, 7), (3, 8)');
    $pdo->exec('INSERT INTO service_endpoints (id, forward_id) VALUES (10, 1)');
    $pdo->exec("INSERT INTO traffic_usage_hourly (service_id, endpoint_id, period_start, bytes_in, bytes_out) VALUES
        (7, 10, '2026-11-05 10:00:00', 100, 50),
        (7, 10, '2026-10-01 10:00:00', 999, 999)");
    $pdo->exec("INSERT INTO ultra_usage_events (forward_id, bytes_in, bytes_out, created_at) VALUES
        (2, 300, 200, '2026-11-05 11:00:00'),
        (2, 40, 60, '2026-11-09 08:30:00'),
        (2, 7777, 7777, '2026-10-20 00:00:00'),
        (3, 5000, 5000, '2026-11-05 11:00:00')");

    $provisioning = (new ReflectionClass(ProvisioningService::class))->newInstanceWithoutConstructor();
    $dbProp = (new ReflectionClass(ProvisioningService::class))->getProperty('db');
    $dbProp->setValue($provisioning, $db);

    $map = $provisioning->forwardCycleUsageMap(7, '2026-11-01 00:00:00');
    if (($map[1] ?? null) !== 150) {
        failUltraUsage('slave line usage must stay 150, got ' . var_export($map[1] ?? null, true));
    }
    if (($map[2] ?? null) !== 600) {
        failUltraUsage('Ultra line usage must be 600 (this cycle only), got ' . var_export($map[2] ?? null, true));
    }
    if (array_key_exists(3, $map)) {
        failUltraUsage('another service\'s Ultra usage must not leak in');
    }
} finally {
    $pdo = null;
    $db = null;
    foreach (glob($tmp . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($tmp);
}

echo "forward_cycle_usage_ultra_test: OK\n";
