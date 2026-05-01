<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use InvalidArgumentException;
use Leikwan\Master\Database\Database;

final class KnowledgebaseService
{
    public function __construct(private Database $db)
    {
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
