<?php
declare(strict_types=1);

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Support\KnowledgebaseService;

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Database/Database.php';
require_once __DIR__ . '/../src/Support/KnowledgebaseService.php';

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$tmpDir = sys_get_temp_dir() . '/leikwan-kb-' . bin2hex(random_bytes(4));
if (!mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
    fail('failed to create temp dir');
}

$envPath = $tmpDir . '/.env';
$dbPath = $tmpDir . '/knowledgebase.sqlite';
file_put_contents($envPath, "DB_DRIVER=sqlite\nDB_NAME={$dbPath}\n");

$config = new Config($envPath);
$db = new Database($config);
$pdo = $db->pdo();

$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
$pdo->exec(
    'CREATE TABLE knowledgebase_threads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        author_user_id INTEGER NULL,
        title TEXT NOT NULL,
        slug TEXT NOT NULL UNIQUE,
        excerpt TEXT NULL,
        body_html TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT "draft",
        is_pinned INTEGER NOT NULL DEFAULT 0,
        sort_order INTEGER NOT NULL DEFAULT 100,
        published_at TEXT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )'
);
$pdo->exec("INSERT INTO users (name) VALUES ('Admin')");

$service = new KnowledgebaseService($db);
$firstId = $service->saveThread([
    'title' => 'Getting Started: First Forward Checklist',
    'excerpt' => '',
    'body_html' => '<p>Start with one healthy slave group, one route package, and one backend target.</p>',
    'status' => 'published',
    'is_pinned' => '1',
    'sort_order' => '10',
], 1);
$secondId = $service->saveThread([
    'title' => 'Getting Started: First Forward Checklist',
    'excerpt' => '',
    'body_html' => '<p>This second thread forces slug uniquing.</p>',
    'status' => 'published',
    'is_pinned' => '0',
    'sort_order' => '20',
], 1);
$draftId = $service->saveThread([
    'title' => 'Draft Only Thread',
    'excerpt' => 'admin only draft',
    'body_html' => '<p>Hidden from tenants.</p>',
    'status' => 'draft',
    'is_pinned' => '0',
    'sort_order' => '999',
], 1);

$published = $service->listPublishedThreads();
if (count($published) !== 2) {
    fail('expected exactly two published threads');
}
if ((int) $published[0]['id'] !== $firstId || (string) $published[0]['slug'] !== 'getting-started-first-forward-checklist') {
    fail('expected the pinned published thread to sort first');
}
if ((int) $published[1]['id'] !== $secondId || (string) $published[1]['slug'] !== 'getting-started-first-forward-checklist-2') {
    fail('expected duplicate titles to get a stable unique slug');
}
if (($service->findPublishedThreadBySlug('draft-only-thread')) !== null) {
    fail('draft threads must not be visible from the reader lookup');
}
if (trim((string) ($published[0]['excerpt'] ?? '')) === '') {
    fail('expected saveThread() to derive an excerpt when excerpt is blank');
}

$adminThreads = $service->listAdminThreads();
if (count($adminThreads) !== 3) {
    fail('expected admin listing to include published and draft threads');
}
$lastThread = $adminThreads[count($adminThreads) - 1] ?? null;
if (!is_array($lastThread) || (int) ($lastThread['id'] ?? 0) !== $draftId || (string) ($lastThread['status'] ?? '') !== 'draft') {
    fail('expected drafts to sort after published threads in the admin listing');
}

echo "ok\n";
