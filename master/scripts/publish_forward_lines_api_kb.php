<?php
declare(strict_types=1);

/**
 * Publishes the Forward Lines API reference as a knowledgebase topic.
 *
 * The tenant guide catalog (resources/knowledgebase/topics.php) is pinned to
 * the seven onboarding guides and their fixed section structure, so this API
 * reference is published on its own instead of through a seed migration.
 * The script is idempotent: it creates the topic on first run and updates the
 * three language versions in place afterwards, through KnowledgebaseService so
 * the bodies pass the same sanitizer as the admin editor.
 *
 *   php scripts/publish_forward_lines_api_kb.php [--dry-run]
 */

$baseDir = dirname(__DIR__);

spl_autoload_register(static function (string $class) use ($baseDir): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = $baseDir . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

require_once $baseDir . '/src/Bootstrap.php';

use Leikwan\Master\Bootstrap;
use Leikwan\Master\Support\KnowledgebaseService;

const TOPIC_KEY = 'forward-lines-api';
const SORT_ORDER = 80;

$articles = [
    'en' => [
        'title' => 'Forward Lines API',
        'excerpt' => 'Create, update and delete forwards from your own program: authentication, endpoints, examples and error codes.',
        'body' => 'en/forward-lines-api.html',
    ],
    'zh-Hans' => [
        'title' => '专线 API',
        'excerpt' => '用你自己的程序创建、修改、删除转发：认证、接口、示例和错误码。',
        'body' => 'zh-Hans/forward-lines-api.html',
    ],
    'zh-TW' => [
        'title' => '專線 API',
        'excerpt' => '用你自己的程式建立、修改、刪除轉發：認證、介面、範例與錯誤碼。',
        'body' => 'zh-TW/forward-lines-api.html',
    ],
];

$dryRun = in_array('--dry-run', $argv, true);
$contentRoot = $baseDir . '/resources/knowledgebase';
foreach ($articles as $locale => $article) {
    $path = $contentRoot . '/' . $article['body'];
    if (!is_file($path)) {
        fwrite(STDERR, "missing body for {$locale}: {$path}\n");
        exit(1);
    }
    $articles[$locale]['body_html'] = (string) file_get_contents($path);
}

$app = new Bootstrap($baseDir . '/.env');
$db = $app->db();
$service = new KnowledgebaseService($db);

$admin = $db->fetchOne("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
$authorId = (int) ($admin['id'] ?? 0);

$topic = $db->fetchOne('SELECT * FROM knowledgebase_topics WHERE topic_key = :topic_key LIMIT 1', ['topic_key' => TOPIC_KEY]);
if ($dryRun) {
    echo 'topic: ' . ($topic === null ? 'would create' : 'exists id=' . $topic['id']) . "\n";
    foreach ($articles as $locale => $article) {
        $existing = $topic === null ? null : $service->findAdminArticle((int) $topic['id'], $locale);
        echo $locale . ': ' . ($existing === null ? 'would create' : 'would update id=' . $existing['id'])
            . ', ' . strlen($article['body_html']) . " bytes\n";
    }
    exit(0);
}

$topicId = $service->saveTopic([
    'id' => $topic['id'] ?? 0,
    'topic_key' => TOPIC_KEY,
    'is_pinned' => (string) ($topic['is_pinned'] ?? '0'),
    'sort_order' => (int) ($topic['sort_order'] ?? SORT_ORDER),
]);

foreach ($articles as $locale => $article) {
    $existing = $service->findAdminArticle($topicId, $locale);
    $articleId = $service->saveArticle([
        'id' => $existing['id'] ?? 0,
        'topic_id' => $topicId,
        'locale' => $locale,
        'status' => 'published',
        'title' => $article['title'],
        'excerpt' => $article['excerpt'],
        'body_html' => $article['body_html'],
    ], $authorId);
    echo $locale . ': ' . ($existing === null ? 'created' : 'updated') . ' article ' . $articleId . "\n";
}

echo 'topic ' . TOPIC_KEY . ' id=' . $topicId . " published\n";
