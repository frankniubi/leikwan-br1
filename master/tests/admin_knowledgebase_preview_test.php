#!/usr/bin/env php
<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use Leikwan\Master\Bootstrap;
use Leikwan\Master\Http\Request;
use Leikwan\Master\Http\Response;
use Leikwan\Master\Support\SessionAuth;

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fail($message . '; expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertContains(string $needle, string $haystack, string $message): void
{
    if (!str_contains($haystack, $needle)) {
        fail($message . ': missing ' . $needle);
    }
}

function assertNotContains(string $needle, string $haystack, string $message): void
{
    if (str_contains($haystack, $needle)) {
        fail($message . ': unexpectedly found ' . $needle);
    }
}

final class KnowledgebasePreviewStatement extends PDOStatement
{
    /** @param array<string, mixed> $user */
    public function __construct(private string $sql, private array $user)
    {
    }

    public function execute(?array $params = null): bool
    {
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if (str_contains($this->sql, 'SELECT * FROM users WHERE id = :id LIMIT 1')) {
            return $this->user;
        }

        return false;
    }
}

final class KnowledgebasePreviewPdo extends PDO
{
    /** @var array<int, string> */
    public array $readQueries = [];

    /** @param array<string, mixed> $user */
    public function __construct(private array $user)
    {
    }

    private function recordSelect(string $query, string $method): void
    {
        if (preg_match('/^\s*SELECT\b/i', $query) !== 1) {
            throw new RuntimeException('preview attempted a non-SELECT ' . $method . ': ' . $query);
        }

        $this->readQueries[] = $query;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->recordSelect($query, 'prepare');
        return new KnowledgebasePreviewStatement($query, $this->user);
    }

    public function exec(string $statement): int|false
    {
        throw new RuntimeException('preview attempted PDO::exec: ' . $statement);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->recordSelect($query, 'query');
        return new KnowledgebasePreviewStatement($query, $this->user);
    }

    public function beginTransaction(): bool
    {
        throw new RuntimeException('preview attempted to begin a transaction');
    }

    public function commit(): bool
    {
        throw new RuntimeException('preview attempted to commit a transaction');
    }

    public function rollBack(): bool
    {
        throw new RuntimeException('preview attempted to roll back a transaction');
    }
}

/** @param array<string, mixed> $body */
function previewRequest(array $body): Request
{
    return new Request('POST', '/admin/knowledgebase/articles/preview', [], $body, [], '');
}

function loginAdmin(string $csrf): void
{
    SessionAuth::boot();
    SessionAuth::login(['id' => 7, 'role' => 'admin']);
    $_SESSION['csrf_token'] = $csrf;
    unset($_SESSION['flash']);
}

function assertErrorPage(Response $response, int $status, string $message, string $secret): void
{
    assertSameValue($status, $response->status(), 'error response status');
    assertContains($message, $response->body(), 'error response must use fixed server text');
    assertNotContains($secret, $response->body(), 'error response must not reflect posted data');
}

$env = tempnam(sys_get_temp_dir(), 'lw-kb-preview-');
if (!is_string($env)) {
    fail('failed to create preview test environment');
}
file_put_contents($env, implode("\n", [
    'APP_NAME=LeiKwan Bridge',
    'APP_URL=https://panel.example.test',
    'DB_DRIVER=mysql',
    'AUTO_RUN_MIGRATIONS=false',
]) . "\n");

try {
    $app = new Bootstrap($env);
    $pdo = new KnowledgebasePreviewPdo([
        'id' => 7,
        'role' => 'admin',
        'status' => 'active',
        'name' => 'Preview Admin',
        'email' => 'admin@example.test',
    ]);
    $app->db()->setPdo($pdo);
    $csrf = 'knowledgebase-preview-csrf';

    loginAdmin($csrf);
    $title = '<img src=x onerror=alert(1)>Unsaved & title';
    $plainBody = '<p onclick="evil()">Body <span>safe</span>. Harmless literal: &lt;img onerror=alert(1)&gt;</p>'
        . '<script>ACTIVE_BODY_SCRIPT</script>'
        . '<img src="/uploads/knowledgebase/safe.png" alt="Safe" width="320" height="180" onclick="evil()">';
    $plain = $app->handle(previewRequest([
        'csrf_token' => $csrf,
        'locale' => 'en',
        'title' => $title,
        'excerpt' => 'Unsaved <strong>excerpt</strong>',
        'body_html' => $plainBody,
    ]));
    assertSameValue(200, $plain->status(), 'plain preview status');
    assertContains('&lt;img src=x onerror=alert(1)&gt;Unsaved &amp; title', $plain->body(), 'unsaved XSS title must render as literal text');
    assertNotContains($title, $plain->body(), 'raw title markup must not render');
    assertContains('Unsaved &lt;strong&gt;excerpt&lt;/strong&gt;', $plain->body(), 'excerpt must be escaped');
    assertContains('Harmless literal: &lt;img onerror=alert(1)&gt;', $plain->body(), 'harmless escaped onerror literal must remain');
    assertNotContains('onclick=', $plain->body(), 'active body event handlers must be removed');
    assertNotContains('ACTIVE_BODY_SCRIPT', $plain->body(), 'active body script must be removed');
    assertContains('<img src="/uploads/knowledgebase/safe.png" alt="Safe" width="320" height="180">', $plain->body(), 'safe image attributes must remain');
    assertContains('Preview — not published', $plain->body(), 'English preview banner');
    assertNotContains('/assets/vendor/mermaid/mermaid.min.js?v=11.12.0', $plain->body(), 'plain preview must not load Mermaid');
    assertNotContains('/assets/knowledgebase-mermaid.js?v=20260729', $plain->body(), 'plain preview must not load Mermaid runtime');

    loginAdmin($csrf);
    $diagramSource = "flowchart TD\naccTitle: Safe preview\naccDescr: Safe diagram\nA--&gt;B";
    $diagram = $app->handle(previewRequest([
        'csrf_token' => $csrf,
        'locale' => 'zh-Hans',
        'title' => '图示预览',
        'excerpt' => '尚未发布',
        'body_html' => '<pre class="mermaid">' . $diagramSource . '</pre>',
    ]));
    assertSameValue(200, $diagram->status(), 'diagram preview status');
    assertContains('预览 — 尚未发布', $diagram->body(), 'Simplified Chinese preview banner');
    assertContains('/assets/vendor/mermaid/mermaid.min.js?v=11.12.0', $diagram->body(), 'diagram preview must load local Mermaid');
    assertContains('/assets/knowledgebase-mermaid.js?v=20260729', $diagram->body(), 'diagram preview must load local Mermaid runtime');
    assertNotContains('cdn.jsdelivr.net', $diagram->body(), 'diagram preview must not use a CDN');

    loginAdmin($csrf);
    $traditional = $app->handle(previewRequest([
        'csrf_token' => $csrf,
        'locale' => 'zh-TW',
        'title' => '預覽',
        'excerpt' => '',
        'body_html' => '<p>正文</p>',
    ]));
    assertSameValue(200, $traditional->status(), 'Traditional Chinese preview status');
    assertContains('預覽 — 尚未發佈', $traditional->body(), 'Traditional Chinese preview banner');

    $expiredSecret = 'EXPIRED_PREVIEW_BODY_SECRET';
    loginAdmin($csrf);
    $expired = $app->handle(previewRequest([
        'locale' => 'en',
        'title' => 'Expired',
        'excerpt' => '',
        'body_html' => '<p>' . $expiredSecret . '</p>',
    ]));
    assertErrorPage($expired, 419, 'Preview request expired. Reload the editor and try again.', $expiredSecret);
    assertNotContains('Preview could not be rendered', $expired->body(), 'CSRF failure must stop before content validation');

    $invalidLocaleSecret = 'INVALID_LOCALE_PREVIEW_SECRET';
    loginAdmin($csrf);
    $invalidLocale = $app->handle(previewRequest([
        'csrf_token' => $csrf,
        'locale' => ['en'],
        'title' => 'Invalid locale',
        'excerpt' => '',
        'body_html' => '<p>' . $invalidLocaleSecret . '</p>',
    ]));
    assertErrorPage($invalidLocale, 422, 'Preview could not be rendered. Check the article and diagrams.', $invalidLocaleSecret);

    $clickSecret = 'CLICK_PREVIEW_SOURCE_SECRET';
    loginAdmin($csrf);
    $click = $app->handle(previewRequest([
        'csrf_token' => $csrf,
        'locale' => 'en',
        'title' => 'Unsafe diagram',
        'excerpt' => '',
        'body_html' => "<pre class=\"mermaid\">flowchart TD\naccTitle: Unsafe\naccDescr: Unsafe\nA--&gt;B\nclick A callback {$clickSecret}</pre>",
    ]));
    assertErrorPage($click, 422, 'Preview could not be rendered. Check the article and diagrams.', $clickSecret);
    assertNotContains('Mermaid directives', $click->body(), 'policy exception message must not be exposed');

    SessionAuth::logout();
    $loggedOut = $app->handle(previewRequest([
        'csrf_token' => $csrf,
        'locale' => 'en',
        'title' => 'Logged out',
        'excerpt' => '',
        'body_html' => '<p>Logged out</p>',
    ]));
    assertSameValue(302, $loggedOut->status(), 'logged-out preview status');
    assertSameValue('/login', $loggedOut->headers()['Location'] ?? null, 'logged-out preview location');

    if ($pdo->readQueries === []) {
        fail('preview test expected authentication SELECT queries');
    }
    foreach ($pdo->readQueries as $query) {
        if (preg_match('/^\s*SELECT\b/i', $query) !== 1) {
            fail('preview recorded a non-SELECT query: ' . $query);
        }
    }
    $authenticationReads = array_filter(
        $pdo->readQueries,
        static fn (string $query): bool => str_contains($query, 'SELECT * FROM users WHERE id = :id LIMIT 1')
    );
    if ($authenticationReads === []) {
        fail('preview test did not record the administrator authentication SELECT');
    }
} finally {
    @unlink($env);
    SessionAuth::boot();
    unset($_SESSION['auth'], $_SESSION['csrf_token'], $_SESSION['flash']);
}

echo "ok\n";
