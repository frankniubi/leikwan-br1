<?php
declare(strict_types=1);

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Support\ApiException;
use Leikwan\Master\Support\DebugCollectionStore;

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

$tmpDir = sys_get_temp_dir() . '/lw-debug-store-' . bin2hex(random_bytes(6));
mkdir($tmpDir, 0700, true);
$envPath = $tmpDir . '/.env';
$dbPath = $tmpDir . '/test.sqlite';
$logDir = $tmpDir . '/logs';
file_put_contents($envPath, "DB_DRIVER=sqlite\nDB_NAME={$dbPath}\nAUTO_RUN_MIGRATIONS=false\nCLIENT_DEBUG_LOG_DIR={$logDir}\n");

$config = new Config($envPath);
$db = new Database($config);
$pdo = $db->pdo();
$pdo->exec('CREATE TABLE clients (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    debug_collection_uuid TEXT NULL,
    debug_collection_expires_at TEXT NULL,
    debug_collection_status TEXT NOT NULL DEFAULT "closed",
    debug_collection_bytes INTEGER NOT NULL DEFAULT 0,
    debug_collection_next_sequence INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT NOT NULL
)');
$pdo->exec('CREATE TABLE client_debug_chunks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id INTEGER NOT NULL,
    collection_uuid TEXT NOT NULL,
    sequence INTEGER NOT NULL,
    chunk_sha256 TEXT NOT NULL,
    byte_length INTEGER NOT NULL,
    storage_path TEXT NOT NULL,
    created_at TEXT NOT NULL,
    UNIQUE(client_id, collection_uuid, sequence)
)');

$now = gmdate('Y-m-d H:i:s');
$db->insert('clients', ['name' => 'Phone', 'updated_at' => $now]);

$store = new DebugCollectionStore($db, $config);
$created = $store->create(1);
$uuid = $created['uuid'];
if ($created['status'] !== 'active' || strlen($uuid) !== 36) {
    fail('create did not produce an active uuid4 collection');
}

$clientRow = $db->fetchOne('SELECT * FROM clients WHERE id = 1') ?? [];
$chunk0 = "=== header ===\nline 1\n";
$chunk1 = "line 2\nline 3\n";
$r0 = $store->append($clientRow, $uuid, 0, hash('sha256', $chunk0), base64_encode($chunk0));
$r1 = $store->append($clientRow, $uuid, 1, hash('sha256', $chunk1), base64_encode($chunk1));
if (!$r0['accepted'] || !$r1['accepted']) {
    fail('chunks were not accepted');
}

// Idempotent retry of sequence 0 succeeds without duplicating bytes.
$dup = $store->append($clientRow, $uuid, 0, hash('sha256', $chunk0), base64_encode($chunk0));
if (!$dup['duplicate'] || $dup['accepted']) {
    fail('idempotent retry was not marked duplicate');
}

// Assemble without explicit uuid resolves latest chunked collection in sequence order.
$assembled = $store->assembleText(1);
if ($assembled['uuid'] !== $uuid || $assembled['chunks'] !== 2 || $assembled['content'] !== ($chunk0 . $chunk1)) {
    fail('assembleText mismatch: ' . var_export($assembled, true));
}

// Assemble with unknown uuid fails 404.
$threw404 = false;
try {
    $store->assembleText(1, '00000000-0000-4000-8000-000000000000');
} catch (ApiException $e) {
    $threw404 = $e->getMessage() === 'debug_collection_not_found' && $e->statusCode() === 404;
}
if (!$threw404) {
    fail('missing collection did not throw 404');
}

// Route & controller wiring checks for client-initiated start and admin download.
$bootstrap = (string) file_get_contents(__DIR__ . '/../src/Bootstrap.php');
$agentController = (string) file_get_contents(__DIR__ . '/../src/Controllers/ClientAgentController.php');
$adminController = (string) file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');

foreach ([
    "'/api/client/debug/start'",
    "'/admin/clients/{id}/debug-log'",
] as $route) {
    if (!str_contains($bootstrap, $route)) {
        fail('Bootstrap missing route ' . $route);
    }
}
foreach (['function startDebugCollection(', 'reference_code', 'CLIENT_DEBUG_START_LIMIT_PER_MINUTE'] as $needle) {
    if (!str_contains($agentController, $needle)) {
        fail('ClientAgentController missing ' . $needle);
    }
}
foreach (['function downloadClientDebugLog(', '->assembleText('] as $needle) {
    if (!str_contains($adminController, $needle)) {
        fail('AdminController missing ' . $needle);
    }
}

echo "ok\n";
