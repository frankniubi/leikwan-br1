<?php
declare(strict_types=1);

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Support\ApiException;
use Leikwan\Master\Support\MigrationRunner;

function failMigrationBehavior(string $message): never
{
    throw new RuntimeException('knowledgebase migration behavior: ' . $message);
}

function assertMigrationBehaviorSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        failMigrationBehavior($message . '; expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function deleteMigrationBehaviorPath(string $path): void
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
        if ($item !== '.' && $item !== '..') {
            deleteMigrationBehaviorPath($path . DIRECTORY_SEPARATOR . $item);
        }
    }
    @rmdir($path);
}

function assertMigrationBehavior(bool $condition, string $message): void
{
    if (!$condition) {
        failMigrationBehavior($message);
    }
}

function restartMigrationBehaviorWithSqlite(): never
{
    $extensionDir = (string) ini_get('extension_dir');
    if ($extensionDir === '') {
        $extensionDir = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'ext';
    }
    assertMigrationBehavior(is_dir($extensionDir), 'SQLite test extension directory is missing: ' . $extensionDir);

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
    restartMigrationBehaviorWithSqlite();
}

$root = realpath(__DIR__ . '/..');
assertMigrationBehavior($root !== false, 'could not resolve master root');

require_once $root . '/src/Config.php';
require_once $root . '/src/Database/Database.php';
require_once $root . '/src/Support/ApiException.php';
require_once $root . '/src/Support/LocaleResolver.php';
require_once $root . '/src/Support/KnowledgebaseService.php';
require_once $root . '/src/Support/MigrationRunner.php';

$previousEnvironment = [];
foreach (['DB_DRIVER', 'DB_NAME'] as $name) {
    $value = getenv($name);
    $previousEnvironment[$name] = $value === false ? null : $value;
}

$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'leikwan-kb-migration-' . bin2hex(random_bytes(8));
$dbPath = $tmpDir . DIRECTORY_SEPARATOR . 'knowledgebase.sqlite';
$envPath = $tmpDir . DIRECTORY_SEPARATOR . '.env';
$migrationsDir = $tmpDir . DIRECTORY_SEPARATOR . 'migrations';
$pdo = null;
$db = null;
$failure = null;

try {
    assertMigrationBehavior(mkdir($tmpDir, 0700, true) || is_dir($tmpDir), 'could not create temporary directory');
    assertMigrationBehavior(mkdir($migrationsDir, 0700, true) || is_dir($migrationsDir), 'could not create temporary migrations directory');

    $seedMigration = $root . '/migrations/20260729_seed_multilingual_knowledgebase.sql';
    assertMigrationBehavior(is_file($seedMigration), 'generated seed migration is missing');
    assertMigrationBehavior(
        copy($seedMigration, $migrationsDir . '/20260729_seed_multilingual_knowledgebase.sql'),
        'could not copy generated seed migration'
    );

    file_put_contents($envPath, "DB_DRIVER=sqlite\nDB_NAME={$dbPath}\n");
    putenv('DB_DRIVER');
    putenv('DB_NAME');

    $config = new Config($envPath);
    $db = new Database($config);
    $pdo = $db->pdo();
    assertMigrationBehavior(method_exists($pdo, 'sqliteCreateCollation'), 'PDO SQLite collation registration is unavailable');
    $pdo->sqliteCreateCollation(
        'utf8mb4_general_ci',
        static fn (string $left, string $right): int => strcasecmp($left, $right)
    );
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
    $pdo->exec(
        'CREATE TABLE knowledgebase_topics (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            topic_key TEXT NOT NULL UNIQUE,
            is_pinned INTEGER NOT NULL DEFAULT 0,
            sort_order INTEGER NOT NULL DEFAULT 100,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE knowledgebase_articles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            topic_id INTEGER NOT NULL,
            locale TEXT NOT NULL,
            author_user_id INTEGER NULL,
            title TEXT NOT NULL,
            excerpt TEXT NULL,
            body_html TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT "draft",
            published_at TEXT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            UNIQUE (topic_id, locale)
        )'
    );
    $pdo->exec("INSERT INTO users (name) VALUES ('Administrator')");

    $legacyRows = [
        ['getting-started-first-forward-checklist', 'Old mixed built-in', 1, 37, '<p>Old mixed built-in body.</p>'],
        ['connection-modes-single-race-bond', 'Old modes', 0, 50, '<p>Old modes body.</p>'],
        ['bond-sidecar-setup', 'Old sidecar', 0, 60, '<p>Old sidecar body.</p>'],
        ['troubleshooting-common-mistakes', 'Old troubleshooting', 0, 70, '<p>Old troubleshooting body.</p>'],
        ['administrator-custom-guide', 'Administrator custom guide', 1, 5, '<p>Administrator custom body.</p>'],
    ];
    $insertLegacy = $pdo->prepare(
        'INSERT INTO knowledgebase_threads (
            author_user_id, title, slug, excerpt, body_html, status, is_pinned, sort_order, published_at, created_at, updated_at
        ) VALUES (
            1, :title, :slug, :excerpt, :body_html, "published", :is_pinned, :sort_order,
            "2026-07-29 12:00:00", "2026-07-29 12:00:00", "2026-07-29 12:00:00"
        )'
    );
    foreach ($legacyRows as [$slug, $title, $isPinned, $sortOrder, $bodyHtml]) {
        $insertLegacy->execute([
            'title' => $title,
            'slug' => $slug,
            'excerpt' => $title . ' excerpt',
            'body_html' => $bodyHtml,
            'is_pinned' => $isPinned,
            'sort_order' => $sortOrder,
        ]);
    }

    $runner = new MigrationRunner($config, $db, $migrationsDir);
    assertMigrationBehaviorSame(
        ['20260729_seed_multilingual_knowledgebase.sql'],
        $runner->applyPending(),
        'first migration run must apply the generated seed'
    );

    assertMigrationBehaviorSame(10, (int) $pdo->query('SELECT COUNT(*) FROM knowledgebase_topics')->fetchColumn(), 'first run must produce ten topics');
    assertMigrationBehaviorSame(22, (int) $pdo->query('SELECT COUNT(*) FROM knowledgebase_articles')->fetchColumn(), 'first run must produce twenty-two articles');

    $firstForward = $pdo->query(
        "SELECT topic.is_pinned, topic.sort_order, article.body_html
         FROM knowledgebase_topics AS topic
         INNER JOIN knowledgebase_articles AS article ON article.topic_id = topic.id AND article.locale = 'en'
         WHERE topic.topic_key = 'getting-started-first-forward-checklist'"
    )->fetch(PDO::FETCH_ASSOC);
    assertMigrationBehavior(is_array($firstForward), 'built-in first Forward article is missing');
    assertMigrationBehaviorSame(1, (int) $firstForward['is_pinned'], 'built-in legacy pinned metadata must survive');
    assertMigrationBehaviorSame(37, (int) $firstForward['sort_order'], 'built-in legacy sort metadata must survive');
    assertMigrationBehavior(!str_contains((string) $firstForward['body_html'], 'Old mixed built-in'), 'built-in English body must be replaced by the generated guide');

    $custom = $pdo->query(
        "SELECT article.locale, article.title, article.body_html
         FROM knowledgebase_articles AS article
         INNER JOIN knowledgebase_topics AS topic ON topic.id = article.topic_id
         WHERE topic.topic_key = 'administrator-custom-guide'"
    )->fetch(PDO::FETCH_ASSOC);
    assertMigrationBehavior(is_array($custom), 'custom legacy article is missing');
    assertMigrationBehaviorSame('en', $custom['locale'], 'custom legacy article must migrate as English');
    assertMigrationBehaviorSame('Administrator custom guide', $custom['title'], 'custom legacy title must survive');
    assertMigrationBehavior(str_contains((string) $custom['body_html'], 'Administrator custom body'), 'custom legacy body must survive');

    $pdo->exec(
        "UPDATE knowledgebase_articles
         SET body_html = '<p>Administrator edited after migration.</p>'
         WHERE id = " . (int) $pdo->query(
            "SELECT article.id
             FROM knowledgebase_articles AS article
             INNER JOIN knowledgebase_topics AS topic ON topic.id = article.topic_id
             WHERE topic.topic_key = 'administrator-custom-guide' AND article.locale = 'en'"
        )->fetchColumn()
    );
    $pdo->exec("DELETE FROM schema_migrations WHERE migration_name = '20260729_seed_multilingual_knowledgebase.sql'");

    assertMigrationBehaviorSame(
        ['20260729_seed_multilingual_knowledgebase.sql'],
        $runner->applyPending(),
        'rerun after history removal must execute the generated seed'
    );
    assertMigrationBehaviorSame(10, (int) $pdo->query('SELECT COUNT(*) FROM knowledgebase_topics')->fetchColumn(), 'rerun must not add topics');
    assertMigrationBehaviorSame(22, (int) $pdo->query('SELECT COUNT(*) FROM knowledgebase_articles')->fetchColumn(), 'rerun must not add articles');
    assertMigrationBehaviorSame(
        '<p>Administrator edited after migration.</p>',
        $pdo->query(
            "SELECT article.body_html
             FROM knowledgebase_articles AS article
             INNER JOIN knowledgebase_topics AS topic ON topic.id = article.topic_id
             WHERE topic.topic_key = 'administrator-custom-guide' AND article.locale = 'en'"
        )->fetchColumn(),
        'rerun must preserve an administrator edit to the custom legacy article'
    );

    $service = new Leikwan\Master\Support\KnowledgebaseService($db);
    $topics = $service->listPublishedTopics('zh-Hans');
    assertMigrationBehaviorSame(8, count($topics), 'reader service must expose the migrated custom plus default topics');
    $customResolved = $service->findPublishedTopicByKey('administrator-custom-guide', 'zh-Hans');
    assertMigrationBehavior(is_array($customResolved), 'reader service must expose the custom English article');
    assertMigrationBehaviorSame('en', $customResolved['locale_resolved'] ?? '', 'custom English article must resolve as English');
    assertMigrationBehavior((bool) ($customResolved['used_fallback'] ?? false), 'custom English article must expose the visible fallback contract');
    foreach ([
        'getting-started-first-forward-checklist',
        'troubleshooting-common-mistakes',
    ] as $legacyKey) {
        assertMigrationBehavior(
            $service->findPublishedTopicByKey($legacyKey, 'zh-TW') !== null,
            'legacy stable key must remain readable after migration: ' . $legacyKey
        );
    }
} catch (Throwable $throwable) {
    $failure = $throwable;
} finally {
    $pdo = null;
    $db = null;
    foreach ($previousEnvironment as $name => $value) {
        if ($value === null) {
            putenv($name);
        } else {
            putenv($name . '=' . $value);
        }
    }
    deleteMigrationBehaviorPath($tmpDir);
}

if ($failure instanceof Throwable) {
    fwrite(STDERR, $failure->getMessage() . PHP_EOL);
    exit(1);
}

echo "ok\n";
