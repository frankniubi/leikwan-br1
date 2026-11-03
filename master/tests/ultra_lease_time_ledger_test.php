<?php
declare(strict_types=1);

/**
 * Server-side Ultra connected time (ultra_lease_time / UltraLeaseTimeLedger).
 *
 * Pins: the migration and schema.sql agree; a lease adds its window once;
 * renewals and candidate leases are unioned, not summed; gaps are not billed;
 * windows crossing UTC midnight split across day rows; issueLease records the
 * time (with service attribution) only for an issued lease, never for a
 * refusal; a broken ledger never changes the lease outcome.
 *
 * Needs pdo_sqlite:
 *   php -d extension=pdo_sqlite master/tests/ultra_lease_time_ledger_test.php
 */

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

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Support\UltraLeaseService;
use Leikwan\Master\Support\UltraLeaseTimeLedger;
use Leikwan\Master\Support\UltraMode;

if (!class_exists(PDO::class) || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "ultra_lease_time_ledger_test: needs the pdo_sqlite extension\n");
    exit(1);
}

$failures = 0;
function check(bool $condition, string $label): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL $label\n");
    }
}

// 1. Migration and schema.sql carry the same table definition.
$migration = (string) file_get_contents(__DIR__ . '/../migrations/20261003_add_ultra_lease_time.sql');
$schema = (string) file_get_contents(__DIR__ . '/../migrations/schema.sql');
$extract = static function (string $sql): string {
    return preg_match('/CREATE TABLE IF NOT EXISTS ultra_lease_time \((.*?)\) ENGINE=InnoDB/s', $sql, $m) === 1
        ? trim((string) preg_replace('/\s+/', ' ', $m[1]))
        : '';
};
check($extract($migration) !== '', 'migration creates ultra_lease_time');
check($extract($migration) === $extract($schema), 'schema.sql block matches the migration');
foreach ([
    'client_id BIGINT UNSIGNED NOT NULL',
    'service_id BIGINT UNSIGNED NULL',
    'device_hash CHAR(64) NOT NULL',
    'forward_id BIGINT UNSIGNED NOT NULL',
    'UNIQUE KEY uniq_ultra_lease_time_day (client_id, device_hash, forward_id, period_day)',
    'INDEX idx_ultra_lease_time_service_day (service_id, period_day)',
] as $needle) {
    check(str_contains($extract($migration), $needle), 'migration has ' . $needle);
}

// The migration DDL itself, translated to SQLite, so the test fails if code
// and table drift apart.
$columns = [];
$unique = '';
foreach (preg_split('/,(?![^\[]*\])/', preg_replace('/\(([^()]*)\)/', '[$1]', $extract($migration)) ?? '') ?: [] as $part) {
    $part = trim($part);
    if (preg_match('/^UNIQUE KEY \w+ \[(.*)\]$/', $part, $m) === 1) {
        $unique = 'UNIQUE (' . $m[1] . ')';
        continue;
    }
    if (str_starts_with($part, 'INDEX ')) {
        continue;
    }
    $part = str_replace(['BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY', ' UNSIGNED'], ['INTEGER PRIMARY KEY AUTOINCREMENT', ''], $part);
    $columns[] = str_replace(['[', ']'], ['(', ')'], $part);
}
$timeDdl = 'CREATE TABLE ultra_lease_time (' . implode(', ', $columns) . ', ' . $unique . ')';

function freshDb(string $timeDdl): Database
{
    $envPath = tempnam(sys_get_temp_dir(), 'lw-lease-time-');
    file_put_contents($envPath, "ULTRA_TOKEN_KEY=test-key\n");
    $db = new Database(new Config($envPath));
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec($timeDdl);
    $pdo->exec('CREATE TABLE ultra_client_leases (
        id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER NOT NULL, device_hash TEXT NOT NULL,
        forward_id INTEGER NOT NULL, policy_generation TEXT NOT NULL, nonce_hash TEXT NOT NULL,
        lease_hash TEXT NOT NULL, candidate_key_hash TEXT NULL, quota_bytes INTEGER NOT NULL DEFAULT 0,
        issued_at TEXT NOT NULL, expires_at TEXT NOT NULL, consumed_at TEXT NULL, revoked_at TEXT NULL,
        created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
        UNIQUE (client_id, device_hash, forward_id, nonce_hash))');
    $pdo->exec('CREATE TABLE ultra_lease_refusals (
        id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER NOT NULL, device_hash TEXT NOT NULL,
        forward_id INTEGER NOT NULL, error_code TEXT NOT NULL, created_at TEXT NOT NULL)');
    $db->setPdo($pdo);
    return $db;
}

/** @return list<array<string,mixed>> */
function timeRows(Database $db): array
{
    return $db->fetchAll('SELECT * FROM ultra_lease_time ORDER BY forward_id, device_hash, period_day');
}

$t0 = 1_900_000_000 - (1_900_000_000 % 86400) + 3600; // 01:00 UTC
$day0 = gmdate('Y-m-d', $t0);
$device = hash('sha256', 'device-a');

// 2. Ledger semantics.
$db = freshDb($timeDdl);
$ledger = new UltraLeaseTimeLedger($db);
check($ledger->recordLease(7, 70, $device, 42, $t0, $t0 + 300) === 300, 'first lease adds its full window');
check($ledger->recordLease(7, 70, $device, 42, $t0 + 240, $t0 + 540) === 240, 'renewal adds only the part past the watermark');
foreach ([1, 2, 3] as $candidate) {
    check($ledger->recordLease(7, 70, $device, 42, $t0 + 240, $t0 + 540) === 0, 'candidate lease #' . $candidate . ' adds nothing');
}
check($ledger->recordLease(7, 70, $device, 42, $t0 + 300, $t0 + 400) === 0, 'window inside the covered span adds nothing');
check($ledger->recordLease(7, 70, $device, 42, $t0 + 2000, $t0 + 2300) === 300, 'a gap is not billed');
check($ledger->recordLease(7, 70, hash('sha256', 'device-b'), 42, $t0, $t0 + 300) === 300, 'another device is its own key');
check($ledger->recordLease(7, 70, $device, 43, $t0, $t0 + 300) === 300, 'another forward is its own key (line-seconds)');
$rows = timeRows($db);
check(count($rows) === 3, 'one row per client+device+forward+day');
$main = array_values(array_filter($rows, static fn(array $r): bool => (int) $r['forward_id'] === 42 && $r['device_hash'] === $device))[0] ?? [];
check((int) ($main['leased_seconds'] ?? 0) === 840, 'union of windows = 300 + 240 + 300');
check((int) ($main['lease_count'] ?? 0) === 7, 'every issued lease is counted');
check((int) ($main['covered_until'] ?? 0) === $t0 + 2300, 'watermark is the furthest window end');
check((int) ($main['service_id'] ?? 0) === 70, 'service attribution stored');
check(($main['period_day'] ?? '') === $day0, 'UTC day bucket');
check(($main['first_issued_at'] ?? '') === gmdate('Y-m-d H:i:s', $t0) && ($main['last_issued_at'] ?? '') === gmdate('Y-m-d H:i:s', $t0 + 2000), 'first/last issue stamps');

$totals = $ledger->totalsForClient(7, $day0, $day0);
check($totals === ['leased_seconds' => 1440, 'lease_count' => 9, 'devices' => 2, 'forwards' => 2], 'client totals ' . json_encode($totals));
check($ledger->totalsForService(70, $day0, $day0)['leased_seconds'] === 1440, 'service totals');
check($ledger->totalsForService(71, $day0, $day0)['leased_seconds'] === 0, 'other service has nothing');
check($ledger->totalsForClient(7, 'bad', $day0)['leased_seconds'] === 0, 'malformed day range is empty');

// Midnight split.
$midnight = $t0 - 3600 + 86400;
check($ledger->recordLease(8, null, $device, 42, $midnight - 100, $midnight + 200) === 300, 'window across midnight adds 300');
$split = $db->fetchAll('SELECT period_day, leased_seconds, covered_until, service_id FROM ultra_lease_time WHERE client_id = 8 ORDER BY period_day');
check(count($split) === 2, 'midnight window splits into two day rows');
check((int) ($split[0]['leased_seconds'] ?? 0) === 100 && (int) ($split[1]['leased_seconds'] ?? 0) === 200, 'split seconds 100 + 200');
check(($split[1]['period_day'] ?? '') === gmdate('Y-m-d', $midnight), 'second row is the next UTC day');
check(array_key_exists('service_id', $split[0] ?? []) && $split[0]['service_id'] === null, 'unknown service stays NULL');
check($ledger->recordLease(8, 80, $device, 42, $midnight + 100, $midnight + 400) === 200, 'next-day renewal unions against its own day row');
check((int) ($db->fetchOne('SELECT service_id FROM ultra_lease_time WHERE client_id = 8 AND period_day = :d', ['d' => gmdate('Y-m-d', $midnight)])['service_id'] ?? 0) === 80, 'late service attribution fills a NULL');

// Input bounds.
check($ledger->recordLease(0, 1, $device, 42, $t0, $t0 + 300) === 0, 'no client');
check($ledger->recordLease(7, 1, 'not-a-hash', 42, $t0, $t0 + 300) === 0, 'bad device hash');
check($ledger->recordLease(7, 1, $device, 42, $t0, $t0) === 0, 'empty window');
check($ledger->recordLease(9, 1, $device, 42, $t0, $t0 + 86400 * 3) === UltraLeaseTimeLedger::MAX_WINDOW_SECONDS, 'window capped at the lease ceiling');

// A missing table is logged and swallowed.
$broken = new Database(new Config(''));
$broken->setPdo(new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]));
check((new UltraLeaseTimeLedger($broken))->recordLease(7, 1, $device, 42, $t0, $t0 + 300) === 0, 'ledger failure is fail-soft');
check((new UltraLeaseTimeLedger($broken))->totalsForClient(7, $day0, $day0)['leased_seconds'] === 0, 'lookup failure is fail-soft');

// 3. issueLease records issued leases only.
$generation = str_repeat('a', 64);
$clock = $t0;
$policyState = 'active';
$makeService = static function (Database $db) use ($generation, &$clock, &$policyState, $t0): UltraLeaseService {
    $envPath = tempnam(sys_get_temp_dir(), 'lw-lease-time-svc-');
    file_put_contents($envPath, "ULTRA_TOKEN_KEY=test-key\n");
    return new UltraLeaseService(
        new Config($envPath),
        $db,
        null,
        static fn(array $request, int $clientId, string $deviceId): array => [
            'selection_ref' => str_repeat('r', 43),
            'ip' => '203.0.113.20',
            'port' => 5438 + (int) ($request['candidate_offset'] ?? 0),
            'user' => 'line-user',
            'credential' => 'line-password',
        ],
        static function () use (&$clock): int {
            return $clock;
        },
        static function (int $clientId, string $deviceId, int $forwardId) use ($generation, &$policyState, $t0): array {
            return [
                'forward_id' => $forwardId,
                'service_id' => 555,
                'device_hash' => hash('sha256', $deviceId),
                'service_state' => $policyState,
                'policy_generation' => $generation,
                'target' => '198.51.100.10:443',
                'transport' => 'tcp',
                'rate_limit_mbps' => 0,
                'quota_remaining_bytes' => 1024 * 1024,
                'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', $t0 + 86400),
            ];
        },
    );
};
$request = static fn(string $nonce, int $offset = 0): array => [
    'forward_id' => 42,
    'session_ticket' => 'ticket-a',
    'policy_generation' => $generation,
    'nonce' => $nonce,
    'target_digest' => hash('sha256', '198.51.100.10:443'),
    'transport' => 'tcp',
    'candidate_offset' => $offset,
];

$db = freshDb($timeDdl);
$leases = $makeService($db);
$first = $leases->issueLease($request(str_repeat('1', 32)), 7, 'device-a');
check(($first['ok'] ?? false) === true, 'lease issues with the ledger wired in');
foreach ([1, 2, 3] as $offset) {
    $leases->issueLease($request(hash('sha256', str_repeat('1', 32) . "\n" . $offset), $offset), 7, 'device-a');
}
$clock = $t0 + 240;
$leases->issueLease($request(str_repeat('2', 32)), 7, 'device-a');
$row = $db->fetchOne('SELECT * FROM ultra_lease_time WHERE client_id = 7');
check((int) ($row['leased_seconds'] ?? 0) === UltraMode::MAX_POLICY_TTL_SECONDS + 240, 'issueLease unions candidates and renewals: ' . json_encode($row));
check((int) ($row['lease_count'] ?? 0) === 5, 'issueLease counts each issued lease');
check((int) ($row['service_id'] ?? 0) === 555, 'issueLease attributes the policy service');
check(($row['device_hash'] ?? '') === hash('sha256', 'device-a'), 'issueLease keys by device hash');

$policyState = 'suspended';
$clock = $t0 + 5000;
$refused = $leases->issueLease($request(str_repeat('3', 32)), 7, 'device-a');
check(($refused['error'] ?? '') === 'forward_inactive', 'refusal path still refuses');
check((int) ($db->fetchOne('SELECT leased_seconds FROM ultra_lease_time WHERE client_id = 7')['leased_seconds'] ?? 0) === UltraMode::MAX_POLICY_TTL_SECONDS + 240, 'a refused lease accrues no time');

// A panel whose migration has not run yet keeps issuing leases.
$policyState = 'active';
$noTable = freshDb($timeDdl);
$noTable->pdo()->exec('DROP TABLE ultra_lease_time');
check(($makeService($noTable)->issueLease($request(str_repeat('4', 32)), 7, 'device-a')['ok'] ?? false) === true, 'missing ledger table never blocks a lease');

if ($failures > 0) {
    fwrite(STDERR, "$failures failure(s)\n");
    exit(1);
}
echo "ok\n";
