<?php
declare(strict_types=1);

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Support\MigrationRunner;

function failRefreshMigration(string $message): never
{
    fwrite(STDERR, 'knowledgebase refresh migration: ' . $message . PHP_EOL);
    exit(1);
}

function assertRefreshMigration(bool $condition, string $message): void
{
    if (!$condition) {
        failRefreshMigration($message);
    }
}

function cleanupRefreshMigration(string $path): void
{
    if (is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            cleanupRefreshMigration($path . DIRECTORY_SEPARATOR . $entry);
        }
    }
    @rmdir($path);
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $extensionDir = (string) ini_get('extension_dir');
    if ($extensionDir === '') {
        $extensionDir = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'ext';
    }
    if (!is_dir($extensionDir)) {
        failRefreshMigration('pdo_sqlite extension directory is missing');
    }
    $command = escapeshellarg(PHP_BINARY)
        . ' -n'
        . ' -d extension_dir=' . escapeshellarg($extensionDir)
        . ' -d extension=pdo_sqlite'
        . ' -d extension=sqlite3 '
        . escapeshellarg(__FILE__);
    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);
    if ($output !== []) {
        fwrite($exitCode === 0 ? STDOUT : STDERR, implode(PHP_EOL, $output) . PHP_EOL);
    }
    exit($exitCode);
}

$root = realpath(__DIR__ . '/..');
assertRefreshMigration($root !== false, 'could not resolve master root');
require_once $root . '/src/Config.php';
require_once $root . '/src/Database/Database.php';
require_once $root . '/src/Support/ApiException.php';
require_once $root . '/src/Support/MigrationRunner.php';

// The resource HTML under resources/knowledgebase/ keeps evolving after this
// migration shipped (later guide rewrites), so the fixture is the body frozen
// inside the migration itself: the first guarded UPDATE's body_html literal.
// Normalise line endings so a CRLF checkout (Windows) sees the same LF bytes
// the panel is deployed with; the guarded SHA-256 predicates are over LF text.
$refreshSql = str_replace("\r\n", "\n", (string) file_get_contents($root . '/migrations/20260806_refresh_tenant_user_guides.sql'));
$bodyStart = strpos($refreshSql, "body_html = '");
$bodyEnd = $bodyStart === false ? false : strpos($refreshSql, "',\n    status = 'published'", $bodyStart);
assertRefreshMigration($bodyStart !== false && $bodyEnd !== false, 'could not locate the five-minute body in the refresh migration');
$bodyStart += strlen("body_html = '");
$newBody = str_replace("''", "'", substr($refreshSql, $bodyStart, $bodyEnd - $bodyStart));
assertRefreshMigration($newBody !== '' && str_contains($newBody, 'kb-screenshot-figure'), 'new body is missing');
$oldBody = preg_replace('/<figure class="kb-screenshot-figure">.*?<\/figure>\R/s', '', $newBody);
assertRefreshMigration(is_string($oldBody), 'could not derive old body fixture');
assertRefreshMigration(
    hash('sha256', $oldBody) === '5a9d1cf71a3f498b422e861112411cc3a06228df340b8cd0183979e910af626d',
    'old body fixture hash drifted; refresh the guarded hash intentionally'
);

$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'leikwan-kb-refresh-' . bin2hex(random_bytes(8));
$dbPath = $tmpDir . DIRECTORY_SEPARATOR . 'knowledgebase.sqlite';
$envPath = $tmpDir . DIRECTORY_SEPARATOR . '.env';
$migrationsDir = $tmpDir . DIRECTORY_SEPARATOR . 'migrations';
$failure = null;

try {
    assertRefreshMigration(mkdir($migrationsDir, 0700, true) || is_dir($migrationsDir), 'could not create temporary directories');
    $migration = $root . '/migrations/20260806_refresh_tenant_user_guides.sql';
    assertRefreshMigration(is_file($migration), 'refresh migration is missing');
    assertRefreshMigration(file_put_contents($migrationsDir . '/20260806_refresh_tenant_user_guides.sql', $refreshSql) !== false, 'could not copy refresh migration');
    file_put_contents($envPath, "DB_DRIVER=sqlite\nDB_NAME={$dbPath}\n");

    $config = new Config($envPath);
    $db = new Database($config);
    $pdo = $db->pdo();
    $pdo->sqliteCreateFunction('SHA2', static fn(string $value, int $bits): string => $bits === 256 ? hash('sha256', $value) : '', 2);
    $pdo->exec('CREATE TABLE knowledgebase_topics (id INTEGER PRIMARY KEY AUTOINCREMENT, topic_key TEXT NOT NULL UNIQUE)');
    $pdo->exec(
        'CREATE TABLE knowledgebase_articles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            topic_id INTEGER NOT NULL,
            locale TEXT NOT NULL,
            title TEXT NOT NULL,
            excerpt TEXT NOT NULL,
            body_html TEXT NOT NULL,
            status TEXT NOT NULL,
            published_at TEXT NULL,
            updated_at TEXT NOT NULL,
            UNIQUE (topic_id, locale)
        )'
    );
    $pdo->exec("INSERT INTO knowledgebase_topics (topic_key) VALUES ('getting-started-five-minute-overview')");
    $topicId = (int) $pdo->lastInsertId();
    $insert = $pdo->prepare(
        'INSERT INTO knowledgebase_articles (topic_id, locale, title, excerpt, body_html, status, published_at, updated_at)
         VALUES (:topic_id, :locale, :title, :excerpt, :body_html, "published", NULL, "2026-07-29 00:00:00")'
    );
    $insert->execute([
        'topic_id' => $topicId,
        'locale' => 'en',
        'title' => 'Old title',
        'excerpt' => 'Old excerpt',
        'body_html' => $oldBody,
    ]);
    $insert->execute([
        'topic_id' => $topicId,
        'locale' => 'zh-Hans',
        'title' => 'Administrator title',
        'excerpt' => 'Administrator excerpt',
        'body_html' => '<p>Administrator edited body.</p>',
    ]);

    $runner = new MigrationRunner($config, $db, $migrationsDir);
    assertRefreshMigration(
        $runner->applyPending() === ['20260806_refresh_tenant_user_guides.sql'],
        'refresh migration must apply exactly once'
    );

    $updated = $pdo->query("SELECT title, excerpt, body_html FROM knowledgebase_articles WHERE topic_id = {$topicId} AND locale = 'en'")->fetch(PDO::FETCH_ASSOC);
    assertRefreshMigration(is_array($updated), 'built-in article disappeared');
    assertRefreshMigration($updated['body_html'] === $newBody, 'untouched built-in article was not refreshed');
    assertRefreshMigration($updated['title'] === 'Get Connected in Five Minutes', 'title was not refreshed');

    $edited = $pdo->query("SELECT title, excerpt, body_html FROM knowledgebase_articles WHERE topic_id = {$topicId} AND locale = 'zh-Hans'")->fetch(PDO::FETCH_ASSOC);
    assertRefreshMigration(is_array($edited), 'edited article disappeared');
    assertRefreshMigration($edited['body_html'] === '<p>Administrator edited body.</p>', 'administrator edit was overwritten');
    assertRefreshMigration($runner->applyPending() === [], 'refresh migration must be idempotent');
} catch (Throwable $throwable) {
    $failure = $throwable;
} finally {
    cleanupRefreshMigration($tmpDir);
}

if ($failure instanceof Throwable) {
    fwrite(STDERR, $failure->getMessage() . PHP_EOL);
    exit(1);
}

echo "knowledgebase_refresh_migration_test: OK\n";
