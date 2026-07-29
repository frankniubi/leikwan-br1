<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use InvalidArgumentException;
use Leikwan\Master\Database\Database;

final class KnowledgebaseService
{
    private const RESOLVED_TOPIC_SELECT =
        "SELECT t.id AS topic_id,
                t.topic_key,
                t.is_pinned,
                t.sort_order,
                CASE WHEN requested.id IS NOT NULL THEN requested.title ELSE fallback.title END AS title,
                CASE WHEN requested.id IS NOT NULL THEN requested.excerpt ELSE fallback.excerpt END AS excerpt,
                CASE WHEN requested.id IS NOT NULL THEN requested.body_html ELSE fallback.body_html END AS body_html,
                CASE WHEN requested.id IS NOT NULL THEN requested.locale ELSE fallback.locale END AS locale_resolved,
                CASE WHEN requested.id IS NULL AND fallback.id IS NOT NULL THEN 1 ELSE 0 END AS used_fallback,
                CASE WHEN requested.id IS NOT NULL THEN requested.updated_at ELSE fallback.updated_at END AS updated_at
         FROM knowledgebase_topics t
         LEFT JOIN knowledgebase_articles requested
                ON requested.topic_id = t.id
               AND requested.locale = :locale
               AND requested.status = 'published'
         LEFT JOIN knowledgebase_articles fallback
                ON fallback.topic_id = t.id
               AND fallback.locale = 'en'
               AND fallback.status = 'published'
         WHERE (requested.id IS NOT NULL OR fallback.id IS NOT NULL)";

    public function __construct(private Database $db)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPublishedTopics(string $locale): array
    {
        $locale = LocaleResolver::normalize($locale) ?? LocaleResolver::DEFAULT_LOCALE;
        $rows = $this->db->fetchAll(
            self::RESOLVED_TOPIC_SELECT
            . ' ORDER BY t.is_pinned DESC, t.sort_order ASC, t.id ASC',
            ['locale' => $locale]
        );

        return array_map($this->normalizeResolvedTopic(...), $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPublishedTopicByKey(string $topicKey, string $locale): ?array
    {
        $topicKey = trim($topicKey);
        if ($topicKey === '') {
            return null;
        }

        $locale = LocaleResolver::normalize($locale) ?? LocaleResolver::DEFAULT_LOCALE;
        $row = $this->db->fetchOne(
            self::RESOLVED_TOPIC_SELECT
            . ' AND t.topic_key = :topic_key LIMIT 1',
            ['locale' => $locale, 'topic_key' => $topicKey]
        );

        return $row === null ? null : $this->normalizeResolvedTopic($row);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAdminTopics(): array
    {
        return $this->db->fetchAll(
            "SELECT t.id,
                    t.topic_key,
                    t.is_pinned,
                    t.sort_order,
                    t.created_at,
                    t.updated_at,
                    en.title AS en_title,
                    en.status AS en_status,
                    zh_hans.title AS zh_hans_title,
                    zh_hans.status AS zh_hans_status,
                    zh_tw.title AS zh_tw_title,
                    zh_tw.status AS zh_tw_status
             FROM knowledgebase_topics t
             LEFT JOIN knowledgebase_articles en
                    ON en.topic_id = t.id AND en.locale = 'en'
             LEFT JOIN knowledgebase_articles zh_hans
                    ON zh_hans.topic_id = t.id AND zh_hans.locale = 'zh-Hans'
             LEFT JOIN knowledgebase_articles zh_tw
                    ON zh_tw.topic_id = t.id AND zh_tw.locale = 'zh-TW'
             ORDER BY t.is_pinned DESC, t.sort_order ASC, t.id ASC"
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findAdminTopicById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        return $this->db->fetchOne(
            'SELECT * FROM knowledgebase_topics WHERE id = :id LIMIT 1',
            ['id' => $id]
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function saveTopic(array $payload): int
    {
        $id = (int) ($payload['id'] ?? 0);
        $existing = $id > 0 ? $this->findAdminTopicById($id) : null;
        if ($id > 0 && $existing === null) {
            throw new InvalidArgumentException('Knowledgebase topic not found.');
        }

        $topicKey = trim((string) ($payload['topic_key'] ?? ''));
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $topicKey) !== 1) {
            throw new InvalidArgumentException('Topic key must use lowercase letters, numbers, and single hyphens.');
        }

        $duplicate = $this->db->fetchOne(
            'SELECT id FROM knowledgebase_topics WHERE topic_key = :topic_key'
                . ($id > 0 ? ' AND id <> :id' : '')
                . ' LIMIT 1',
            $id > 0 ? ['topic_key' => $topicKey, 'id' => $id] : ['topic_key' => $topicKey]
        );
        if ($duplicate !== null) {
            throw new InvalidArgumentException('This topic key already exists.');
        }

        $isPinned = $this->truthy($payload['is_pinned'] ?? '0') ? 1 : 0;
        $sortOrder = (int) ($payload['sort_order'] ?? 100);
        $now = gmdate('Y-m-d H:i:s');
        if ($existing === null) {
            return $this->db->insert('knowledgebase_topics', [
                'topic_key' => $topicKey,
                'is_pinned' => $isPinned,
                'sort_order' => $sortOrder,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->db->execute(
            'UPDATE knowledgebase_topics
             SET topic_key = :topic_key,
                 is_pinned = :is_pinned,
                 sort_order = :sort_order,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'id' => $id,
                'topic_key' => $topicKey,
                'is_pinned' => $isPinned,
                'sort_order' => $sortOrder,
                'updated_at' => $now,
            ]
        );

        return $id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findAdminArticle(int $topicId, string $locale): ?array
    {
        $locale = LocaleResolver::normalize($locale);
        if ($topicId <= 0 || $locale === null) {
            return null;
        }

        return $this->db->fetchOne(
            'SELECT * FROM knowledgebase_articles WHERE topic_id = :topic_id AND locale = :locale LIMIT 1',
            ['topic_id' => $topicId, 'locale' => $locale]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findAdminArticleById(int $articleId): ?array
    {
        if ($articleId <= 0) {
            return null;
        }

        return $this->db->fetchOne(
            'SELECT * FROM knowledgebase_articles WHERE id = :id LIMIT 1',
            ['id' => $articleId]
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function saveArticle(array $payload, int $authorUserId): int
    {
        $locale = LocaleResolver::normalize((string) ($payload['locale'] ?? ''));
        if ($locale === null) {
            throw new InvalidArgumentException('Unsupported knowledgebase locale.');
        }

        $topicId = (int) ($payload['topic_id'] ?? 0);
        if ($this->findAdminTopicById($topicId) === null) {
            throw new InvalidArgumentException('Knowledgebase topic not found.');
        }

        $status = trim((string) ($payload['status'] ?? 'draft'));
        if (!in_array($status, ['draft', 'published'], true)) {
            throw new InvalidArgumentException('Unsupported knowledgebase article status.');
        }

        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('Article title is required.');
        }

        $excerpt = trim((string) ($payload['excerpt'] ?? ''));
        $bodyHtml = (new KnowledgebaseHtmlSanitizer())->sanitize((string) ($payload['body_html'] ?? ''));
        if ($status === 'published') {
            if ($excerpt === '') {
                throw new InvalidArgumentException('Published article excerpt is required.');
            }
            $bodyText = trim((string) preg_replace('/\s+/u', ' ', strip_tags($bodyHtml)));
            if ($bodyText === '') {
                throw new InvalidArgumentException('Published article body is required.');
            }
            MermaidSourcePolicy::assertPublishable(
                $bodyHtml,
                (string) ($payload['mermaid_validation_digest'] ?? '')
            );
        }

        $id = (int) ($payload['id'] ?? 0);
        $existing = $id > 0 ? $this->findAdminArticleById($id) : null;
        if ($id > 0 && ($existing === null
            || (int) $existing['topic_id'] !== $topicId
            || (string) $existing['locale'] !== $locale)) {
            throw new InvalidArgumentException('Knowledgebase article not found.');
        }
        if ($id <= 0 && $this->findAdminArticle($topicId, $locale) !== null) {
            throw new InvalidArgumentException('This language version already exists.');
        }

        $now = gmdate('Y-m-d H:i:s');
        $firstPublishedAt = (string) ($existing['published_at'] ?? '');
        $publishedAt = $firstPublishedAt !== ''
            ? $firstPublishedAt
            : ($status === 'published' ? $now : null);
        if ($existing === null) {
            return $this->db->insert('knowledgebase_articles', [
                'topic_id' => $topicId,
                'locale' => $locale,
                'author_user_id' => $authorUserId > 0 ? $authorUserId : null,
                'title' => $title,
                'excerpt' => $excerpt,
                'body_html' => $bodyHtml,
                'status' => $status,
                'published_at' => $publishedAt,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->db->execute(
            'UPDATE knowledgebase_articles
             SET author_user_id = :author_user_id,
                 title = :title,
                 excerpt = :excerpt,
                 body_html = :body_html,
                 status = :status,
                 published_at = :published_at,
                 updated_at = :updated_at
             WHERE id = :id AND topic_id = :topic_id AND locale = :locale',
            [
                'id' => $id,
                'topic_id' => $topicId,
                'locale' => $locale,
                'author_user_id' => $authorUserId > 0 ? $authorUserId : null,
                'title' => $title,
                'excerpt' => $excerpt,
                'body_html' => $bodyHtml,
                'status' => $status,
                'published_at' => $publishedAt,
                'updated_at' => $now,
            ]
        );

        return $id;
    }

    public function deleteArticle(int $articleId): void
    {
        if ($this->findAdminArticleById($articleId) === null) {
            throw new InvalidArgumentException('Knowledgebase article not found.');
        }

        $this->db->execute('DELETE FROM knowledgebase_articles WHERE id = :id', ['id' => $articleId]);
    }

    public function deleteTopic(int $topicId): void
    {
        $this->db->transaction(function (Database $db) use ($topicId): void {
            $topic = $db->fetchOne(
                'SELECT id FROM knowledgebase_topics WHERE id = :id LIMIT 1',
                ['id' => $topicId]
            );
            if ($topic === null) {
                throw new InvalidArgumentException('Knowledgebase topic not found.');
            }
            $db->execute(
                'DELETE FROM knowledgebase_articles WHERE topic_id = :topic_id',
                ['topic_id' => $topicId]
            );
            $db->execute('DELETE FROM knowledgebase_topics WHERE id = :id', ['id' => $topicId]);
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPublishedThreads(): array
    {
        return $this->db->fetchAll(
            "SELECT id, title, slug, excerpt, body_html, status, is_pinned, sort_order, published_at, created_at, updated_at
             FROM knowledgebase_threads
             WHERE status = 'published'
             ORDER BY is_pinned DESC, sort_order ASC, COALESCE(published_at, updated_at) DESC, id DESC"
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAdminThreads(): array
    {
        return $this->db->fetchAll(
            "SELECT id, title, slug, excerpt, body_html, status, is_pinned, sort_order, published_at, created_at, updated_at
             FROM knowledgebase_threads
             ORDER BY CASE WHEN status = 'published' THEN 0 ELSE 1 END, is_pinned DESC, sort_order ASC, COALESCE(published_at, updated_at) DESC, id DESC"
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPublishedThreadBySlug(string $slug): ?array
    {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }

        return $this->db->fetchOne(
            "SELECT id, title, slug, excerpt, body_html, status, is_pinned, sort_order, published_at, created_at, updated_at
             FROM knowledgebase_threads
             WHERE slug = :slug AND status = 'published'
             LIMIT 1",
            ['slug' => $slug]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findAdminThreadById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        return $this->db->fetchOne(
            'SELECT * FROM knowledgebase_threads WHERE id = :id LIMIT 1',
            ['id' => $id]
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function saveThread(array $payload, int $authorUserId): int
    {
        $id = (int) ($payload['id'] ?? 0);
        $existing = $id > 0 ? $this->findAdminThreadById($id) : null;
        if ($id > 0 && $existing === null) {
            throw new InvalidArgumentException('Knowledgebase thread not found.');
        }

        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('Thread title is required.');
        }

        $bodyHtml = trim((string) ($payload['body_html'] ?? ''));
        $bodyText = trim((string) preg_replace('/\s+/', ' ', strip_tags($bodyHtml)));
        if ($bodyText === '') {
            throw new InvalidArgumentException('Thread body is required.');
        }

        $status = trim((string) ($payload['status'] ?? 'draft'));
        if (!in_array($status, ['draft', 'published'], true)) {
            $status = 'draft';
        }

        $slug = $this->buildUniqueSlug(
            trim((string) ($payload['slug'] ?? '')),
            $title,
            $id > 0 ? $id : null
        );
        $excerpt = $this->buildExcerpt(trim((string) ($payload['excerpt'] ?? '')), $bodyText);
        $isPinned = $this->truthy($payload['is_pinned'] ?? '0') ? 1 : 0;
        $sortOrder = (int) ($payload['sort_order'] ?? 100);
        $now = gmdate('Y-m-d H:i:s');
        $publishedAt = $status === 'published'
            ? (string) (($existing['published_at'] ?? '') ?: $now)
            : null;

        if ($existing === null) {
            return $this->db->insert('knowledgebase_threads', [
                'author_user_id' => $authorUserId > 0 ? $authorUserId : null,
                'title' => $title,
                'slug' => $slug,
                'excerpt' => $excerpt,
                'body_html' => $bodyHtml,
                'status' => $status,
                'is_pinned' => $isPinned,
                'sort_order' => $sortOrder,
                'published_at' => $publishedAt,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->db->execute(
            'UPDATE knowledgebase_threads
             SET author_user_id = :author_user_id,
                 title = :title,
                 slug = :slug,
                 excerpt = :excerpt,
                 body_html = :body_html,
                 status = :status,
                 is_pinned = :is_pinned,
                 sort_order = :sort_order,
                 published_at = :published_at,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'id' => $id,
                'author_user_id' => $authorUserId > 0 ? $authorUserId : null,
                'title' => $title,
                'slug' => $slug,
                'excerpt' => $excerpt,
                'body_html' => $bodyHtml,
                'status' => $status,
                'is_pinned' => $isPinned,
                'sort_order' => $sortOrder,
                'published_at' => $publishedAt,
                'updated_at' => $now,
            ]
        );

        return $id;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeResolvedTopic(array $row): array
    {
        $row['used_fallback'] = (bool) $row['used_fallback'];

        return $row;
    }

    private function buildUniqueSlug(string $requestedSlug, string $title, ?int $ignoreId = null): string
    {
        $base = $this->normalizeSlug($requestedSlug !== '' ? $requestedSlug : $title);
        $candidate = $base;
        $suffix = 2;

        while ($this->slugExists($candidate, $ignoreId)) {
            $candidate = $base . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function normalizeSlug(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return 'thread';
        }

        $ascii = function_exists('iconv')
            ? (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value)
            : $value;
        $ascii = strtolower($ascii);
        $ascii = preg_replace('/[^a-z0-9]+/', '-', $ascii) ?? '';
        $ascii = trim($ascii, '-');

        return $ascii !== '' ? $ascii : 'thread';
    }

    private function slugExists(string $slug, ?int $ignoreId = null): bool
    {
        $sql = 'SELECT id FROM knowledgebase_threads WHERE slug = :slug';
        $params = ['slug' => $slug];
        if ($ignoreId !== null && $ignoreId > 0) {
            $sql .= ' AND id <> :ignore_id';
            $params['ignore_id'] = $ignoreId;
        }
        $sql .= ' LIMIT 1';

        return $this->db->fetchOne($sql, $params) !== null;
    }

    private function buildExcerpt(string $excerpt, string $bodyText): string
    {
        if ($excerpt !== '') {
            return $this->limit($excerpt, 180);
        }

        return $this->limit($bodyText, 180);
    }

    private function limit(string $value, int $limit): string
    {
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($value) <= $limit ? $value : rtrim(mb_substr($value, 0, $limit - 1)) . '?';
        }

        return strlen($value) <= $limit ? $value : rtrim(substr($value, 0, $limit - 1)) . '?';
    }

    private function truthy(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
