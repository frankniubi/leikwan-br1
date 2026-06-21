<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertContains(string $haystack, string $needle): void
{
    if (!str_contains($haystack, $needle)) {
        fail('missing profile-linux schema snippet: ' . $needle);
    }
}

function deletePath(string $path): void
{
    if (is_file($path)) {
        @unlink($path);
        return;
    }

    if (!is_dir($path)) {
        return;
    }

    $items = scandir($path);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        deletePath($path . DIRECTORY_SEPARATOR . $item);
    }

    @rmdir($path);
}

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fail('could not resolve master root');
}

$schema = file_get_contents($root . '/migrations/schema.sql');
$migration = file_get_contents($root . '/migrations/20260622_add_client_profile_linux_fields.sql');

if (!is_string($schema) || !is_string($migration)) {
    fail('could not read task 1 schema files');
}

foreach ([
    [$schema, 'server_hostname VARCHAR(255) NULL'],
    [$schema, 'server_public_ip VARCHAR(64) NULL'],
    [$schema, "server_ip_state ENUM('fresh', 'stale', 'missing') NOT NULL DEFAULT 'missing'"],
    [$schema, 'server_ip_last_synced_at DATETIME NULL'],
    [$schema, 'allow_paid_lines TINYINT(1) NOT NULL DEFAULT 0'],
    [$schema, 'selected_carriers_json JSON NULL'],
    [$schema, "runtime_platform_scope VARCHAR(32) NOT NULL DEFAULT 'all'"],
    [$schema, "target_source VARCHAR(32) NOT NULL DEFAULT 'manual'"],
    [$schema, 'target_service_id BIGINT UNSIGNED NULL'],
    [$schema, 'CONSTRAINT fk_forwards_target_service FOREIGN KEY (target_service_id) REFERENCES services(id)'],
    [$schema, 'INDEX idx_forwards_target_service (target_service_id)'],
    [$migration, 'ALTER TABLE services ADD COLUMN server_hostname VARCHAR(255) NULL;'],
    [$migration, 'ALTER TABLE services ADD COLUMN server_public_ip VARCHAR(64) NULL;'],
    [$migration, "ALTER TABLE services ADD COLUMN server_ip_state ENUM('fresh', 'stale', 'missing') NOT NULL DEFAULT 'missing';"],
    [$migration, 'ALTER TABLE services ADD COLUMN server_ip_last_synced_at DATETIME NULL;'],
    [$migration, 'ALTER TABLE clients ADD COLUMN allow_paid_lines TINYINT(1) NOT NULL DEFAULT 0;'],
    [$migration, 'ALTER TABLE clients ADD COLUMN selected_carriers_json JSON NULL;'],
    [$migration, "ALTER TABLE clients ADD COLUMN runtime_platform_scope VARCHAR(32) NOT NULL DEFAULT 'all';"],
    [$migration, "ALTER TABLE forwards ADD COLUMN target_source VARCHAR(32) NOT NULL DEFAULT 'manual';"],
    [$migration, 'ALTER TABLE forwards ADD COLUMN target_service_id BIGINT UNSIGNED NULL;'],
    [$migration, 'CREATE INDEX idx_forwards_target_service ON forwards (target_service_id);'],
    [$migration, 'ALTER TABLE forwards ADD CONSTRAINT fk_forwards_target_service FOREIGN KEY (target_service_id) REFERENCES services(id);'],
] as [$haystack, $needle]) {
    assertContains($haystack, $needle);
}

$extDir = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'ext';
if (!is_dir($extDir)) {
    fail('sqlite smoke missing PHP extension directory: ' . $extDir);
}

$helperPath = tempnam(sys_get_temp_dir(), 'lw-profile-linux-smoke-');
if ($helperPath === false) {
    fail('could not create sqlite smoke helper file');
}

$helperCode = <<<'PHP'
<?php
declare(strict_types=1);

function helperFail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function helperDeletePath(string $path): void
{
    if (is_file($path)) {
        @unlink($path);
        return;
    }

    if (!is_dir($path)) {
        return;
    }

    $items = scandir($path);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        helperDeletePath($path . DIRECTORY_SEPARATOR . $item);
    }

    @rmdir($path);
}

$root = $argv[1] ?? '';
if ($root === '') {
    helperFail('missing root path');
}

require_once $root . '/src/Config.php';
require_once $root . '/src/Database/Database.php';
require_once $root . '/src/Support/ApiException.php';
require_once $root . '/src/Support/MigrationRunner.php';

$migrationSource = $root . '/migrations/20260622_add_client_profile_linux_fields.sql';
$dbPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lw-profile-linux-' . uniqid('', true) . '.sqlite';
$envPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lw-profile-linux-' . uniqid('', true) . '.env';
$migrationsDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lw-profile-linux-migrations-' . uniqid('', true);

$bootstrap = null;
$db = null;
$verify = null;

try {
    if (!mkdir($migrationsDir, 0777, true) && !is_dir($migrationsDir)) {
        helperFail('could not create temp migrations dir');
    }

    if (!copy($migrationSource, $migrationsDir . '/20260622_add_client_profile_linux_fields.sql')) {
        helperFail('could not copy migration for sqlite smoke');
    }

    file_put_contents($envPath, "DB_DRIVER=sqlite\nDB_NAME={$dbPath}\n");

    $bootstrap = new PDO('sqlite:' . $dbPath);
    $bootstrap->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $bootstrap->exec('CREATE TABLE services (id INTEGER PRIMARY KEY AUTOINCREMENT, whmcs_service_id INTEGER NOT NULL UNIQUE)');
    $bootstrap->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY AUTOINCREMENT, is_default_for_owner INTEGER NOT NULL DEFAULT 0)');
    $bootstrap->exec('CREATE TABLE forwards (id INTEGER PRIMARY KEY AUTOINCREMENT, service_id INTEGER NOT NULL)');
    $bootstrap = null;

    $config = new \Leikwan\Master\Config($envPath);
    $db = new \Leikwan\Master\Database\Database($config);
    $runner = new \Leikwan\Master\Support\MigrationRunner($config, $db, $migrationsDir);
    $runner->applyPending();

    $verify = new PDO('sqlite:' . $dbPath);
    $verify->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $serviceCols = array_column($verify->query('PRAGMA table_info(services)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    $clientCols = array_column($verify->query('PRAGMA table_info(clients)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    $forwardCols = array_column($verify->query('PRAGMA table_info(forwards)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    $forwardIndexes = array_column($verify->query('PRAGMA index_list(forwards)')->fetchAll(PDO::FETCH_ASSOC), 'name');

    foreach (['server_hostname', 'server_public_ip', 'server_ip_state', 'server_ip_last_synced_at'] as $column) {
        if (!in_array($column, $serviceCols, true)) {
            helperFail('sqlite migration did not add services.' . $column);
        }
    }

    foreach (['allow_paid_lines', 'selected_carriers_json', 'runtime_platform_scope'] as $column) {
        if (!in_array($column, $clientCols, true)) {
            helperFail('sqlite migration did not add clients.' . $column);
        }
    }

    foreach (['target_source', 'target_service_id'] as $column) {
        if (!in_array($column, $forwardCols, true)) {
            helperFail('sqlite migration did not add forwards.' . $column);
        }
    }

    if (!in_array('idx_forwards_target_service', $forwardIndexes, true)) {
        helperFail('sqlite migration did not add idx_forwards_target_service');
    }
} finally {
    $verify = null;
    $db = null;
    $bootstrap = null;
    @unlink($envPath);
    @unlink($dbPath);
    helperDeletePath($migrationsDir);
}

echo "sqlite-smoke-ok\n";
PHP;

file_put_contents($helperPath, $helperCode);

$command = escapeshellarg(PHP_BINARY)
    . ' -n'
    . ' -d extension_dir=' . escapeshellarg($extDir)
    . ' -d extension=pdo_sqlite'
    . ' -d extension=sqlite3 '
    . escapeshellarg($helperPath)
    . ' ' . escapeshellarg($root);

$output = [];
$exitCode = 0;
exec($command . ' 2>&1', $output, $exitCode);
@unlink($helperPath);

if ($exitCode !== 0) {
    fail("sqlite migration smoke failed:\n" . implode(PHP_EOL, $output));
}

assertContains(implode(PHP_EOL, $output), 'sqlite-smoke-ok');

echo "ok\n";
