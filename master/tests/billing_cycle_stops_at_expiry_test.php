<?php
declare(strict_types=1);

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Support\ProvisioningService;

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

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        fail($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$envPath = tempnam(sys_get_temp_dir(), 'lw-billing-cycle-');
file_put_contents($envPath, "DB_DRIVER=sqlite\nDB_NAME=:memory:\n");
$config = new Config($envPath);
$db = new Database($config);
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->setPdo($pdo);
$pdo->exec('CREATE TABLE services (id INTEGER PRIMARY KEY, user_id INTEGER, plan_id INTEGER, status TEXT, suspend_reason TEXT,
    expire_at TEXT, created_at TEXT, updated_at TEXT, billing_mode TEXT, billing_currency TEXT, monthly_base_fee TEXT,
    monthly_included_traffic_gb INTEGER, traffic_price_per_gb TEXT, traffic_limit_gb INTEGER, bandwidth_limit_mbps INTEGER,
    billing_cycle_started_at TEXT, billing_cycle_traffic_used_bytes INTEGER)');
$pdo->exec('CREATE TABLE forwards (id INTEGER PRIMARY KEY, service_id INTEGER, enabled INTEGER, line_client_id INTEGER)');
$pdo->exec('CREATE TABLE service_billing_cycles (id INTEGER PRIMARY KEY AUTOINCREMENT, service_id INTEGER, cycle_started_at TEXT,
    cycle_ended_at TEXT, billing_mode TEXT, billing_currency TEXT, monthly_base_fee TEXT, monthly_included_traffic_gb INTEGER,
    traffic_price_per_gb TEXT, usage_bytes INTEGER, billable_bytes INTEGER, estimated_total_amount TEXT, created_at TEXT)');

$utc = new DateTimeZone('UTC');
$now = new DateTimeImmutable('now', $utc);
$fmt = static fn(DateTimeImmutable $d): string => $d->format('Y-m-d H:i:s');

$insert = static function (int $id, string $status, DateTimeImmutable $cycleStart, DateTimeImmutable $expireAt, int $usedBytes) use ($pdo, $fmt): void {
    $stmt = $pdo->prepare("INSERT INTO services VALUES (:id, 1, NULL, :status, :reason, :expire, :start, :start, 'monthly_bundle', 'CNY', '0.00',
        100, '0.0000', 100, 100, :start, :used)");
    $stmt->execute([
        'id' => $id,
        'status' => $status,
        'reason' => $status === 'traffic_suspended' ? 'traffic_limit' : null,
        'expire' => $fmt($expireAt),
        'start' => $fmt($cycleStart),
        'used' => $usedBytes,
    ]);
};
$service = static fn(int $id): array => $pdo->query('SELECT * FROM services WHERE id = ' . $id)->fetch();
$historyRows = static fn(int $id): int => (int) $pdo->query('SELECT COUNT(*) FROM service_billing_cycles WHERE service_id = ' . $id)->fetchColumn();

$provisioning = new ProvisioningService($config, $db);

// Expired monthly service: its paid cycle ended at expire_at, so it must not open an unpaid one.
$expiredStart = $now->modify('-40 days');
$insert(1, 'active', $expiredStart, $expiredStart->modify('+1 month'), 5000);
$provisioning->serviceBillingOverview(1);
assertSame($fmt($expiredStart), $service(1)['billing_cycle_started_at'], 'expired service keeps its last paid cycle');
assertSame(5000, (int) $service(1)['billing_cycle_traffic_used_bytes'], 'expired service keeps its cycle usage');
assertSame(0, $historyRows(1), 'expired service records no extra cycle');

// Long paid term (yearly plan): traffic still resets every month inside the term.
$yearlyStart = $now->modify('-75 days');
$insert(2, 'active', $yearlyStart, $now->modify('+300 days'), 7000);
$provisioning->serviceBillingOverview(2);
assertSame($fmt($yearlyStart->modify('+2 months')), $service(2)['billing_cycle_started_at'], 'cycles inside the paid term still roll monthly');
assertSame(0, (int) $service(2)['billing_cycle_traffic_used_bytes'], 'monthly reset clears cycle usage');
assertSame(2, $historyRows(2), 'each closed cycle is recorded once');

// Renewal moves expire_at: the next sync catches up to the cycle the renewal paid for.
$pdo->exec("UPDATE services SET expire_at = '" . $fmt($now->modify('+20 days')) . "' WHERE id = 1");
$provisioning->serviceBillingOverview(1);
assertSame($fmt($expiredStart->modify('+1 month')), $service(1)['billing_cycle_started_at'], 'renewed service rolls into the renewed cycle');
assertSame(1, $historyRows(1), 'renewed service records the cycle it closed');

// An expired service out of traffic must not be resumed by a cycle roll.
$suspendedStart = $now->modify('-40 days');
$insert(3, 'traffic_suspended', $suspendedStart, $suspendedStart->modify('+1 month'), 9000);
$provisioning->serviceBillingOverview(3);
assertSame('traffic_suspended', $service(3)['status'], 'expired out-of-traffic service stays suspended');

echo "ok\n";
