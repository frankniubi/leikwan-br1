<?php
declare(strict_types=1);

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Support\KnowledgebaseService;

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Database/Database.php';
require_once __DIR__ . '/../src/Support/LocaleResolver.php';
require_once __DIR__ . '/../src/Support/KnowledgebaseHtmlSanitizer.php';
require_once __DIR__ . '/../src/Support/MermaidSourcePolicy.php';
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

function assertThrows(callable $callback, string $expectedMessage, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException $exception) {
        assertSameValue($expectedMessage, $exception->getMessage(), $message . ' message');
        return;
    }

    fail($message . '; expected InvalidArgumentException');
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

$topicId = $service->saveTopic([
    'topic_key' => 'connect-and-verify',
    'is_pinned' => '0',
    'sort_order' => '40',
]);
assertThrows(
    fn() => $service->saveTopic(['topic_key' => 'connect-and-verify']),
    'This topic key already exists.',
    'duplicate topic keys must be rejected'
);
assertThrows(
    fn() => $service->saveTopic(['topic_key' => 'Invalid Topic Key']),
    'Topic key must use lowercase letters, numbers, and single hyphens.',
    'invalid topic keys must be rejected'
);
$createdAt = $service->findAdminTopicById($topicId)['created_at'] ?? null;
$service->saveTopic([
    'id' => $topicId,
    'topic_key' => 'connect-and-verify-updated',
    'is_pinned' => '1',
    'sort_order' => '41',
]);
$updatedTopic = $service->findAdminTopicById($topicId);
if ($updatedTopic === null) {
    fail('updated topic must remain readable');
}
assertSameValue('connect-and-verify-updated', $updatedTopic['topic_key'], 'positive topic ID must update that row');
assertSameValue(1, (int) $updatedTopic['is_pinned'], 'topic pinned flag must update');
assertSameValue(41, (int) $updatedTopic['sort_order'], 'topic sort order must update');
assertSameValue($createdAt, $updatedTopic['created_at'], 'topic update must preserve created_at');
assertThrows(
    fn() => $service->saveTopic(['id' => 999999, 'topic_key' => 'must-not-insert']),
    'Knowledgebase topic not found.',
    'unknown positive topic ID must not insert'
);
assertSameValue(null, $service->findAdminTopicById(0), 'nonpositive topic lookup must return null');

$articleId = $service->saveArticle([
    'topic_id' => $topicId,
    'locale' => 'zh-TW',
    'id' => 0,
    'title' => '連線並驗證',
    'excerpt' => '確認應使用的位址，以及如何判斷連線成功。',
    'body_html' => '<p onclick="alert(1)">使用正確的位址。</p><script>alert(1)</script>',
    'status' => 'published',
    'mermaid_validation_digest' => '',
], 1);
$savedArticle = $service->findAdminArticle($topicId, 'zh-TW');
if ($savedArticle === null) {
    fail('saved localized article must be found by topic and locale');
}
assertSameValue($articleId, (int) $savedArticle['id'], 'topic and locale lookup must return the inserted article');
assertSameValue('<p>使用正確的位址。</p>', $savedArticle['body_html'], 'HTML must be sanitized before storage');
assertSameValue($savedArticle, $service->findAdminArticleById($articleId), 'ID lookup must return the saved article');
assertSameValue(null, $service->findAdminArticle(0, 'zh-TW'), 'invalid topic ID must not find an article');
assertSameValue(null, $service->findAdminArticle($topicId, 'fr'), 'invalid locale lookup must return null');
assertSameValue(null, $service->findAdminArticleById(0), 'nonpositive article lookup must return null');

assertThrows(
    fn() => $service->saveArticle([
        'topic_id' => $topicId, 'locale' => 'fr', 'title' => 'French',
        'excerpt' => '', 'body_html' => '', 'status' => 'draft',
    ], 1),
    'Unsupported knowledgebase locale.',
    'unsupported article locale must be rejected'
);
assertThrows(
    fn() => $service->saveArticle([
        'topic_id' => $topicId, 'locale' => 'en', 'title' => 'Invalid status',
        'excerpt' => '', 'body_html' => '', 'status' => 'archived',
    ], 1),
    'Unsupported knowledgebase article status.',
    'unsupported article status must be rejected'
);
assertThrows(
    fn() => $service->saveArticle([
        'topic_id' => $topicId, 'locale' => 'en', 'title' => '',
        'excerpt' => '', 'body_html' => '', 'status' => 'draft',
    ], 1),
    'Article title is required.',
    'draft article must require a title'
);
assertThrows(
    fn() => $service->saveArticle([
        'topic_id' => $topicId, 'locale' => 'en', 'title' => 'No excerpt',
        'excerpt' => ' ', 'body_html' => '<p>Visible</p>', 'status' => 'published',
    ], 1),
    'Published article excerpt is required.',
    'published article must require a nonblank excerpt'
);
assertThrows(
    fn() => $service->saveArticle([
        'topic_id' => $topicId, 'locale' => 'en', 'title' => 'No body',
        'excerpt' => 'Visible excerpt', 'body_html' => '<script>hidden()</script>', 'status' => 'published',
    ], 1),
    'Published article body is required.',
    'published article must require visible sanitized body text'
);

$draftId = $service->saveArticle([
    'topic_id' => $topicId, 'locale' => 'en', 'title' => 'Draft can be sparse',
    'excerpt' => '', 'body_html' => '', 'status' => 'draft',
], 1);
$draft = $service->findAdminArticleById($draftId);
assertSameValue('', $draft['excerpt'] ?? null, 'draft may have blank excerpt');
assertSameValue('', $draft['body_html'] ?? null, 'draft may have blank body');
assertSameValue(null, $draft['published_at'] ?? null, 'draft must not have published_at');

$mermaidBody = "<p>Diagram</p><pre class=\"mermaid\">flowchart TD\naccTitle: Connect\naccDescr: Connect safely\nA--&gt;B</pre>";
assertThrows(
    fn() => $service->saveArticle([
        'topic_id' => $topicId, 'locale' => 'zh-Hans', 'title' => '图示',
        'excerpt' => '图示摘要', 'body_html' => $mermaidBody, 'status' => 'published',
        'mermaid_validation_digest' => '',
    ], 1),
    'Mermaid validation digest does not match the article diagrams.',
    'publishing Mermaid must require a matching digest'
);
$mermaidId = $service->saveArticle([
    'topic_id' => $topicId, 'locale' => 'zh-Hans', 'title' => '图示',
    'excerpt' => '图示摘要', 'body_html' => $mermaidBody, 'status' => 'published',
    'mermaid_validation_digest' => Leikwan\Master\Support\MermaidSourcePolicy::digest($mermaidBody),
], 1);
if ($mermaidId <= 0) {
    fail('valid Mermaid digest must permit publication');
}

$articleCreatedAt = $savedArticle['created_at'];
$firstPublishedAt = $savedArticle['published_at'];
$updatedArticleId = $service->saveArticle([
    'id' => $articleId, 'topic_id' => $topicId, 'locale' => 'zh-TW',
    'title' => '更新後標題', 'excerpt' => '更新後摘要',
    'body_html' => '<p>更新後正文。</p>', 'status' => 'published',
    'mermaid_validation_digest' => '',
], 1);
assertSameValue($articleId, $updatedArticleId, 'existing article ID must update that row');
$updatedArticle = $service->findAdminArticleById($articleId);
assertSameValue('更新後標題', $updatedArticle['title'] ?? null, 'article title must update');
assertSameValue($articleCreatedAt, $updatedArticle['created_at'] ?? null, 'article update must preserve created_at');
assertSameValue($firstPublishedAt, $updatedArticle['published_at'] ?? null, 'published edit must preserve first published_at');
assertThrows(
    fn() => $service->saveArticle([
        'id' => $articleId, 'topic_id' => $topicId, 'locale' => 'en',
        'title' => 'Wrong ownership', 'excerpt' => '', 'body_html' => '', 'status' => 'draft',
    ], 1),
    'Knowledgebase article not found.',
    'article ID must belong to supplied topic and locale'
);
assertThrows(
    fn() => $service->saveArticle([
        'topic_id' => $topicId, 'locale' => 'zh-TW', 'title' => 'Duplicate',
        'excerpt' => '', 'body_html' => '', 'status' => 'draft',
    ], 1),
    'This language version already exists.',
    'insert path must reject an existing topic and locale'
);
assertThrows(
    fn() => $service->saveArticle([
        'topic_id' => 999999, 'locale' => 'en', 'title' => 'Missing topic',
        'excerpt' => '', 'body_html' => '', 'status' => 'draft',
    ], 1),
    'Knowledgebase topic not found.',
    'article save must require an existing topic'
);

$adminTopics = $service->listAdminTopics();
$adminTopic = null;
foreach ($adminTopics as $candidateTopic) {
    if ((int) ($candidateTopic['id'] ?? 0) === $topicId) {
        $adminTopic = $candidateTopic;
        break;
    }
}
if ($adminTopic === null) {
    fail('listAdminTopics must include saved topic');
}
assertSameValue('draft', $adminTopic['en_status'], 'admin topic list must include English status');
assertSameValue('published', $adminTopic['zh_hans_status'], 'admin topic list must include Simplified Chinese status');
assertSameValue('published', $adminTopic['zh_tw_status'], 'admin topic list must include Traditional Chinese status');
assertSameValue('Draft can be sparse', $adminTopic['en_title'], 'admin topic list must include English title');
assertSameValue('图示', $adminTopic['zh_hans_title'], 'admin topic list must include Simplified Chinese title');
assertSameValue('更新後標題', $adminTopic['zh_tw_title'], 'admin topic list must include Traditional Chinese title');
$service->saveArticle([
    'id' => $articleId, 'topic_id' => $topicId, 'locale' => 'zh-TW',
    'title' => '暫存標題', 'excerpt' => '', 'body_html' => '', 'status' => 'draft',
], 1);
assertSameValue(
    $firstPublishedAt,
    $service->findAdminArticleById($articleId)['published_at'] ?? null,
    'unpublishing must preserve the first published_at timestamp'
);

$articleDeleteTopicId = $service->saveTopic([
    'topic_key' => 'delete-one-locale', 'is_pinned' => '0', 'sort_order' => '900',
]);
$enDeleteId = $service->saveArticle([
    'id' => 0, 'topic_id' => $articleDeleteTopicId, 'locale' => 'en',
    'title' => 'Keep English', 'excerpt' => '', 'body_html' => '',
    'status' => 'draft', 'mermaid_validation_digest' => '',
], 1);
$zhDeleteId = $service->saveArticle([
    'id' => 0, 'topic_id' => $articleDeleteTopicId, 'locale' => 'zh-Hans',
    'title' => '删除简体版本', 'excerpt' => '', 'body_html' => '',
    'status' => 'draft', 'mermaid_validation_digest' => '',
], 1);
$service->deleteArticle($zhDeleteId);
assertSameValue(null, $service->findAdminArticleById($zhDeleteId), 'localized article must be deleted');
if ($service->findAdminArticleById($enDeleteId) === null) {
    fail('deleteArticle removed another locale');
}

$topicDeleteId = $service->saveTopic([
    'topic_key' => 'delete-whole-topic', 'is_pinned' => '0', 'sort_order' => '910',
]);
foreach (['en', 'zh-Hans', 'zh-TW'] as $deleteLocale) {
    $service->saveArticle([
        'id' => 0, 'topic_id' => $topicDeleteId, 'locale' => $deleteLocale,
        'title' => 'Delete ' . $deleteLocale, 'excerpt' => '', 'body_html' => '',
        'status' => 'draft', 'mermaid_validation_digest' => '',
    ], 1);
}
$service->deleteTopic($topicDeleteId);
assertSameValue(null, $service->findAdminTopicById($topicDeleteId), 'topic must be deleted');
assertSameValue(
    0,
    (int) $pdo->query("SELECT COUNT(*) FROM knowledgebase_articles WHERE topic_id = {$topicDeleteId}")->fetchColumn(),
    'topic articles must not become orphans'
);

$topicCount = (int) $pdo->query('SELECT COUNT(*) FROM knowledgebase_topics')->fetchColumn();
$articleCount = (int) $pdo->query('SELECT COUNT(*) FROM knowledgebase_articles')->fetchColumn();
assertThrows(fn() => $service->deleteArticle(0), 'Knowledgebase article not found.', 'zero article ID must be rejected');
assertThrows(fn() => $service->deleteArticle(999999), 'Knowledgebase article not found.', 'unknown article ID must be rejected');
assertThrows(fn() => $service->deleteTopic(0), 'Knowledgebase topic not found.', 'zero topic ID must be rejected');
assertThrows(fn() => $service->deleteTopic(999999), 'Knowledgebase topic not found.', 'unknown topic ID must be rejected');
assertSameValue($topicCount, (int) $pdo->query('SELECT COUNT(*) FROM knowledgebase_topics')->fetchColumn(), 'invalid deletes must not change topic count');
assertSameValue($articleCount, (int) $pdo->query('SELECT COUNT(*) FROM knowledgebase_articles')->fetchColumn(), 'invalid deletes must not change article count');

$rollbackTopicId = $service->saveTopic([
    'topic_key' => 'rollback-delete-topic', 'is_pinned' => '0', 'sort_order' => '920',
]);
$rollbackArticleId = $service->saveArticle([
    'topic_id' => $rollbackTopicId, 'locale' => 'en', 'title' => 'Rollback article',
    'excerpt' => '', 'body_html' => '', 'status' => 'draft',
], 1);
$pdo->exec("CREATE TRIGGER fail_topic_delete BEFORE DELETE ON knowledgebase_topics BEGIN SELECT RAISE(ABORT, 'forced topic delete failure'); END");
try {
    $service->deleteTopic($rollbackTopicId);
    fail('forced topic delete failure must throw');
} catch (Throwable $exception) {
    if (!str_contains($exception->getMessage(), 'forced topic delete failure')) {
        throw $exception;
    }
} finally {
    $pdo->exec('DROP TRIGGER fail_topic_delete');
}
if ($service->findAdminTopicById($rollbackTopicId) === null) {
    fail('failed topic delete must roll back topic');
}
if ($service->findAdminArticleById($rollbackArticleId) === null) {
    fail('failed topic delete must roll back article');
}
assertSameValue(
    1,
    (int) $pdo->query("SELECT COUNT(*) FROM knowledgebase_threads WHERE slug = 'legacy-rollback-sentinel'")->fetchColumn(),
    'localized admin writes and deletes must not change legacy sentinel'
);

$legacy = $service->listPublishedThreads();
assertSameValue(1, count($legacy), 'legacy published thread reader must remain available');
assertSameValue('legacy-rollback-sentinel', $legacy[0]['slug'], 'legacy rollback sentinel must remain readable');
assertSameValue(
    'legacy-rollback-sentinel',
    $service->findPublishedThreadBySlug('legacy-rollback-sentinel')['slug'] ?? null,
    'legacy detail reader must remain available'
);

echo "ok\n";
