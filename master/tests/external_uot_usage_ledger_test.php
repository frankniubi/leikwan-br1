<?php
declare(strict_types=1);

/**
 * Plan C7: sidecar usage ingest (cumulative counters -> per-day deltas) and the
 * reconcile that bills max(client-reported, sidecar-measured) per forward and
 * finished UTC day. Runs on in-memory SQLite (needs pdo_sqlite).
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (str_starts_with($class, $prefix)) {
        require_once __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Support\ExternalUotUsageLedger;
use Leikwan\Master\Support\ProvisioningService;

$failures = 0;
function checkLedger(bool $ok, string $label): void
{
    global $failures;
    if (!$ok) {
        $failures++;
        fwrite(STDERR, "FAIL $label\n");
    }
}

const MIB = 1048576;
const GIB = 1073741824;

$config = new Config('/nonexistent');
$db = new Database($config);
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->setPdo($pdo);
$cycleStart = gmdate('Y-m-d 00:00:00');
$pdo->exec("CREATE TABLE forwards (id INTEGER PRIMARY KEY, service_id INTEGER, path_prefix TEXT);
CREATE TABLE services (id INTEGER PRIMARY KEY, status TEXT, suspend_reason TEXT, traffic_limit_gb INTEGER,
 monthly_included_traffic_gb INTEGER, traffic_used_bytes INTEGER, billing_cycle_traffic_used_bytes INTEGER,
 billing_mode TEXT, expire_at TEXT, billing_cycle_started_at TEXT, created_at TEXT, updated_at TEXT);
CREATE TABLE service_endpoints (id INTEGER PRIMARY KEY, forward_id INTEGER, node_id INTEGER, public_port INTEGER, health_status TEXT);
CREATE TABLE ultra_usage_events (id INTEGER PRIMARY KEY, client_id INTEGER, device_hash TEXT, forward_id INTEGER,
 policy_generation TEXT, sequence_no INTEGER, bytes_in INTEGER, bytes_out INTEGER, created_at TEXT);
CREATE TABLE external_uot_usage_counters (source TEXT NOT NULL, instance_id TEXT NOT NULL, forward_id INTEGER NOT NULL,
 to_target_bytes INTEGER NOT NULL DEFAULT 0, from_target_bytes INTEGER NOT NULL DEFAULT 0, updated_at TEXT NOT NULL,
 PRIMARY KEY (source, instance_id, forward_id));
CREATE TABLE external_uot_usage_daily (forward_id INTEGER NOT NULL, usage_date TEXT NOT NULL, service_id INTEGER NULL,
 sidecar_bytes INTEGER NOT NULL DEFAULT 0, client_bytes INTEGER NOT NULL DEFAULT 0, billed_extra_bytes INTEGER NOT NULL DEFAULT 0,
 reconciled_at TEXT NULL, updated_at TEXT NOT NULL, PRIMARY KEY (forward_id, usage_date));
INSERT INTO forwards VALUES (170, 30, ''), (171, 31, ''), (172, 32, ''), (173, 33, '');
INSERT INTO services VALUES
 (30, 'active', NULL, 0, 0, 0, 0, 'metered', NULL, '$cycleStart', '$cycleStart', '$cycleStart'),
 (31, 'active', NULL, 0, 0, 0, 0, 'metered', NULL, '$cycleStart', '$cycleStart', '$cycleStart'),
 (32, 'active', NULL, 1, 0, 0, " . (GIB - 10 * MIB) . ", 'monthly_bundle', NULL, '$cycleStart', '$cycleStart', '$cycleStart'),
 (33, 'active', NULL, 1, 0, 0, " . (GIB - 10 * MIB) . ", 'metered', NULL, '$cycleStart', '$cycleStart', '$cycleStart');");

$ledger = new ExternalUotUsageLedger($db, new ProvisioningService($config, $db));
$instanceA = str_repeat('a', 32);
$instanceB = str_repeat('b', 32);
$day = strtotime('2026-10-19 12:00:00 UTC');
$daily = static function (int $forwardId, string $date) use ($pdo): ?array {
    $statement = $pdo->prepare('SELECT * FROM external_uot_usage_daily WHERE forward_id = ? AND usage_date = ?');
    $statement->execute([$forwardId, $date]);
    $row = $statement->fetch();
    return $row === false ? null : $row;
};
$report = static fn (string $instance, array $forwards): array => ['version' => 1, 'instance' => $instance, 'forwards' => $forwards];
$counters = static fn (int $to, int $from): array => ['to_target_bytes' => $to, 'from_target_bytes' => $from,
    'to_target_packets' => 1, 'from_target_packets' => 1, 'rate_limited_drops' => 0];

// --- ingest ---------------------------------------------------------------
$summary = $ledger->ingest('pool:la1', $report($instanceA, ['170' => $counters(100, 200)]), $day);
checkLedger($summary['bytes'] === 300 && (int) $daily(170, '2026-10-19')['sidecar_bytes'] === 300, 'first report counts in full');
checkLedger((int) $daily(170, '2026-10-19')['service_id'] === 30, 'daily row carries the forward service');
$ledger->ingest('pool:la1', $report($instanceA, ['170' => $counters(100, 200)]), $day + 60);
checkLedger((int) $daily(170, '2026-10-19')['sidecar_bytes'] === 300, 'a repeated report adds nothing');
$ledger->ingest('pool:la1', $report($instanceA, ['170' => $counters(150, 250)]), $day + 120);
checkLedger((int) $daily(170, '2026-10-19')['sidecar_bytes'] === 400, 'same instance adds the difference');
$ledger->ingest('pool:la1', $report($instanceA, ['170' => $counters(10, 250)]), $day + 180);
checkLedger((int) $daily(170, '2026-10-19')['sidecar_bytes'] === 410, 'a counter that went backwards counts its whole value');
$ledger->ingest('pool:la1', $report($instanceB, ['170' => $counters(50, 50)]), $day + 240);
checkLedger((int) $daily(170, '2026-10-19')['sidecar_bytes'] === 510, 'a new instance counts in full');
checkLedger((int) $pdo->query("SELECT COUNT(*) FROM external_uot_usage_counters WHERE instance_id = '$instanceA'")->fetchColumn() === 0,
    'superseded instance counters are dropped');
$ledger->ingest('standalone:203.0.113.5', $report($instanceA, ['170' => $counters(5, 5)]), $day + 300);
checkLedger((int) $daily(170, '2026-10-19')['sidecar_bytes'] === 520, 'sources are metered independently');
$ledger->ingest('pool:la1', $report($instanceB, ['170' => $counters(80, 70)]), $day + 86400);
checkLedger((int) $daily(170, '2026-10-20')['sidecar_bytes'] === 50 && (int) $daily(170, '2026-10-19')['sidecar_bytes'] === 520,
    'deltas land on the UTC day they were seen');

$summary = $ledger->ingest('pool:la1', $report($instanceB, ['999' => $counters(10, 10)]), $day);
checkLedger($summary['ignored'] === 1 && $daily(999, '2026-10-19') === null, 'unknown forward ignored');
$summary = $ledger->ingest('pool:la1', $report($instanceB, ['171' => $counters(11 * 1024 * GIB, 0)]), $day);
checkLedger($summary['rejected'] === 1 && $daily(171, '2026-10-19') === null
    && (int) $pdo->query('SELECT COUNT(*) FROM external_uot_usage_counters WHERE forward_id = 171')->fetchColumn() === 0,
    'absurd delta rejected without touching the counters');
foreach ([
    'bad instance' => $report('not-hex', ['171' => $counters(1, 1)]),
    'forwards not a map' => ['instance' => $instanceB, 'forwards' => 'x'],
    'negative counter' => $report($instanceB, ['171' => $counters(-1, 1)]),
    'float counter' => $report($instanceB, ['171' => ['to_target_bytes' => 1.5, 'from_target_bytes' => 1]]),
    'empty' => [],
] as $label => $document) {
    $ledger->ingest('pool:la1', $document, $day);
    checkLedger($daily(171, '2026-10-19') === null, "malformed report ignored: $label");
}
checkLedger($ledger->ingest(str_repeat('x', 192), $report($instanceB, ['171' => $counters(1, 1)]), $day)['forwards'] === 0, 'oversized source ignored');
checkLedger((int) $pdo->query('SELECT SUM(traffic_used_bytes) FROM services')->fetchColumn() === 0, 'ingest never touches service counters');

// --- reconcile ------------------------------------------------------------
$pdo->exec('DELETE FROM external_uot_usage_daily');
$insertDaily = $pdo->prepare("INSERT INTO external_uot_usage_daily VALUES (?, ?, ?, ?, 0, 0, NULL, '2026-10-19 23:00:00')");
$insertDaily->execute([170, '2026-10-19', 30, 100 * MIB]);
$insertDaily->execute([171, '2026-10-19', 31, (int) (1.5 * MIB)]);
$insertDaily->execute([172, '2026-10-19', 32, 100 * MIB]);
$insertDaily->execute([173, '2026-10-19', 33, 100 * MIB]);
$insertDaily->execute([170, '2026-10-20', 30, 100 * MIB]);
$event = $pdo->prepare('INSERT INTO ultra_usage_events (client_id, device_hash, forward_id, policy_generation, sequence_no, bytes_in, bytes_out, created_at) VALUES (3, ?, ?, ?, ?, ?, ?, ?)');
$event->execute(['d', 170, 'g', 1, 30 * MIB, 20 * MIB, '2026-10-19 00:00:00']);
$event->execute(['d', 170, 'g', 2, 500 * MIB, 0, '2026-10-18 23:59:59']);
$event->execute(['d', 170, 'g', 3, 500 * MIB, 0, '2026-10-20 00:00:00']);
$event->execute(['d', 171, 'g', 4, MIB, 0, '2026-10-19 08:00:00']);

checkLedger(ExternalUotUsageLedger::extraBytes(100 * MIB, 50 * MIB) === 48 * MIB, 'tolerance is 2 % of the sidecar bytes');
checkLedger(ExternalUotUsageLedger::extraBytes(10 * MIB, 8 * MIB) === MIB, 'tolerance is at least 1 MiB');
checkLedger(ExternalUotUsageLedger::extraBytes(10 * MIB, 20 * MIB) === 0, 'client over-reporting never yields negative extra');

$beforeGrace = strtotime('2026-10-21 01:59:59 UTC');
$dry = $ledger->reconcile(null, true, $beforeGrace);
checkLedger(array_map(static fn (array $row): string => $row['forward_id'] . '@' . $row['usage_date'], $dry)
    === ['170@2026-10-19', '171@2026-10-19', '172@2026-10-19', '173@2026-10-19'], 'only days that ended 2 h ago are due');
checkLedger($dry[0]['client_bytes'] === 50 * MIB && $dry[0]['extra_bytes'] === 48 * MIB, 'client bytes summed over the UTC day only');
checkLedger($daily(170, '2026-10-19')['reconciled_at'] === null && (int) $pdo->query('SELECT traffic_used_bytes FROM services WHERE id = 30')->fetchColumn() === 0,
    'dry run writes nothing');
checkLedger($ledger->reconcile('2026-10-20', true, $beforeGrace) === [], '--date inside the grace window is refused');

$results = $ledger->reconcile(null, false, $beforeGrace);
$byForward = [];
foreach ($results as $row) {
    $byForward[$row['forward_id']] = $row;
}
$service = static fn (int $id): array => $pdo->query("SELECT * FROM services WHERE id = $id")->fetch();
checkLedger((int) $service(30)['traffic_used_bytes'] === 48 * MIB && (int) $service(30)['billing_cycle_traffic_used_bytes'] === 48 * MIB,
    'extra billed to traffic_used_bytes and billing_cycle_traffic_used_bytes');
checkLedger((int) $daily(170, '2026-10-19')['billed_extra_bytes'] === 48 * MIB && (int) $daily(170, '2026-10-19')['client_bytes'] === 50 * MIB
    && $daily(170, '2026-10-19')['reconciled_at'] !== null, 'daily row records the reconcile');
checkLedger($byForward[171]['extra_bytes'] === 0 && (int) $service(31)['traffic_used_bytes'] === 0
    && $daily(171, '2026-10-19')['reconciled_at'] !== null, 'within tolerance: reconciled, nothing billed');
checkLedger($byForward[172]['suspended'] === true && $service(32)['status'] === 'traffic_suspended'
    && $service(32)['suspend_reason'] === 'traffic_limit', 'quota exhausted by the extra suspends the service like recordUltraUsage');
checkLedger($byForward[173]['suspended'] === false && $service(33)['status'] === 'active', 'metered service is billed, never suspended');
checkLedger($daily(170, '2026-10-20')['reconciled_at'] === null, 'yesterday waits for the grace window');

checkLedger($ledger->reconcile(null, false, $beforeGrace) === [], 'rerun bills nothing');
checkLedger((int) $service(30)['traffic_used_bytes'] === 48 * MIB, 'rerun leaves the service counters alone');

$afterGrace = strtotime('2026-10-21 02:00:00 UTC');
$late = $ledger->reconcile('2026-10-20', false, $afterGrace);
checkLedger(count($late) === 1 && $late[0]['client_bytes'] === 500 * MIB && $late[0]['extra_bytes'] === 0,
    '--date reconciles one day once its grace has passed');

if ($failures > 0) {
    fwrite(STDERR, "external_uot_usage_ledger_test: $failures failed\n");
    exit(1);
}
echo "external_uot_usage_ledger_test: ok\n";
