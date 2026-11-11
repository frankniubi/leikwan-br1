<?php
declare(strict_types=1);

/**
 * Publishes knowledgebase topics from resources/knowledgebase without a seed
 * migration.
 *
 * Accepts topic keys from the onboarding catalog (topics.php) and from
 * supplementary_topics.php. Each run creates the topic if needed and writes
 * the three language versions through KnowledgebaseService, so bodies pass the
 * same sanitizer and real-address guard as the admin editor.
 *
 * Mermaid diagrams are normally validated by the admin editor in a browser.
 * This script only republishes a body whose diagrams are identical to the ones
 * already published; a changed diagram has to go through the editor.
 *
 *   php scripts/publish_knowledgebase_topics.php [--dry-run] <topic_key>...
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
use Leikwan\Master\Support\MermaidSourcePolicy;

$arguments = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $arguments, true);
$topicKeys = array_values(array_filter($arguments, static fn (string $argument): bool => !str_starts_with($argument, '--')));
if ($topicKeys === []) {
    fwrite(STDERR, "usage: php scripts/publish_knowledgebase_topics.php [--dry-run] <topic_key>...\n");
    exit(2);
}

$contentRoot = $baseDir . '/resources/knowledgebase';
$catalog = (require $contentRoot . '/topics.php') + (require $contentRoot . '/supplementary_topics.php');

$app = new Bootstrap($baseDir . '/.env');
$db = $app->db();
$service = new KnowledgebaseService($db);

$admin = $db->fetchOne("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
$authorId = (int) ($admin['id'] ?? 0);

foreach ($topicKeys as $topicKey) {
    $definition = $catalog[$topicKey] ?? null;
    if (!is_array($definition)) {
        fwrite(STDERR, "unknown topic: {$topicKey}\n");
        exit(1);
    }

    $bodies = [];
    foreach ($definition['articles'] as $locale => $article) {
        $path = $contentRoot . '/' . $article['body'];
        if (!is_file($path)) {
            fwrite(STDERR, "missing body for {$topicKey} {$locale}: {$path}\n");
            exit(1);
        }
        $bodies[$locale] = (string) file_get_contents($path);
    }

    $topic = $db->fetchOne('SELECT * FROM knowledgebase_topics WHERE topic_key = :topic_key LIMIT 1', ['topic_key' => $topicKey]);

    // Refuse before writing anything if a diagram differs from the published one.
    $digests = [];
    foreach ($bodies as $locale => $body) {
        $diagrams = MermaidSourcePolicy::extract($body);
        if ($diagrams === []) {
            $digests[$locale] = '';
            continue;
        }
        $existing = $topic === null ? null : $service->findAdminArticle((int) $topic['id'], $locale);
        if ($existing === null || MermaidSourcePolicy::extract((string) $existing['body_html']) !== $diagrams) {
            fwrite(STDERR, "{$topicKey} {$locale}: diagrams differ from the published article; publish it through the admin editor\n");
            exit(1);
        }
        $digests[$locale] = MermaidSourcePolicy::digest($body);
    }

    if ($dryRun) {
        echo $topicKey . ': ' . ($topic === null ? 'would create' : 'exists id=' . $topic['id']) . "\n";
        foreach ($bodies as $locale => $body) {
            $existing = $topic === null ? null : $service->findAdminArticle((int) $topic['id'], $locale);
            echo '  ' . $locale . ': ' . ($existing === null ? 'would create' : 'would update id=' . $existing['id'])
                . ', ' . strlen($body) . " bytes\n";
        }
        continue;
    }

    $topicId = $service->saveTopic([
        'id' => $topic['id'] ?? 0,
        'topic_key' => $topicKey,
        'is_pinned' => (string) ($topic['is_pinned'] ?? $definition['is_pinned']),
        'sort_order' => (int) ($topic['sort_order'] ?? $definition['sort_order']),
    ]);

    foreach ($definition['articles'] as $locale => $article) {
        $existing = $service->findAdminArticle($topicId, $locale);
        $articleId = $service->saveArticle([
            'id' => $existing['id'] ?? 0,
            'topic_id' => $topicId,
            'locale' => $locale,
            'status' => 'published',
            'title' => $article['title'],
            'excerpt' => $article['excerpt'],
            'body_html' => $bodies[$locale],
            'mermaid_validation_digest' => $digests[$locale],
        ], $authorId);
        echo $topicKey . ' ' . $locale . ': ' . ($existing === null ? 'created' : 'updated') . ' article ' . $articleId . "\n";
    }

    echo 'topic ' . $topicKey . ' id=' . $topicId . " published\n";
}
