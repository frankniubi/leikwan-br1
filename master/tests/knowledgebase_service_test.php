<?php
declare(strict_types=1);

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Support\KnowledgebaseService;

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Database/Database.php';
require_once __DIR__ . '/../src/Support/LocaleResolver.php';
require_once __DIR__ . '/../src/Support/KnowledgebaseService.php';

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($actual !== $expected) {
        fail($message . '; expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
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
$pdo->exec(
    "INSERT INTO knowledgebase_threads
        (author_user_id, title, slug, excerpt, body_html, status, is_pinned, sort_order, published_at, created_at, updated_at)
     VALUES
        (1, 'Legacy rollback sentinel', 'legacy-rollback-sentinel', 'legacy excerpt', '<p>legacy body</p>',
         'published', 0, 100, '2026-07-01 00:00:00', '2026-07-01 00:00:00', '2026-07-01 00:00:00')"
);
$pdo->exec(
    "INSERT INTO knowledgebase_topics (id, topic_key, is_pinned, sort_order, created_at, updated_at) VALUES
        (1, 'getting-started-first-forward-checklist', 1, 90, '2026-07-01 00:00:00', '2026-07-10 00:00:00'),
        (2, 'requested-draft-falls-back', 0, 10, '2026-07-02 00:00:00', '2026-07-11 00:00:00'),
        (3, 'connection-modes-single-race-bond', 0, 20, '2026-07-03 00:00:00', '2026-07-12 00:00:00'),
        (4, 'draft-only', 0, 1, '2026-07-04 00:00:00', '2026-07-13 00:00:00')"
);
$pdo->exec(
    "INSERT INTO knowledgebase_articles
        (topic_id, locale, author_user_id, title, excerpt, body_html, status, published_at, created_at, updated_at) VALUES
        (1, 'en', 1, 'English getting started', 'English excerpt must not leak', '<p>English getting started body</p>', 'published', '2026-07-10 00:00:00', '2026-07-01 00:00:00', '2026-07-10 00:00:00'),
        (1, 'zh-Hans', 1, '简体入门', NULL, '<p>简体入门正文</p>', 'published', '2026-07-20 00:00:00', '2026-07-02 00:00:00', '2026-07-20 00:00:00'),
        (2, 'en', 1, 'Published English fallback', 'Published English excerpt', '<p>Published English body</p>', 'published', '2026-07-11 00:00:00', '2026-07-02 00:00:00', '2026-07-11 00:00:00'),
        (2, 'zh-Hans', 1, '未发布简体', '未发布简体摘要', '<p>未发布简体正文</p>', 'draft', NULL, '2026-07-03 00:00:00', '2026-07-21 00:00:00'),
        (3, 'en', 1, 'Connection modes', 'Single, race, and bond', '<p>English modes body</p>', 'published', '2026-07-12 00:00:00', '2026-07-03 00:00:00', '2026-07-12 00:00:00'),
        (4, 'en', 1, 'Draft only', 'Hidden draft', '<p>Hidden draft body</p>', 'draft', NULL, '2026-07-04 00:00:00', '2026-07-13 00:00:00')"
);

$service = new KnowledgebaseService($db);
$zhHans = $service->listPublishedTopics('zh-Hans');
assertSameValue(3, count($zhHans), 'only topics with a published requested or English article are visible');
assertSameValue(
    ['getting-started-first-forward-checklist', 'requested-draft-falls-back', 'connection-modes-single-race-bond'],
    array_column($zhHans, 'topic_key'),
    'pinned topics must sort first, then by sort order'
);

$requested = $zhHans[0];
assertSameValue('zh-Hans', $requested['locale_resolved'], 'published requested locale must win');
assertSameValue(false, $requested['used_fallback'], 'requested locale must report no fallback');
assertSameValue(true, is_bool($requested['used_fallback']), 'used_fallback false must be a real boolean');
assertSameValue('简体入门', $requested['title'], 'requested title must be selected');
assertSameValue(null, $requested['excerpt'], 'a nullable requested field must not be mixed with English fallback');
assertSameValue('<p>简体入门正文</p>', $requested['body_html'], 'requested body must be selected');
assertSameValue('2026-07-20 00:00:00', $requested['updated_at'], 'requested updated_at must be selected');

$draftFallback = $zhHans[1];
assertSameValue('en', $draftFallback['locale_resolved'], 'a requested draft must use published English fallback');
assertSameValue(true, $draftFallback['used_fallback'], 'a requested draft must report fallback');
assertSameValue(true, is_bool($draftFallback['used_fallback']), 'used_fallback true must be a real boolean');
assertSameValue('Published English fallback', $draftFallback['title'], 'draft requested title must stay hidden');
assertSameValue('Published English excerpt', $draftFallback['excerpt'], 'fallback excerpt must come from the complete English row');
assertSameValue('<p>Published English body</p>', $draftFallback['body_html'], 'fallback body must come from the complete English row');
assertSameValue('2026-07-11 00:00:00', $draftFallback['updated_at'], 'fallback updated_at must come from the complete English row');

$missingFallback = $zhHans[2];
assertSameValue('en', $missingFallback['locale_resolved'], 'a missing requested locale must fall back to English');
assertSameValue(true, $missingFallback['used_fallback'], 'a missing requested locale must report fallback');
assertSameValue(null, $service->findPublishedTopicByKey('draft-only', 'zh-Hans'), 'draft-only topic must stay hidden');
assertSameValue(null, $service->findPublishedTopicByKey('missing', 'en'), 'unknown topic must return null');

$detail = $service->findPublishedTopicByKey('requested-draft-falls-back', 'zh-Hans');
assertSameValue($draftFallback, $detail, 'list and detail reads must return the same resolved row shape');

$normalized = $service->findPublishedTopicByKey('getting-started-first-forward-checklist', 'unsupported-locale');
if ($normalized === null) {
    fail('unsupported locale must normalize to English');
}
assertSameValue('en', $normalized['locale_resolved'], 'unsupported locale must normalize to English');
assertSameValue(false, $normalized['used_fallback'], 'normalized English request must not report fallback');
assertSameValue('English getting started', $normalized['title'], 'normalized locale must select the English row');

$legacy = $service->listPublishedThreads();
assertSameValue(1, count($legacy), 'legacy published thread reader must remain available');
assertSameValue('legacy-rollback-sentinel', $legacy[0]['slug'], 'legacy rollback sentinel must remain readable');
assertSameValue(
    'legacy-rollback-sentinel',
    $service->findPublishedThreadBySlug('legacy-rollback-sentinel')['slug'] ?? null,
    'legacy detail reader must remain available'
);

echo "ok\n";
