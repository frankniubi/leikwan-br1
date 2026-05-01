# Threaded Knowledgebase Page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the current static knowledgebase with a brand-new thread-based knowledgebase page, let admins add/edit threads from the admin panel, let tenants read published threads, support TinyMCE rich-text authoring with paste-to-upload images, and ship a starter catalog of default threads so the feature is useful on day one.

**Architecture:** Keep `/knowledgebase` as the shared signed-in reader surface inside `AuthController`, add admin authoring routes under `/admin/knowledgebase*` inside `AdminController`, and move thread persistence/query logic into a dedicated `KnowledgebaseService`. Store thread bodies as trusted admin-authored HTML in a new `knowledgebase_threads` table seeded by migration, and save pasted editor images under `master/public/uploads/knowledgebase/YYYY/MM/` with server-generated filenames that TinyMCE inserts back into the thread body.

**Tech Stack:** PHP controllers, custom router, MySQL migration SQL, TinyMCE CDN script, filesystem image uploads, source-string regression tests, lightweight SQLite behavior tests, existing locale JSON packs

---

## What already exists

- `master/src/Controllers/AuthController.php:471-741` currently renders `/knowledgebase` as one large static marketing/documentation page.
- `master/src/Controllers/BaseController.php:1046-1063` already exposes the shared admin nav item for `/knowledgebase`.
- `master/src/Controllers/TenantController.php:1620-1636` still links to the old anchor-based sections (`#quick-start`, `#advanced-options`, `#usage-patterns`).
- `master/src/Controllers/AdminController.php:1919-1939` and `4241-4266` already demonstrate the house style for CSRF-protected admin forms and raw upload handling via `$_FILES`.
- `master/src/Http/Request.php` already hydrates multipart form posts into `$_POST`, so TinyMCE uploads can submit `csrf_token` as normal form-data.

## NOT in scope

- Public anonymous knowledgebase access
- Tenant-authored threads, comments, reactions, or per-thread permissions
- Full revision history / version diff UI
- Auto-cleanup of unused uploaded images
- A third-party HTML sanitization library or a full markdown pipeline
- Categories, tags, full-text search, or pagination

## File map

- Modify: `master/src/Controllers/AuthController.php`
- Modify: `master/src/Controllers/AdminController.php`
- Modify: `master/src/Controllers/TenantController.php`
- Modify: `master/src/Bootstrap.php`
- Modify: `master/tests/i18n_locale_regression_test.php`
- Modify: `master/public/i18n/zh-TW.json`
- Modify: `master/public/i18n/zh-Hans.json`
- Add: `master/src/Support/KnowledgebaseService.php`
- Add: `master/migrations/20260424_add_knowledgebase_threads.sql`
- Add: `master/tests/knowledgebase_service_test.php`
- Add: `master/tests/knowledgebase_seed_regression_test.php`
- Add: `master/tests/knowledgebase_reader_regression_test.php`
- Add: `master/tests/admin_knowledgebase_regression_test.php`

## Data shape to implement

`knowledgebase_threads` should be the only new persistent table in this pass:

- `id`
- `author_user_id` (`NULL` allowed for migration-seeded starter threads)
- `title`
- `slug` (unique, used in `?thread=<slug>` links)
- `excerpt`
- `body_html`
- `status` (`draft` or `published`)
- `is_pinned`
- `sort_order`
- `published_at`
- `created_at`
- `updated_at`

The four migration-seeded starter threads should be:

1. `getting-started-first-forward-checklist`
2. `connection-modes-single-race-bond`
3. `bond-sidecar-setup`
4. `troubleshooting-common-mistakes`

---

### Task 1: Add knowledgebase storage primitives and day-one starter threads

**Files:**
- Add: `master/src/Support/KnowledgebaseService.php`
- Add: `master/migrations/20260424_add_knowledgebase_threads.sql`
- Add: `master/tests/knowledgebase_service_test.php`
- Add: `master/tests/knowledgebase_seed_regression_test.php`

- [ ] **Step 1: Write the failing storage behavior test**

Create `master/tests/knowledgebase_service_test.php`:

```php
<?php
declare(strict_types=1);

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Support\KnowledgebaseService;

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Database/Database.php';
require_once __DIR__ . '/../src/Support/KnowledgebaseService.php';

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
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

$service = new KnowledgebaseService($db);
$firstId = $service->saveThread([
    'title' => 'Getting Started: First Forward Checklist',
    'excerpt' => '',
    'body_html' => '<p>Start with one healthy slave group, one route package, and one backend target.</p>',
    'status' => 'published',
    'is_pinned' => '1',
    'sort_order' => '10',
], 1);
$secondId = $service->saveThread([
    'title' => 'Getting Started: First Forward Checklist',
    'excerpt' => '',
    'body_html' => '<p>This second thread forces slug uniquing.</p>',
    'status' => 'published',
    'is_pinned' => '0',
    'sort_order' => '20',
], 1);
$draftId = $service->saveThread([
    'title' => 'Draft Only Thread',
    'excerpt' => 'admin only draft',
    'body_html' => '<p>Hidden from tenants.</p>',
    'status' => 'draft',
    'is_pinned' => '0',
    'sort_order' => '999',
], 1);

$published = $service->listPublishedThreads();
if (count($published) !== 2) {
    fail('expected exactly two published threads');
}
if ((int) $published[0]['id'] !== $firstId || (string) $published[0]['slug'] !== 'getting-started-first-forward-checklist') {
    fail('expected the pinned published thread to sort first');
}
if ((int) $published[1]['id'] !== $secondId || (string) $published[1]['slug'] !== 'getting-started-first-forward-checklist-2') {
    fail('expected duplicate titles to get a stable unique slug');
}
if (($service->findPublishedThreadBySlug('draft-only-thread')) !== null) {
    fail('draft threads must not be visible from the reader lookup');
}
if (trim((string) ($published[0]['excerpt'] ?? '')) === '') {
    fail('expected saveThread() to derive an excerpt when excerpt is blank');
}

$adminThreads = $service->listAdminThreads();
if (count($adminThreads) !== 3) {
    fail('expected admin listing to include published and draft threads');
}
$lastThread = $adminThreads[count($adminThreads) - 1] ?? null;
if (!is_array($lastThread) || (int) ($lastThread['id'] ?? 0) !== $draftId || (string) ($lastThread['status'] ?? '') !== 'draft') {
    fail('expected drafts to sort after published threads in the admin listing');
}

echo "ok\n";
```

- [ ] **Step 2: Write the failing seed regression test**

Create `master/tests/knowledgebase_seed_regression_test.php`:

```php
<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../migrations/20260424_add_knowledgebase_threads.sql');
if (!is_string($source) || $source === '') {
    fail('failed to read knowledgebase migration');
}

foreach ([
    'CREATE TABLE IF NOT EXISTS knowledgebase_threads',
    'Getting Started: First Forward Checklist',
    'Connection Modes: Single, Race, Bond',
    'Bond Sidecar Setup',
    'Troubleshooting Common Mistakes',
    'getting-started-first-forward-checklist',
    'connection-modes-single-race-bond',
    'bond-sidecar-setup',
    'troubleshooting-common-mistakes',
] as $required) {
    if (!str_contains($source, $required)) {
        fail('missing knowledgebase migration snippet: ' . $required);
    }
}

echo "ok\n";
```

- [ ] **Step 3: Run the new tests and verify they fail**

Run: `php master/tests/knowledgebase_service_test.php`  
Expected: FAIL because `KnowledgebaseService.php` does not exist yet.

Run: `php master/tests/knowledgebase_seed_regression_test.php`  
Expected: FAIL because the migration file does not exist yet.

- [ ] **Step 4: Add the migration and default starter catalog**

Create `master/migrations/20260424_add_knowledgebase_threads.sql`:

```sql
CREATE TABLE IF NOT EXISTS knowledgebase_threads (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    author_user_id BIGINT UNSIGNED NULL,
    title VARCHAR(191) NOT NULL,
    slug VARCHAR(191) NOT NULL UNIQUE,
    excerpt VARCHAR(255) NULL,
    body_html MEDIUMTEXT NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'draft',
    is_pinned TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 100,
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_knowledgebase_threads_author FOREIGN KEY (author_user_id) REFERENCES users(id),
    INDEX idx_knowledgebase_threads_reader (status, is_pinned, sort_order, published_at),
    INDEX idx_knowledgebase_threads_updated (updated_at)
);

INSERT INTO knowledgebase_threads (
    author_user_id,
    title,
    slug,
    excerpt,
    body_html,
    status,
    is_pinned,
    sort_order,
    published_at,
    created_at,
    updated_at
) VALUES
(
    NULL,
    'Getting Started: First Forward Checklist',
    'getting-started-first-forward-checklist',
    'The shortest path from a fresh service to a working first forward.',
    '<h2>Start simple</h2><p>Use one healthy slave group, one published route package, and one backend target before touching advanced topology.</p><ol><li>Make sure the service is active and already bound to a route package.</li><li>Create one forward and point it at the backend host and port you actually want to expose.</li><li>Smoke-test the path before changing connection mode.</li></ol><p><strong>Default recommendation:</strong> Slave Group + Single + TCP.</p>',
    'published',
    1,
    10,
    '2026-04-24 00:00:00',
    '2026-04-24 00:00:00',
    '2026-04-24 00:00:00'
),
(
    NULL,
    'Connection Modes: Single, Race, Bond',
    'connection-modes-single-race-bond',
    'When to stay simple, when to use redundancy, and when Bond actually matters.',
    '<h2>Choose the right mode</h2><ul><li><strong>Single</strong>: the safest default for first delivery.</li><li><strong>Race</strong>: multiple healthy paths for redundancy and per-connection spread.</li><li><strong>Bond</strong>: use only when you already have a sidecar and want true multi-leg aggregation.</li></ul><p>Race improves resilience. Bond changes topology and needs a sidecar. Do not treat them as the same feature.</p>',
    'published',
    1,
    20,
    '2026-04-24 00:00:00',
    '2026-04-24 00:00:00',
    '2026-04-24 00:00:00'
),
(
    NULL,
    'Bond Sidecar Setup',
    'bond-sidecar-setup',
    'A practical checklist for creating a sidecar point and wiring a Bond forward around it.',
    '<h2>Bond requires a sidecar</h2><ol><li>Create or confirm a sidecar point in the panel.</li><li>Install the sidecar with the panel-generated command on the C-side host.</li><li>Create the Bond forward and select that sidecar point.</li><li>Point the backend target at the service that lives behind the sidecar, often <code>127.0.0.1:&lt;port&gt;</code>.</li></ol><p>If the sidecar is not healthy, stop and fix that first before changing tenant forwards.</p>',
    'published',
    0,
    30,
    '2026-04-24 00:00:00',
    '2026-04-24 00:00:00',
    '2026-04-24 00:00:00'
),
(
    NULL,
    'Troubleshooting Common Mistakes',
    'troubleshooting-common-mistakes',
    'Fast checks for the panel states that most often explain a non-working forward.',
    '<h2>Check the obvious first</h2><ul><li>Confirm the service is active and not suspended.</li><li>Confirm the route package is still published on the plan.</li><li>Confirm the slave group or sidecar point is online.</li><li>Confirm the backend host and port are reachable from the machine that will terminate the traffic.</li></ul><p>When in doubt, roll back to the simplest working topology and reintroduce complexity one step at a time.</p>',
    'published',
    0,
    40,
    '2026-04-24 00:00:00',
    '2026-04-24 00:00:00',
    '2026-04-24 00:00:00'
);
```

- [ ] **Step 5: Implement the thread query/save service**

Create `master/src/Support/KnowledgebaseService.php`:

```php
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
            return mb_strlen($value) <= $limit ? $value : rtrim(mb_substr($value, 0, $limit - 1)) . '…';
        }

        return strlen($value) <= $limit ? $value : rtrim(substr($value, 0, $limit - 1)) . '…';
    }

    private function truthy(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
```

- [ ] **Step 6: Re-run the new tests and verify they pass**

Run: `php master/tests/knowledgebase_service_test.php`  
Expected: PASS

Run: `php master/tests/knowledgebase_seed_regression_test.php`  
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add master/src/Support/KnowledgebaseService.php master/migrations/20260424_add_knowledgebase_threads.sql master/tests/knowledgebase_service_test.php master/tests/knowledgebase_seed_regression_test.php
git commit -m "feat: add threaded knowledgebase storage foundation"
```

---

### Task 2: Replace the static knowledgebase page with a thread-based reader experience

**Files:**
- Modify: `master/src/Controllers/AuthController.php`
- Modify: `master/src/Controllers/TenantController.php`
- Add: `master/tests/knowledgebase_reader_regression_test.php`

- [ ] **Step 1: Write the failing reader regression test**

Create `master/tests/knowledgebase_reader_regression_test.php`:

```php
<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$auth = file_get_contents(__DIR__ . '/../src/Controllers/AuthController.php');
$tenant = file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');

if (!is_string($auth) || $auth === '') {
    fail('failed to read AuthController source');
}
if (!is_string($tenant) || $tenant === '') {
    fail('failed to read TenantController source');
}

foreach ([
    'new KnowledgebaseService($this->db)',
    'listPublishedThreads()',
    'findPublishedThreadBySlug',
    'Latest Threads',
    'No published threads yet.',
    'Open admin editor',
    '?thread=',
    'Knowledgebase / 知识库',
] as $required) {
    if (!str_contains($auth, $required)) {
        fail('missing threaded reader snippet: ' . $required);
    }
}

foreach ([
    '/knowledgebase?thread=getting-started-first-forward-checklist',
    '/knowledgebase?thread=connection-modes-single-race-bond',
    '/knowledgebase?thread=troubleshooting-common-mistakes',
] as $requiredTenantLink) {
    if (!str_contains($tenant, $requiredTenantLink)) {
        fail('missing updated tenant knowledgebase link: ' . $requiredTenantLink);
    }
}

foreach ([
    '#quick-start',
    '#advanced-options',
    '#usage-patterns',
    'The Default Path /',
    'Core Modes /',
    'Three Common Goals /',
] as $forbidden) {
    if (str_contains($auth . $tenant, $forbidden)) {
        fail('found stale static knowledgebase snippet: ' . $forbidden);
    }
}

echo "ok\n";
```

- [ ] **Step 2: Run the reader regression test and verify it fails**

Run: `php master/tests/knowledgebase_reader_regression_test.php`  
Expected: FAIL because `AuthController` still contains the old one-page static document and `TenantController` still points to anchor links.

- [ ] **Step 3: Rewrite `/knowledgebase` into a real thread reader page**

In `master/src/Controllers/AuthController.php`, add the import:

```php
use Leikwan\Master\Support\KnowledgebaseService;
```

Then replace `knowledgebasePage()` with this structure:

```php
public function knowledgebasePage(Request $request, array $params): Response
{
    $user = SessionAuth::currentUser($this->db);
    if ($user === null) {
        SessionAuth::flash('error', 'Please sign in to access the knowledgebase.');
        return Response::redirect('/login');
    }

    $service = new KnowledgebaseService($this->db);
    $threads = $service->listPublishedThreads();
    $selectedSlug = trim((string) $request->input('thread', ''));
    $selectedThread = $selectedSlug !== ''
        ? $service->findPublishedThreadBySlug($selectedSlug)
        : null;

    if ($selectedThread === null && $threads !== []) {
        $selectedThread = $threads[0];
        $selectedSlug = (string) ($selectedThread['slug'] ?? '');
    }

    $threadCards = '';
    foreach ($threads as $thread) {
        $isActive = (string) ($thread['slug'] ?? '') === $selectedSlug;
        $updatedAt = trim((string) ($thread['updated_at'] ?? ''));
        $threadCards .= '<a class="kb-thread-card' . ($isActive ? ' active' : '') . '" href="/knowledgebase?thread=' . urlencode((string) ($thread['slug'] ?? '')) . '">'
            . '<div class="kb-thread-meta">'
            . (((int) ($thread['is_pinned'] ?? 0) === 1) ? '<span class="kb-pill">Pinned</span>' : '')
            . ($updatedAt !== '' ? '<span class="kb-updated">Updated ' . $this->escape($updatedAt) . '</span>' : '')
            . '</div>'
            . '<strong>' . $this->escape((string) ($thread['title'] ?? 'Untitled')) . '</strong>'
            . '<p>' . $this->escape((string) ($thread['excerpt'] ?? '')) . '</p>'
            . '</a>';
    }

    $adminButton = (string) ($user['role'] ?? '') === 'admin'
        ? '<a class="button secondary" href="/admin/knowledgebase">Open admin editor</a>'
        : '';

    $articleHtml = $selectedThread !== null
        ? '<article class="kb-article">'
            . '<div class="kb-article-meta">'
            . (((int) ($selectedThread['is_pinned'] ?? 0) === 1) ? '<span class="kb-pill">Pinned</span>' : '')
            . '<span>Slug: ' . $this->escape((string) ($selectedThread['slug'] ?? '')) . '</span>'
            . '</div>'
            . '<h2>' . $this->escape((string) ($selectedThread['title'] ?? 'Knowledgebase')) . '</h2>'
            . '<div class="kb-article-body">' . (string) ($selectedThread['body_html'] ?? '') . '</div>'
            . '</article>'
        : '<section class="card"><h3>No published threads yet.</h3><p class="muted">Admins can create the first knowledgebase thread from the admin panel.</p></section>';

    $body = $this->knowledgebaseStyles() . '
        <section class="kb-shell">
          <section class="kb-hero">
            <div>
              <span class="kb-eyebrow">Knowledgebase / 知识库</span>
              <h2>Threaded operational docs for admins and tenants</h2>
              <p class="muted">Read the starter threads, open the exact topic you need, and keep future updates as normal panel-managed content instead of one giant static page.</p>
            </div>
            <div class="actions">
              ' . $adminButton . '
            </div>
          </section>
          <section class="kb-layout">
            <aside class="kb-sidebar">
              <h3>Latest Threads</h3>
              <div class="kb-thread-list">' . ($threadCards !== '' ? $threadCards : '<div class="card"><p class="muted">No published threads yet.</p></div>') . '</div>
            </aside>
            <div class="kb-content">' . $articleHtml . '</div>
          </section>
        </section>';

    return $this->page('Knowledgebase', $body, $user, $this->knowledgebaseNav($user));
}
```

Update `knowledgebaseNav()` so tenant readers get a clean real nav instead of placeholder strings:

```php
private function knowledgebaseNav(array $user): array
{
    if ((string) ($user['role'] ?? '') === 'admin') {
        return $this->adminNavigationItems('knowledgebase');
    }

    return [
        ['href' => '/tenant', 'label' => 'Intro / 介绍'],
        ['href' => '/tenant/account', 'label' => 'Account / 账户'],
        ['href' => '/tenant/plans', 'label' => 'Plans / 套餐'],
        ['href' => '/tenant/forwards', 'label' => 'Forwards / 转发配置'],
        ['href' => '/tenant/clients', 'label' => 'Clients / 接入点'],
        ['href' => '/tenant/sidecars', 'label' => 'Sidecars / 边车'],
        ['href' => '/tenant/slave-groups', 'label' => 'Slave Groups / Slave 组'],
        ['href' => '/knowledgebase', 'label' => 'Knowledgebase / 知识库', 'active' => true],
    ];
}
```

Replace `knowledgebaseStyles()` with a slimmer threaded layout such as:

```php
private function knowledgebaseStyles(): string
{
    return <<<'HTML'
<style>
  .kb-shell { display:flex; flex-direction:column; gap:20px; }
  .kb-hero,
  .kb-article,
  .kb-sidebar,
  .kb-thread-card {
    border-radius: 24px;
    border: 1px solid rgba(229,231,235,0.94);
    background: rgba(255,255,255,0.96);
    box-shadow: var(--shadow);
  }
  .kb-hero {
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:16px;
    padding:28px;
  }
  .kb-eyebrow,
  .kb-pill {
    display:inline-flex;
    align-items:center;
    padding:7px 11px;
    border-radius:999px;
    font-size:11px;
    font-weight:700;
    letter-spacing:0.12em;
    text-transform:uppercase;
  }
  .kb-eyebrow { background:#111827; color:#fff; }
  .kb-pill { background:#eef2ff; color:#3730a3; }
  .kb-layout {
    display:grid;
    grid-template-columns:minmax(280px, 340px) minmax(0, 1fr);
    gap:20px;
    align-items:start;
  }
  .kb-sidebar { padding:22px; }
  .kb-thread-list { display:flex; flex-direction:column; gap:12px; margin-top:14px; }
  .kb-thread-card {
    display:flex;
    flex-direction:column;
    gap:10px;
    padding:16px;
    color:inherit;
    text-decoration:none;
  }
  .kb-thread-card.active { border-color:#111827; transform:translateY(-1px); }
  .kb-thread-card p { margin:0; color:var(--muted); line-height:1.6; }
  .kb-thread-meta {
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    align-items:center;
    justify-content:space-between;
  }
  .kb-updated { color:var(--muted); font-size:12px; }
  .kb-content { display:flex; flex-direction:column; gap:20px; }
  .kb-article { padding:28px; }
  .kb-article-meta {
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    align-items:center;
    margin-bottom:14px;
    color:var(--muted);
    font-size:12px;
  }
  .kb-article-body { display:grid; gap:14px; line-height:1.72; }
  .kb-article-body h2,
  .kb-article-body h3,
  .kb-article-body p,
  .kb-article-body ul,
  .kb-article-body ol { margin:0; }
  @media (max-width: 980px) {
    .kb-layout { grid-template-columns: 1fr; }
  }
</style>
HTML;
}
```

- [ ] **Step 4: Update tenant deep-links to point at real thread slugs**

In `master/src/Controllers/TenantController.php`, replace the old anchor-based links with thread links:

```php
<a class="button ghost" href="/knowledgebase?thread=getting-started-first-forward-checklist">Knowledgebase</a>
```

```php
<p class="field-note">Need help? <a href="/knowledgebase?thread=getting-started-first-forward-checklist">Quick Start</a> · <a href="/knowledgebase?thread=connection-modes-single-race-bond">Connection Modes</a> · <a href="/knowledgebase?thread=troubleshooting-common-mistakes">Troubleshooting</a></p>
```

```php
<a class="button ghost" href="/knowledgebase?thread=getting-started-first-forward-checklist">Knowledgebase</a>
```

- [ ] **Step 5: Re-run the reader regression test and verify it passes**

Run: `php master/tests/knowledgebase_reader_regression_test.php`  
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add master/src/Controllers/AuthController.php master/src/Controllers/TenantController.php master/tests/knowledgebase_reader_regression_test.php
git commit -m "feat: turn knowledgebase into a threaded reader page"
```

---

### Task 3: Add admin knowledgebase management, TinyMCE authoring, and paste-to-upload images

**Files:**
- Modify: `master/src/Controllers/AdminController.php`
- Modify: `master/src/Bootstrap.php`
- Add: `master/tests/admin_knowledgebase_regression_test.php`

- [ ] **Step 1: Write the failing admin/editor regression test**

Create `master/tests/admin_knowledgebase_regression_test.php`:

```php
<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$admin = file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');
$bootstrap = file_get_contents(__DIR__ . '/../src/Bootstrap.php');

if (!is_string($admin) || $admin === '') {
    fail('failed to read AdminController source');
}
if (!is_string($bootstrap) || $bootstrap === '') {
    fail('failed to read Bootstrap source');
}

foreach ([
    'public function knowledgebaseThreadsPage(Request $request, array $params): Response',
    'public function knowledgebaseEditorPage(Request $request, array $params): Response',
    'public function saveKnowledgebaseThread(Request $request, array $params): Response',
    'public function uploadKnowledgebaseImage(Request $request, array $params): Response',
    'tinymce.min.js',
    'paste_data_images: true',
    '/admin/knowledgebase/images',
    'new FormData()',
    'Paste images directly into the editor.',
    'multipart/form-data',
] as $required) {
    if (!str_contains($admin, $required)) {
        fail('missing admin knowledgebase snippet: ' . $required);
    }
}

foreach ([
    "\$this->router->add('GET', '/admin/knowledgebase', [\$admin, 'knowledgebaseThreadsPage']);",
    "\$this->router->add('GET', '/admin/knowledgebase/new', [\$admin, 'knowledgebaseEditorPage']);",
    "\$this->router->add('GET', '/admin/knowledgebase/{id}/edit', [\$admin, 'knowledgebaseEditorPage']);",
    "\$this->router->add('POST', '/admin/knowledgebase/save', [\$admin, 'saveKnowledgebaseThread']);",
    "\$this->router->add('POST', '/admin/knowledgebase/images', [\$admin, 'uploadKnowledgebaseImage']);",
] as $requiredRoute) {
    if (!str_contains($bootstrap, $requiredRoute)) {
        fail('missing admin knowledgebase route: ' . $requiredRoute);
    }
}

echo "ok\n";
```

- [ ] **Step 2: Run the admin/editor regression test and verify it fails**

Run: `php master/tests/admin_knowledgebase_regression_test.php`  
Expected: FAIL because the admin knowledgebase routes and methods do not exist yet.

- [ ] **Step 3: Wire the new admin routes**

In `master/src/Bootstrap.php`, add these routes next to the other admin routes:

```php
$this->router->add('GET', '/admin/knowledgebase', [$admin, 'knowledgebaseThreadsPage']);
$this->router->add('GET', '/admin/knowledgebase/new', [$admin, 'knowledgebaseEditorPage']);
$this->router->add('GET', '/admin/knowledgebase/{id}/edit', [$admin, 'knowledgebaseEditorPage']);
$this->router->add('POST', '/admin/knowledgebase/save', [$admin, 'saveKnowledgebaseThread']);
$this->router->add('POST', '/admin/knowledgebase/images', [$admin, 'uploadKnowledgebaseImage']);
```

- [ ] **Step 4: Add the admin thread library and editor pages**

In `master/src/Controllers/AdminController.php`, add the import:

```php
use Leikwan\Master\Support\KnowledgebaseService;
```

Add the thread library page:

```php
public function knowledgebaseThreadsPage(Request $request, array $params): Response
{
    $viewer = $this->adminViewer();
    if ($viewer === null) {
        return Response::redirect('/login');
    }

    $service = new KnowledgebaseService($this->db);
    $threads = $service->listAdminThreads();
    $rows = '';
    foreach ($threads as $thread) {
        $status = (string) ($thread['status'] ?? 'draft');
        $statusClass = $status === 'published' ? 'success' : 'info';
        $rows .= '<tr>'
            . '<td>' . (int) ($thread['id'] ?? 0) . '</td>'
            . '<td><strong>' . $this->escape((string) ($thread['title'] ?? '')) . '</strong><div class="muted">' . $this->escape((string) ($thread['excerpt'] ?? '')) . '</div></td>'
            . '<td>' . $this->escape((string) ($thread['slug'] ?? '')) . '</td>'
            . '<td><span class="badge ' . $statusClass . '">' . $this->escape($status) . '</span></td>'
            . '<td>' . (((int) ($thread['is_pinned'] ?? 0) === 1) ? 'yes' : 'no') . '</td>'
            . '<td>' . (int) ($thread['sort_order'] ?? 100) . '</td>'
            . '<td>' . $this->escape((string) ($thread['updated_at'] ?? '')) . '</td>'
            . '<td><div class="actions"><a class="button" href="/admin/knowledgebase/' . (int) ($thread['id'] ?? 0) . '/edit">Edit</a><a class="button ghost" href="/knowledgebase?thread=' . urlencode((string) ($thread['slug'] ?? '')) . '" target="_blank" rel="noopener">View Live Page</a></div></td>'
            . '</tr>';
    }

    $body = '
        <div class="hero">
          <div>
            <h2>Thread Library</h2>
            <p class="muted">Admins can create starter docs, update operational guidance, and publish new threads for tenants without touching PHP templates.</p>
          </div>
          <div class="actions">
            <a class="button" href="/admin/knowledgebase/new">Create Thread</a>
            <a class="button ghost" href="/knowledgebase" target="_blank" rel="noopener">Open Reader</a>
          </div>
        </div>
        <section class="card" style="margin-top:20px;">
          <h3>Manage Threads</h3>
          <div class="table-wrap">
            <table>
              <thead><tr><th>ID</th><th>Title</th><th>Slug</th><th>Status</th><th>Pinned</th><th>Order</th><th>Updated</th><th>Actions</th></tr></thead>
              <tbody>' . ($rows !== '' ? $rows : '<tr><td colspan="8" class="muted">No threads yet.</td></tr>') . '</tbody>
            </table>
          </div>
        </section>';

    return $this->adminPage('Knowledgebase', $body, $viewer, 'knowledgebase');
}
```

Add the editor page:

```php
public function knowledgebaseEditorPage(Request $request, array $params): Response
{
    $viewer = $this->adminViewer();
    if ($viewer === null) {
        return Response::redirect('/login');
    }

    $service = new KnowledgebaseService($this->db);
    $threadId = $this->idParam($params);
    $thread = $threadId > 0 ? $service->findAdminThreadById($threadId) : null;
    if ($threadId > 0 && $thread === null) {
        SessionAuth::flash('error', 'Knowledgebase thread not found.');
        return Response::redirect('/admin/knowledgebase');
    }

    $csrf = $this->escape(SessionAuth::csrfToken());
    $thread = $thread ?? [
        'id' => 0,
        'title' => '',
        'slug' => '',
        'excerpt' => '',
        'body_html' => '<p>Start writing here.</p>',
        'status' => 'draft',
        'is_pinned' => 0,
        'sort_order' => 100,
    ];

    $body = '
        <section class="card">
          <div class="hero" style="margin-bottom:20px;">
            <div>
              <h2>' . ((int) ($thread['id'] ?? 0) > 0 ? 'Edit Thread' : 'Create Thread') . '</h2>
              <p class="muted">Paste images directly into the editor. The panel will upload them and replace the pasted blob with a real URL.</p>
            </div>
            <div class="actions">
              <a class="button ghost" href="/admin/knowledgebase">Back to Library</a>
              ' . (((int) ($thread['id'] ?? 0) > 0 && (string) ($thread['slug'] ?? '') !== '') ? '<a class="button secondary" target="_blank" rel="noopener" href="/knowledgebase?thread=' . urlencode((string) ($thread['slug'] ?? '')) . '">View Live Page</a>' : '') . '
            </div>
          </div>
          <form method="post" action="/admin/knowledgebase/save" enctype="multipart/form-data" data-kb-editor data-csrf-token="' . $csrf . '">
            <input type="hidden" name="csrf_token" value="' . $csrf . '">
            <input type="hidden" name="id" value="' . (int) ($thread['id'] ?? 0) . '">
            <div class="form-grid">
              <div class="field"><label>Title</label><input type="text" name="title" value="' . $this->escape((string) ($thread['title'] ?? '')) . '" required></div>
              <div class="field"><label>Slug</label><input type="text" name="slug" value="' . $this->escape((string) ($thread['slug'] ?? '')) . '"></div>
              <div class="field"><label>Summary</label><input type="text" name="excerpt" value="' . $this->escape((string) ($thread['excerpt'] ?? '')) . '"></div>
              <div class="field"><label>Order</label><input type="number" name="sort_order" value="' . (int) ($thread['sort_order'] ?? 100) . '"></div>
              <div class="field"><label>Status</label><select name="status"><option value="draft"' . ((string) ($thread['status'] ?? 'draft') === 'draft' ? ' selected' : '') . '>Draft</option><option value="published"' . ((string) ($thread['status'] ?? 'draft') === 'published' ? ' selected' : '') . '>Published</option></select></div>
              <div class="field"><label>Pinned</label><select name="is_pinned"><option value="0"' . ((int) ($thread['is_pinned'] ?? 0) === 0 ? ' selected' : '') . '>No</option><option value="1"' . ((int) ($thread['is_pinned'] ?? 0) === 1 ? ' selected' : '') . '>Yes</option></select></div>
            </div>
            <div class="field" style="margin-top:16px;">
              <label>Body HTML</label>
              <textarea id="body_html" name="body_html" rows="20">' . $this->escape((string) ($thread['body_html'] ?? '')) . '</textarea>
            </div>
            <div class="actions" style="margin-top:18px;">
              <button class="button" type="submit">Save Thread</button>
            </div>
          </form>
        </section>
        <script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js" referrerpolicy="origin"></script>
        <script>
          (function () {
            const form = document.querySelector('[data-kb-editor]');
            if (!form || typeof tinymce === 'undefined') {
              return;
            }
            const csrfToken = form.getAttribute('data-csrf-token') || '';
            tinymce.init({
              selector: '#body_html',
              height: 560,
              menubar: 'file edit view insert format table tools help',
              plugins: 'advlist autolink lists link image table code codesample fullscreen paste',
              toolbar: 'undo redo | blocks | bold italic underline | bullist numlist blockquote | alignleft aligncenter alignright | link image table codesample | fullscreen code',
              paste_data_images: true,
              automatic_uploads: true,
              images_upload_handler: (blobInfo) => new Promise((resolve, reject) => {
                const formData = new FormData();
                formData.append('file', blobInfo.blob(), blobInfo.filename());
                formData.append('csrf_token', csrfToken);
                fetch('/admin/knowledgebase/images', {
                  method: 'POST',
                  body: formData,
                  credentials: 'same-origin'
                })
                  .then(async (response) => {
                    const payload = await response.json();
                    if (!response.ok || !payload.location) {
                      throw new Error(payload.error || 'Image upload failed');
                    }
                    resolve(payload.location);
                  })
                  .catch((error) => reject(error.message));
              })
            });
          }());
        </script>';

    return $this->adminPage('Knowledgebase Editor', $body, $viewer, 'knowledgebase');
}
```

- [ ] **Step 5: Add the save handler and paste-upload endpoint**

Still in `master/src/Controllers/AdminController.php`, add:

```php
public function saveKnowledgebaseThread(Request $request, array $params): Response
{
    $viewer = $this->adminViewer();
    if ($viewer === null) {
        return Response::redirect('/login');
    }

    try {
        SessionAuth::validateCsrf($request);
        $service = new KnowledgebaseService($this->db);
        $threadId = $service->saveThread([
            'id' => $request->input('id', 0),
            'title' => $request->input('title', ''),
            'slug' => $request->input('slug', ''),
            'excerpt' => $request->input('excerpt', ''),
            'body_html' => $request->input('body_html', ''),
            'status' => $request->input('status', 'draft'),
            'is_pinned' => $request->input('is_pinned', '0'),
            'sort_order' => $request->input('sort_order', '100'),
        ], (int) ($viewer['id'] ?? 0));
        SessionAuth::flash('success', 'Knowledgebase thread saved.');
        return Response::redirect('/admin/knowledgebase/' . $threadId . '/edit');
    } catch (\Throwable $throwable) {
        SessionAuth::flash('error', 'Failed to save knowledgebase thread: ' . $this->displayThrowableMessage($throwable));
        return Response::redirect('/admin/knowledgebase');
    }
}
```

```php
public function uploadKnowledgebaseImage(Request $request, array $params): Response
{
    $viewer = $this->adminViewer();
    if ($viewer === null) {
        return Response::json(401, ['error' => 'authentication_required']);
    }

    try {
        SessionAuth::validateCsrf($request);
        $upload = $this->readKnowledgebaseImageUpload();
        $relativeDir = '/uploads/knowledgebase/' . gmdate('Y') . '/' . gmdate('m');
        $absoluteDir = dirname(__DIR__, 2) . '/public' . $relativeDir;
        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
            throw new ApiException('knowledgebase_upload_dir_failed', 500, ['message' => 'failed to create upload directory']);
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $upload['extension'];
        $absolutePath = $absoluteDir . '/' . $filename;
        if (!move_uploaded_file($upload['tmp_name'], $absolutePath)) {
            throw new ApiException('knowledgebase_upload_failed', 500, ['message' => 'failed to store uploaded image']);
        }

        return Response::json(200, ['location' => $relativeDir . '/' . $filename]);
    } catch (\Throwable $throwable) {
        return Response::json(422, ['error' => $this->displayThrowableMessage($throwable)]);
    }
}
```

Add the helper used above:

```php
/**
 * @return array{tmp_name:string,extension:string}
 */
private function readKnowledgebaseImageUpload(): array
{
    $file = $_FILES['file'] ?? $_FILES['image'] ?? null;
    if (!is_array($file)) {
        throw new ApiException('knowledgebase_upload_missing', 422, ['message' => 'no image upload found']);
    }

    $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorCode !== UPLOAD_ERR_OK) {
        throw new ApiException('knowledgebase_upload_invalid', 422, ['message' => 'image upload failed']);
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new ApiException('knowledgebase_upload_invalid', 422, ['message' => 'uploaded image is not readable']);
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > 5 * 1024 * 1024) {
        throw new ApiException('knowledgebase_upload_size', 422, ['message' => 'image must be between 1 byte and 5 MB']);
    }

    $mime = (string) ((new \finfo(FILEINFO_MIME_TYPE))->file($tmpName) ?: '');
    $allowed = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
    if (!isset($allowed[$mime])) {
        throw new ApiException('knowledgebase_upload_type', 422, ['message' => 'only PNG, JPG, GIF, and WEBP images are allowed']);
    }

    return [
        'tmp_name' => $tmpName,
        'extension' => $allowed[$mime],
    ];
}
```

- [ ] **Step 6: Re-run the admin/editor regression test and verify it passes**

Run: `php master/tests/admin_knowledgebase_regression_test.php`  
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add master/src/Controllers/AdminController.php master/src/Bootstrap.php master/tests/admin_knowledgebase_regression_test.php
git commit -m "feat: add admin knowledgebase editor and image upload"
```

---

### Task 4: Localize the new UI strings and run the full verification sweep

**Files:**
- Modify: `master/tests/i18n_locale_regression_test.php`
- Modify: `master/public/i18n/zh-TW.json`
- Modify: `master/public/i18n/zh-Hans.json`
- Test: `master/tests/knowledgebase_service_test.php`
- Test: `master/tests/knowledgebase_seed_regression_test.php`
- Test: `master/tests/knowledgebase_reader_regression_test.php`
- Test: `master/tests/admin_knowledgebase_regression_test.php`

- [ ] **Step 1: Extend the locale regression with the new knowledgebase strings**

In `master/tests/i18n_locale_regression_test.php`, append these items to `$requiredTextKeys`:

```php
$requiredTextKeys = array_merge($requiredTextKeys, [
    'Latest Threads',
    'Open admin editor',
    'No published threads yet.',
    'Admins can create the first knowledgebase thread from the admin panel.',
    'Thread Library',
    'Create Thread',
    'Manage Threads',
    'View Live Page',
    'Edit Thread',
    'Summary',
    'Order',
    'Pinned',
    'Draft',
    'Published',
    'Body HTML',
    'Save Thread',
    'Paste images directly into the editor.',
    'Knowledgebase thread saved.',
    'Knowledgebase thread not found.',
]);
```

- [ ] **Step 2: Run the locale regression and verify it fails on the new strings**

Run: `php master/tests/i18n_locale_regression_test.php`  
Expected: FAIL because the new knowledgebase strings are not in `zh-TW.json` and `zh-Hans.json` yet.

- [ ] **Step 3: Add the Traditional Chinese translations**

In `master/public/i18n/zh-TW.json`, add at least these `text` entries:

```json
"Latest Threads": "最新文章",
"Open admin editor": "打開管理編輯器",
"No published threads yet.": "目前還沒有已發佈的文章。",
"Admins can create the first knowledgebase thread from the admin panel.": "管理員可以從後台建立第一篇知識庫文章。",
"Thread Library": "文章庫",
"Create Thread": "新增文章",
"Manage Threads": "管理文章",
"View Live Page": "查看前台頁面",
"Edit Thread": "編輯文章",
"Summary": "摘要",
"Order": "排序",
"Pinned": "置頂",
"Draft": "草稿",
"Published": "已發佈",
"Body HTML": "正文 HTML",
"Save Thread": "儲存文章",
"Paste images directly into the editor.": "可直接將圖片貼到編輯器中上傳。",
"Knowledgebase thread saved.": "知識庫文章已儲存。",
"Knowledgebase thread not found.": "找不到該知識庫文章。"
```

- [ ] **Step 4: Add the Simplified Chinese translations**

In `master/public/i18n/zh-Hans.json`, add the matching `text` entries:

```json
"Latest Threads": "最新文章",
"Open admin editor": "打开管理编辑器",
"No published threads yet.": "目前还没有已发布的文章。",
"Admins can create the first knowledgebase thread from the admin panel.": "管理员可以从后台建立第一篇知识库文章。",
"Thread Library": "文章库",
"Create Thread": "新增文章",
"Manage Threads": "管理文章",
"View Live Page": "查看前台页面",
"Edit Thread": "编辑文章",
"Summary": "摘要",
"Order": "排序",
"Pinned": "置顶",
"Draft": "草稿",
"Published": "已发布",
"Body HTML": "正文 HTML",
"Save Thread": "保存文章",
"Paste images directly into the editor.": "可直接将图片贴到编辑器中上传。",
"Knowledgebase thread saved.": "知识库文章已保存。",
"Knowledgebase thread not found.": "找不到该知识库文章。"
```

- [ ] **Step 5: Run the full verification sweep**

Run each command below and require a clean pass before claiming the feature is done:

```bash
php master/tests/knowledgebase_service_test.php
php master/tests/knowledgebase_seed_regression_test.php
php master/tests/knowledgebase_reader_regression_test.php
php master/tests/admin_knowledgebase_regression_test.php
php master/tests/i18n_locale_regression_test.php
php -l master/src/Support/KnowledgebaseService.php
php -l master/src/Controllers/AuthController.php
php -l master/src/Controllers/AdminController.php
php -l master/src/Controllers/TenantController.php
php -l master/src/Bootstrap.php
```

Expected: every command prints `ok` or `No syntax errors detected`.

- [ ] **Step 6: Commit**

```bash
git add master/tests/i18n_locale_regression_test.php master/public/i18n/zh-TW.json master/public/i18n/zh-Hans.json
git commit -m "feat: localize threaded knowledgebase UI"
```

---

## Spec coverage self-check

- **Brand new knowledgebase page:** covered by Task 2 replacing the static `/knowledgebase` view with a thread-driven sidebar + article layout.
- **Admin panel permission to add new threads:** covered by Task 3 via `/admin/knowledgebase`, `/admin/knowledgebase/new`, and `/admin/knowledgebase/save`.
- **Tenant can view:** covered by Task 2 via `KnowledgebaseService::listPublishedThreads()` and `findPublishedThreadBySlug()`.
- **Thread writing page uses TinyMCE:** covered by Task 3 editor page and the TinyMCE CDN/init block.
- **Paste to Upload Image:** covered by Task 3 custom `images_upload_handler`, CSRF form-data post, and `/admin/knowledgebase/images` endpoint.
- **Pre-composed basic threads:** covered by Task 1 migration seed rows for four starter threads.

## Placeholder scan self-check

- No `TODO`, `TBD`, or “implement later” placeholders remain.
- Every task has exact file paths and exact commands.
- Every code-writing step includes the concrete code shape to add.
- The only optional behavior intentionally deferred is full HTML sanitization / revisions, which is explicitly marked out-of-scope.

## Type / naming consistency self-check

- Service class name is consistently `KnowledgebaseService`.
- Reader lookup always uses `findPublishedThreadBySlug()`.
- Admin edit lookup always uses `findAdminThreadById()`.
- Save endpoint is consistently `/admin/knowledgebase/save`.
- Paste upload endpoint is consistently `/admin/knowledgebase/images`.
- Starter thread slug names match the updated tenant links.

Plan complete and saved to `docs/superpowers/plans/2026-04-24-knowledgebase-threads.md`. Two execution options:

**1. Subagent-Driven (recommended)** - I dispatch a fresh subagent per task, review between tasks, fast iteration

**2. Inline Execution** - Execute tasks in this session using executing-plans, batch execution with checkpoints

**Which approach?**
