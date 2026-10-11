<?php
declare(strict_types=1);

/**
 * Real-database coverage for OpenWrt router pairing.
 *
 * The rest of the OpenWrt tests use in-memory doubles, the way the Android
 * enrolment-code tests do. This one follows the SQLite re-exec convention from
 * knowledgebase_migration_behavior_test.php so the migration, the real SQL,
 * and the transaction/compare-and-swap path are exercised for real.
 */

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fail($message);
    }
}

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fail($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function expectApi(string $code, int $status, callable $callback): void
{
    try {
        $callback();
    } catch (\Leikwan\Master\Support\ApiException $exception) {
        assertSame($code, $exception->getMessage(), 'unexpected machine code for ' . $code);
        assertSame($status, $exception->statusCode(), 'unexpected status for ' . $code);
        return;
    }
    fail('expected ApiException ' . $code . ' but no exception was thrown');
}

function restartWithSqlite(): never
{
    $extensionDir = (string) ini_get('extension_dir');
    if ($extensionDir === '') {
        $extensionDir = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'ext';
    }
    assertTrue(is_dir($extensionDir), 'SQLite test extension directory is missing: ' . $extensionDir);

    $command = escapeshellarg(PHP_BINARY)
        . ' -n'
        . ' -d extension_dir=' . escapeshellarg($extensionDir)
        . ' -d extension=pdo_sqlite'
        . ' -d extension=sqlite3 '
        . escapeshellarg(__FILE__);
    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);
    $stream = $exitCode === 0 ? STDOUT : STDERR;
    if ($output !== []) {
        fwrite($stream, implode(PHP_EOL, $output) . PHP_EOL);
    }
    exit($exitCode);
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    restartWithSqlite();
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    if ($relative === false) {
        return;
    }
    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use Leikwan\Master\Config;
use Leikwan\Master\Controllers\ClientAgentController;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Http\Request;
use Leikwan\Master\Http\Response;
use Leikwan\Master\Support\AccessPolicyService;
use Leikwan\Master\Support\ClientOnlineSessionService;
use Leikwan\Master\Support\MigrationRunner;
use Leikwan\Master\Support\OpenWrtPairingCodeService;
use Leikwan\Master\Support\ProvisioningService;

/** The pairing endpoint's policy boundary is covered by openwrt_platform_normalization_test.php. */
final class PersistencePolicyProbe extends AccessPolicyService
{
    public function __construct(Database $db)
    {
        parent::__construct($db);
    }

    public function authorizeClientRequest(array $client, Request $request, string $touchType = 'request', ?string $deviceUuid = null): array
    {
        return ['decision' => 'allow', 'touch_type' => $touchType];
    }
}

/** @return array<string, mixed> */
function payload(Response $response): array
{
    $decoded = json_decode($response->body(), true);
    return is_array($decoded) ? $decoded : [];
}

function pairingRequest(string $code, string $deviceId, array $extraBody = []): Request
{
    $body = $extraBody + [
        'pairing_code' => $code,
        'device_id' => $deviceId,
        'device_name' => 'Leikwan Bridge on router',
        'platform' => 'openwrt',
        'architecture' => 'arm64',
    ];
    return new Request(
        'POST',
        '/api/client/devices/openwrt/pair',
        [],
        $body,
        [
            'x-device-id' => $deviceId,
            'x-device-name' => 'Leikwan Bridge on router',
            'x-device-platform' => 'openwrt',
            'x-device-form-factor' => 'router',
            'x-device-architecture' => 'arm64',
            'user-agent' => 'Leikwan-Client-Agent/0.9.0 (OpenWrt)',
        ],
        (string) json_encode($body, JSON_THROW_ON_ERROR),
        '203.0.113.10'
    );
}

$root = realpath(__DIR__ . '/..');
assertTrue($root !== false, 'could not resolve the master root');
$migrationName = '20260911_add_openwrt_pairing_codes.sql';
assertTrue(is_file($root . '/migrations/' . $migrationName), 'the pairing migration is missing');

$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'leikwan-openwrt-pairing-' . bin2hex(random_bytes(8));
assertTrue(mkdir($tmpDir, 0700, true) || is_dir($tmpDir), 'could not create a temporary directory');
$migrationsDir = $tmpDir . DIRECTORY_SEPARATOR . 'migrations';
assertTrue(mkdir($migrationsDir, 0700, true) || is_dir($migrationsDir), 'could not create a temporary migrations directory');

$dbPath = $tmpDir . DIRECTORY_SEPARATOR . 'panel.sqlite';
$envPath = $tmpDir . DIRECTORY_SEPARATOR . '.env';
file_put_contents($envPath, "DB_DRIVER=sqlite\nDB_NAME={$dbPath}\nOPENWRT_PAIRING_ENABLED=true\n");
$disabledEnvPath = $tmpDir . DIRECTORY_SEPARATOR . '.env.disabled';
file_put_contents($disabledEnvPath, "DB_DRIVER=sqlite\nDB_NAME={$dbPath}\nOPENWRT_PAIRING_ENABLED=false\n");
putenv('DB_DRIVER');
putenv('DB_NAME');
putenv('OPENWRT_PAIRING_ENABLED');

$config = new Config($envPath);
$db = new Database($config);
$pdo = $db->pdo();
$pdo->exec(
    'CREATE TABLE clients (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        agent_token TEXT NULL,
        status TEXT NOT NULL DEFAULT "active",
        owner_user_id INTEGER NULL,
        online_device_limit INTEGER NOT NULL DEFAULT 1
    )'
);
$pdo->exec(
    'CREATE TABLE client_device_sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        client_id INTEGER NOT NULL,
        device_id TEXT NOT NULL,
        device_name TEXT NULL,
        platform TEXT NULL,
        client_version TEXT NULL,
        os_release TEXT NULL,
        user_agent TEXT NULL,
        last_ip TEXT NULL,
        first_seen_at TEXT NOT NULL,
        last_seen_at TEXT NOT NULL,
        last_pull_at TEXT NULL,
        last_heartbeat_at TEXT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )'
);
// Two routers may be online for client 12; the online-limit check below
// exercises the refusal of a third one over real SQL.
$pdo->exec('INSERT INTO clients (id, name, agent_token, status, owner_user_id, online_device_limit) VALUES (12, "Home Router", "tok-12", "active", 7, 2)');
$pdo->exec('INSERT INTO clients (id, name, agent_token, status, owner_user_id) VALUES (13, "Someone Else", "tok-13", "active", 7)');

// 1. The migration file follows the repository DDL and numbering conventions.
//    The panel ships MySQL DDL (AUTO_INCREMENT, UNIQUE KEY, inline INDEX) like
//    every other migration in migrations/, so the SQLite fixture below mirrors
//    the same column set with SQLite types instead of replaying MySQL syntax.
$migrationSql = (string) file_get_contents($root . '/migrations/' . $migrationName);
foreach ([
    'CREATE TABLE IF NOT EXISTS openwrt_pairing_codes',
    'client_id BIGINT UNSIGNED NOT NULL',
    'code_hash CHAR(64) NOT NULL',
    'expires_at DATETIME NOT NULL',
    'used_at DATETIME NULL',
    'created_by_user_id BIGINT UNSIGNED NULL',
    'UNIQUE KEY uniq_openwrt_pairing_code_hash (code_hash)',
    'INDEX idx_openwrt_pairing_code_expiry (expires_at)',
    'REFERENCES clients(id) ON DELETE CASCADE',
] as $ddl) {
    assertTrue(str_contains($migrationSql, $ddl), 'the migration is missing DDL: ' . $ddl);
}
assertTrue(!str_contains($migrationSql, 'code VARCHAR'), 'the migration must not persist a plaintext code column');
assertTrue(
    str_contains((string) file_get_contents($root . '/migrations/schema.sql'), 'CREATE TABLE IF NOT EXISTS openwrt_pairing_codes'),
    'fresh installs must create the pairing table from schema.sql'
);

// The real migration directory keeps the pairing migration in date order.
// Later feature migrations may legitimately be appended, so the guard is
// "nothing older sorts after it" plus "listed exactly once" rather than
// "is the newest file in the directory".
$available = (new MigrationRunner($config, $db))->availableMigrations();
assertSame('schema.sql', $available[0] ?? null, 'schema.sql must stay first');
$pairingIndex = array_search($migrationName, $available, true);
assertTrue($pairingIndex !== false, 'the pairing migration must be listed');
assertSame(1, count(array_keys($available, $migrationName, true)), 'the pairing migration must be listed once');
foreach (array_slice($available, (int) $pairingIndex + 1) as $laterMigration) {
    assertTrue(
        strcmp(substr($laterMigration, 0, 8), substr($migrationName, 0, 8)) >= 0,
        'a migration older than the pairing migration must not sort after it: ' . $laterMigration
    );
}

// A staged copy applies through the real MigrationRunner (SQLite-compatible DDL
// with the exact columns of the migration).
$pdo->exec(
    'CREATE TABLE openwrt_pairing_codes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        client_id INTEGER NOT NULL,
        code_hash TEXT NOT NULL UNIQUE,
        expires_at TEXT NOT NULL,
        used_at TEXT NULL,
        created_by_user_id INTEGER NULL,
        created_at TEXT NOT NULL
    )'
);
file_put_contents(
    $migrationsDir . DIRECTORY_SEPARATOR . '20260101_openwrt_pairing_codes_sqlite_fixture.sql',
    "CREATE TABLE IF NOT EXISTS openwrt_pairing_codes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        client_id INTEGER NOT NULL,
        code_hash TEXT NOT NULL UNIQUE,
        expires_at TEXT NOT NULL,
        used_at TEXT NULL,
        created_by_user_id INTEGER NULL,
        created_at TEXT NOT NULL
    );"
);
$runner = new MigrationRunner($config, $db, $migrationsDir);
$applied = $runner->applyPending();
assertSame(['20260101_openwrt_pairing_codes_sqlite_fixture.sql'], $applied, 'the staged fixture must apply exactly once');
assertSame([], $runner->applyPending(), 'an applied migration must not re-apply');
$table = $db->fetchOne("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'openwrt_pairing_codes'");
assertSame('openwrt_pairing_codes', (string) ($table['name'] ?? ''), 'the pairing table must exist');
$columns = array_map(
    static fn(array $row): string => (string) ($row['name'] ?? ''),
    $db->fetchAll('PRAGMA table_info(openwrt_pairing_codes)')
);
foreach (['id', 'client_id', 'code_hash', 'expires_at', 'used_at', 'created_by_user_id', 'created_at'] as $column) {
    assertTrue(in_array($column, $columns, true), 'the pairing table is missing column ' . $column);
}
foreach (['code', 'pairing_code', 'plain_code'] as $forbidden) {
    assertTrue(!in_array($forbidden, $columns, true), 'the pairing table must not persist a plaintext column: ' . $forbidden);
}

// 2. Real issue/lookup/redeem against real SQL.
$codes = new OpenWrtPairingCodeService($db, $config);
$issued = $codes->issue(12, 1);
$code = (string) $issued['code'];
$canonical = str_replace('-', '', $code);
$row = $db->fetchOne('SELECT * FROM openwrt_pairing_codes WHERE client_id = :client_id LIMIT 1', ['client_id' => 12]);
assertSame(hash('sha256', $canonical), (string) ($row['code_hash'] ?? ''), 'only the sha256 hash may be stored');
assertSame(null, $row['used_at'] ?? null, 'a fresh code must be unused');
assertSame(12, (int) ($codes->findActive($code)['client_id'] ?? 0), 'the stored code must resolve its client');
assertTrue(!str_contains((string) file_get_contents($dbPath), $code), 'the plaintext pairing code must not reach the database file');
assertTrue(!str_contains((string) file_get_contents($dbPath), $canonical), 'the ungrouped pairing code must not reach the database file');

$first = $codes->consume($code);
assertTrue($first !== null, 'the first redemption must win the compare-and-swap');
assertSame(null, $codes->consume($code), 'the second redemption must lose the compare-and-swap');
assertSame(null, $codes->findActive($code), 'a redeemed code must be unusable');
$used = $db->fetchOne('SELECT used_at FROM openwrt_pairing_codes WHERE id = :id', ['id' => (int) $row['id']]);
assertTrue(($used['used_at'] ?? null) !== null, 'redemption must stamp used_at');

// Expiry is evaluated from the stored timestamp, not from process state.
$expiredCode = 'ABCD-2345';
$db->insert('openwrt_pairing_codes', [
    'client_id' => 13,
    'code_hash' => hash('sha256', 'ABCD2345'),
    'expires_at' => gmdate('Y-m-d H:i:s', time() - 30),
    'used_at' => null,
    'created_by_user_id' => null,
    'created_at' => gmdate('Y-m-d H:i:s'),
]);
assertSame(null, $codes->findActive($expiredCode), 'an expired stored code must not resolve');
assertSame(null, $codes->consume($expiredCode), 'an expired stored code must not be redeemable');
assertSame(0, (int) ($db->fetchOne('SELECT COUNT(*) AS total FROM openwrt_pairing_codes WHERE used_at IS NOT NULL AND client_id = 13')['total'] ?? -1), 'an expired code must stay unused');

// The unique index is the last line of defence against duplicate codes.
$duplicateRejected = false;
try {
    $db->insert('openwrt_pairing_codes', [
        'client_id' => 12,
        'code_hash' => hash('sha256', $canonical),
        'expires_at' => gmdate('Y-m-d H:i:s', time() + 900),
        'used_at' => null,
        'created_by_user_id' => null,
        'created_at' => gmdate('Y-m-d H:i:s'),
    ]);
} catch (\Throwable) {
    $duplicateRejected = true;
}
assertTrue($duplicateRejected, 'duplicate code hashes must be rejected by the unique index');

// 3. The real controller binds a device session over real SQL.
$controllerConfig = new Config($envPath);
$controller = new ClientAgentController(
    $controllerConfig,
    $db,
    new ProvisioningService($controllerConfig, $db),
    accessPolicy: new PersistencePolicyProbe($db)
);
$freshCode = (string) (new OpenWrtPairingCodeService($db, $controllerConfig))->issue(12, 1)['code'];
$response = $controller->pairOpenWrtDevice(pairingRequest($freshCode, 'openwrt-real-0001'), []);
$body = payload($response);
assertSame(200, $response->status(), 'pairing must answer 200');
assertSame(['ok', 'client_id', 'client_name', 'agent_token'], array_keys($body), 'pairing response shape');
assertSame(12, $body['client_id'] ?? null, 'pairing must bind the code client');
assertSame('tok-12', $body['agent_token'] ?? null, 'pairing must return the stored agent token');
$session = $db->fetchOne(
    'SELECT * FROM client_device_sessions WHERE client_id = :client_id AND device_id = :device_id',
    ['client_id' => 12, 'device_id' => 'openwrt-real-0001']
);
assertTrue($session !== null, 'pairing must persist a device session');
assertSame('openwrt', (string) ($session['platform'] ?? ''), 'the device session must record the router platform');
assertSame('Leikwan Bridge on router', (string) ($session['device_name'] ?? ''), 'the device session must record the router name');
assertSame(1, (new ClientOnlineSessionService($db))->activeCountForClient(12), 'the paired router must count as an online device');

// Replay, expiry, cross-client scope, and device validation all hold over real SQL.
expectApi('openwrt_pairing_code_invalid', 403, static fn() => $controller->pairOpenWrtDevice(pairingRequest($freshCode, 'openwrt-real-0002'), []));
$scoped = (string) (new OpenWrtPairingCodeService($db, $controllerConfig))->issue(12, 1)['code'];
expectApi('client_id_mismatch', 403, static fn() => $controller->pairOpenWrtDevice(pairingRequest($scoped, 'openwrt-real-0003', ['client_id' => 13]), []));
$scopedBody = payload($controller->pairOpenWrtDevice(pairingRequest($scoped, 'openwrt-real-0004'), []));
assertSame(12, $scopedBody['client_id'] ?? null, 'the pairing code client stays authoritative');
$overLimit = (string) (new OpenWrtPairingCodeService($db, $controllerConfig))->issue(12, 1)['code'];
expectApi('client_online_limit_reached', 429, static fn() => $controller->pairOpenWrtDevice(pairingRequest($overLimit, 'openwrt-real-0007'), []));
expectApi('openwrt_pairing_device_invalid', 422, static fn() => $controller->pairOpenWrtDevice(pairingRequest('ABCD-2345', 'bad device id'), []));

$disabledController = new ClientAgentController(
    new Config($disabledEnvPath),
    $db,
    new ProvisioningService(new Config($disabledEnvPath), $db),
    accessPolicy: new PersistencePolicyProbe($db)
);
expectApi('openwrt_pairing_unavailable', 503, static fn() => $disabledController->pairOpenWrtDevice(pairingRequest('ABCD-2345', 'openwrt-real-0005'), []));
$stillValid = (string) (new OpenWrtPairingCodeService($db, $controllerConfig))->issue(12, 1)['code'];
expectApi('openwrt_pairing_unavailable', 503, static fn() => $disabledController->pairOpenWrtDevice(pairingRequest($stillValid, 'openwrt-real-0006'), []));
assertTrue((new OpenWrtPairingCodeService($db, $controllerConfig))->findActive($stillValid) !== null, 'a disabled feature must not burn pairing codes');

echo "ok\n";
