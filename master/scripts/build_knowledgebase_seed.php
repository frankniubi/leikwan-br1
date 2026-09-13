<?php
declare(strict_types=1);

const EXPECTED_TOPIC_COUNT = 7;

/** @return never */
function failSeed(string $message): never
{
    fwrite(STDERR, 'knowledgebase seed: ' . $message . PHP_EOL);
    exit(1);
}

function sqlString(string $value): string
{
    return "'" . str_replace("'", "''", $value) . "'";
}

/** @return array<string, array<string, mixed>> */
function loadCatalog(string $manifestPath, string $contentRoot): array
{
    if (!is_file($manifestPath) || !is_readable($manifestPath)) {
        failSeed('topic manifest is missing or unreadable');
    }

    $topics = require $manifestPath;
    if (!is_array($topics) || count($topics) !== EXPECTED_TOPIC_COUNT) {
        failSeed('generation requires exactly 7 topics');
    }

    $requiredLocales = ['en', 'zh-Hans', 'zh-TW'];
    foreach ($topics as $topicKey => $topic) {
        if (!is_string($topicKey) || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $topicKey) !== 1) {
            failSeed('invalid topic key');
        }
        if (!is_array($topic)
            || !isset($topic['is_pinned'], $topic['sort_order'], $topic['articles'])
            || !is_int($topic['is_pinned'])
            || !in_array($topic['is_pinned'], [0, 1], true)
            || !is_int($topic['sort_order'])
            || !is_array($topic['articles'])) {
            failSeed($topicKey . ': invalid topic metadata');
        }

        foreach ($requiredLocales as $locale) {
            $article = $topic['articles'][$locale] ?? null;
            if (!is_array($article)
                || !isset($article['title'], $article['excerpt'], $article['body'])
                || !is_string($article['title'])
                || trim($article['title']) === ''
                || !is_string($article['excerpt'])
                || trim($article['excerpt']) === ''
                || !is_string($article['body'])
                || $article['body'] !== $locale . '/' . $topicKey . '.html') {
                failSeed($topicKey . ': all required locale metadata and body files must exist for ' . $locale);
            }

            $bodyPath = $contentRoot . '/' . $article['body'];
            if (!is_file($bodyPath) || !is_readable($bodyPath)) {
                failSeed($topicKey . ': all required locale metadata and body files must be readable for ' . $locale);
            }
            $body = file_get_contents($bodyPath);
            if (!is_string($body) || trim($body) === '' || preg_match('//u', $body) !== 1) {
                failSeed($topicKey . ': body must be non-empty valid UTF-8 for ' . $locale);
            }
        }
    }

    return $topics;
}

/** @param array<string, array<string, mixed>> $topics */
function buildSql(array $topics, string $contentRoot): string
{
    $statements = [];
    $statements[] = <<<'SQL'
-- Deterministic multilingual knowledgebase seed.
-- Existing topics and localized articles always win.
INSERT INTO knowledgebase_topics (
    topic_key, is_pinned, sort_order, created_at, updated_at
)
SELECT
    legacy.slug, legacy.is_pinned, legacy.sort_order, legacy.created_at, legacy.updated_at
FROM knowledgebase_threads AS legacy
WHERE NOT EXISTS (
    SELECT 1
    FROM knowledgebase_topics AS existing_topic
    WHERE existing_topic.topic_key COLLATE utf8mb4_general_ci = legacy.slug COLLATE utf8mb4_general_ci
);
SQL;

    $statements[] = <<<'SQL'
INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'en', legacy.author_user_id, legacy.title, legacy.excerpt, legacy.body_html,
    legacy.status, legacy.published_at, legacy.created_at, legacy.updated_at
FROM knowledgebase_threads AS legacy
INNER JOIN knowledgebase_topics AS topic
    ON topic.topic_key COLLATE utf8mb4_general_ci = legacy.slug COLLATE utf8mb4_general_ci
WHERE legacy.slug NOT IN ('getting-started-first-forward-checklist', 'connection-modes-single-race-bond', 'bond-sidecar-setup', 'troubleshooting-common-mistakes')
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'en'
  );
SQL;

    foreach ($topics as $topicKey => $topic) {
        $statements[] = sprintf(
            <<<'SQL'
INSERT INTO knowledgebase_topics (
    topic_key, is_pinned, sort_order, created_at, updated_at
)
SELECT
    %s, %d, %d, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
WHERE NOT EXISTS (
    SELECT 1
    FROM knowledgebase_topics AS existing_topic
    WHERE existing_topic.topic_key = %s
);
SQL,
            sqlString($topicKey),
            $topic['is_pinned'],
            $topic['sort_order'],
            sqlString($topicKey),
        );
    }

    $requiredLocales = ['en', 'zh-Hans', 'zh-TW'];
    foreach ($requiredLocales as $locale) {
        foreach ($topics as $topicKey => $topic) {
            /** @var array{title: string, excerpt: string, body: string} $article */
            $article = $topic['articles'][$locale];
            $body = file_get_contents($contentRoot . '/' . $article['body']);
            if (!is_string($body)) {
                failSeed($topicKey . ': failed to read validated body for ' . $locale);
            }

            $statements[] = sprintf(
                <<<'SQL'
INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, %s, NULL, %s, %s, %s,
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = %s
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = %s
  );
SQL,
                sqlString($locale),
                sqlString($article['title']),
                sqlString($article['excerpt']),
                sqlString($body),
                sqlString($topicKey),
                sqlString($locale),
            );
        }
    }

    return implode("\n\n", $statements) . "\n";
}

$projectRoot = dirname(__DIR__);
$contentRoot = $projectRoot . '/resources/knowledgebase';
$manifestPath = $contentRoot . '/topics.php';
$outputPath = $projectRoot . '/migrations/20260729_seed_multilingual_knowledgebase.sql';

foreach (array_slice($argv, 1) as $argument) {
    if (!str_starts_with($argument, '--output=') || strlen($argument) === strlen('--output=')) {
        failSeed('usage: php build_knowledgebase_seed.php [--output=<path>]');
    }
    $outputPath = substr($argument, strlen('--output='));
}

$topics = loadCatalog($manifestPath, $contentRoot);
$sql = buildSql($topics, $contentRoot);
$outputDirectory = dirname($outputPath);
if (!is_dir($outputDirectory) || !is_writable($outputDirectory)) {
    failSeed('output directory is missing or not writable: ' . $outputDirectory);
}
if (file_put_contents($outputPath, $sql, LOCK_EX) !== strlen($sql)) {
    failSeed('failed to write output: ' . $outputPath);
}

fwrite(STDOUT, $outputPath . PHP_EOL);
