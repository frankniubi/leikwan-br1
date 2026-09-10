<?php
declare(strict_types=1);

// Textual MySQL schema contract test. This intentionally does not assert SQLite portability.

$migrationPath = __DIR__ . '/../migrations/20260729_add_multilingual_knowledgebase.sql';
if (!is_file($migrationPath)) {
    fwrite(STDERR, 'FAIL: missing migration 20260729_add_multilingual_knowledgebase.sql' . PHP_EOL);
    exit(1);
}

$migration = file_get_contents($migrationPath) ?: '';
$schema = file_get_contents(__DIR__ . '/../migrations/schema.sql') ?: '';
$failures = 0;

function failKnowledgebaseSchema(string $message, int &$failures): void
{
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    $failures++;
}

function knowledgebaseTableDefinition(string $sql, string $table, string $source, int &$failures): string
{
    $pattern = '/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?'
        . preg_quote($table, '/')
        . '`?\s*\((.*?)\)\s*ENGINE\s*=\s*InnoDB\s+DEFAULT\s+CHARSET\s*=\s*utf8mb4\s*;/is';
    if (preg_match($pattern, $sql, $match) !== 1) {
        failKnowledgebaseSchema($source . ' missing MySQL InnoDB/utf8mb4 table ' . $table, $failures);
        return '';
    }

    return $match[1];
}

function requireKnowledgebaseSnippets(array $snippets, string $haystack, string $where, int &$failures): void
{
    foreach ($snippets as $snippet) {
        if (!str_contains($haystack, $snippet)) {
            failKnowledgebaseSchema($where . ' missing ' . $snippet, $failures);
        }
    }
}

foreach (['migration' => $migration, 'schema.sql' => $schema] as $source => $sql) {
    $topics = knowledgebaseTableDefinition($sql, 'knowledgebase_topics', $source, $failures);
    requireKnowledgebaseSnippets([
        'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
        'topic_key VARCHAR(190) NOT NULL',
        'is_pinned TINYINT(1) NOT NULL DEFAULT 0',
        'sort_order INT NOT NULL DEFAULT 100',
        'created_at DATETIME NOT NULL',
        'updated_at DATETIME NOT NULL',
        'UNIQUE KEY uniq_knowledgebase_topics_key (topic_key)',
        'KEY idx_knowledgebase_topics_reader (is_pinned, sort_order, id)',
    ], $topics, $source . ' knowledgebase_topics', $failures);

    $articles = knowledgebaseTableDefinition($sql, 'knowledgebase_articles', $source, $failures);
    requireKnowledgebaseSnippets([
        'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
        'topic_id BIGINT UNSIGNED NOT NULL',
        'locale VARCHAR(16) NOT NULL',
        'author_user_id BIGINT UNSIGNED NULL',
        'title VARCHAR(190) NOT NULL',
        'excerpt TEXT NULL',
        'body_html MEDIUMTEXT NOT NULL',
        "status VARCHAR(32) NOT NULL DEFAULT 'draft'",
        'published_at DATETIME NULL',
        'created_at DATETIME NOT NULL',
        'updated_at DATETIME NOT NULL',
        'CONSTRAINT fk_knowledgebase_articles_topic FOREIGN KEY (topic_id) REFERENCES knowledgebase_topics(id)',
        'CONSTRAINT fk_knowledgebase_articles_author FOREIGN KEY (author_user_id) REFERENCES users(id)',
        'UNIQUE KEY uniq_knowledgebase_article_locale (topic_id, locale)',
        'KEY idx_knowledgebase_articles_reader (locale, status, topic_id)',
        'KEY idx_knowledgebase_articles_updated (updated_at)',
    ], $articles, $source . ' knowledgebase_articles', $failures);

    if (preg_match('/\b(?:DROP|ALTER)\s+TABLE(?:\s+IF\s+EXISTS)?\s+`?knowledgebase_threads`?\b/i', $sql) === 1) {
        failKnowledgebaseSchema($source . ' must not drop or alter legacy knowledgebase_threads', $failures);
    }
}

if ($failures > 0) {
    fwrite(STDERR, $failures . ' FAIL' . PHP_EOL);
    exit(1);
}

echo 'ok' . PHP_EOL;
