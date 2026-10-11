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

final class ApiTokenStatement extends PDOStatement
{
    /** @param array<string, mixed> $params */
    public array $params = [];

    public function __construct(private ApiTokenPdo $pdo, private string $sql)
    {
    }

    public function execute(?array $params = null): bool
    {
        $this->params = $params ?? [];
        if (preg_match('/^\s*(UPDATE|INSERT|DELETE)\b/i', $this->sql) === 1) {
            $this->pdo->writes[] = ['sql' => $this->sql, 'params' => $this->params];
            if (str_contains($this->sql, 'UPDATE users SET api_token')) {
                $this->pdo->user['api_token'] = (string) $this->params['token'];
            }
        }
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if (str_contains($this->sql, 'SELECT * FROM users WHERE id = :id LIMIT 1')) {
            return $this->pdo->user;
        }
        return false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return [];
    }

    public function rowCount(): int
    {
        return 1;
    }
}

final class ApiTokenPdo extends PDO
{
    /** @var array<int, array{sql:string, params:array<string, mixed>}> */
    public array $writes = [];

    /** @param array<string, mixed> $user */
    public function __construct(public array $user)
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new ApiTokenStatement($this, $query);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return new ApiTokenStatement($this, $query);
    }

    public function inTransaction(): bool
    {
        return false;
    }
}

function login(array $user, string $csrf): void
{
    SessionAuth::boot();
    SessionAuth::login($user);
    $_SESSION['csrf_token'] = $csrf;
    unset($_SESSION['flash']);
}

function resetRequest(string $csrf): Request
{
    return new Request('POST', '/tenant/security/api-token/reset', [], ['csrf_token' => $csrf], [], '');
}

$env = tempnam(sys_get_temp_dir(), 'lw-api-token-');
if (!is_string($env)) {
    fail('failed to create test environment');
}
file_put_contents($env, implode("\n", [
    'APP_NAME=LeiKwan Bridge',
    'APP_URL=https://panel.example.test',
    'DB_DRIVER=mysql',
    'AUTO_RUN_MIGRATIONS=false',
]) . "\n");

try {
    $oldToken = str_repeat('ab', 24);
    $tenant = [
        'id' => 42,
        'role' => 'tenant',
        'status' => 'active',
        'name' => 'Token Tenant',
        'email' => 'tenant@example.test',
        'api_token' => $oldToken,
    ];
    $app = new Bootstrap($env);
    $pdo = new ApiTokenPdo($tenant);
    $app->db()->setPdo($pdo);
    $csrf = 'api-token-csrf';

    // A wrong CSRF token must not rotate anything.
    login($tenant, $csrf);
    $rejected = $app->handle(resetRequest('wrong-csrf'));
    assertSameValue(302, $rejected->status(), 'rejected reset redirects');
    assertSameValue([], $pdo->writes, 'reset without valid CSRF must not write');
    assertSameValue('error', $_SESSION['flash']['type'] ?? null, 'rejected reset flashes an error');

    // A valid reset writes a fresh token for this user only.
    login($tenant, $csrf);
    $reset = $app->handle(resetRequest($csrf));
    assertSameValue(302, $reset->status(), 'reset redirects');
    assertSameValue('/tenant/security', $reset->headers()['Location'] ?? null, 'reset returns to the security page');
    assertSameValue(1, count($pdo->writes), 'reset performs one write');
    $write = $pdo->writes[0];
    assertContains('UPDATE users SET api_token = :token', $write['sql'], 'reset updates users.api_token');
    assertSameValue(42, $write['params']['id'] ?? null, 'reset targets the signed-in user');
    $newToken = (string) ($write['params']['token'] ?? '');
    if (preg_match('/^[0-9a-f]{48}$/', $newToken) !== 1) {
        fail('new token must be 48 lowercase hex characters, got ' . var_export($newToken, true));
    }
    if (hash_equals($oldToken, $newToken)) {
        fail('new token must differ from the old token');
    }
    assertSameValue('success', $_SESSION['flash']['type'] ?? null, 'reset flashes success');

    // Admins cannot use the tenant reset endpoint.
    $pdo->writes = [];
    $admin = ['id' => 7, 'role' => 'admin', 'status' => 'active', 'name' => 'Admin', 'email' => 'admin@example.test'];
    $pdo->user = $admin;
    login($admin, $csrf);
    $denied = $app->handle(resetRequest($csrf));
    assertSameValue('/login', $denied->headers()['Location'] ?? null, 'non-tenant is sent to login');
    assertSameValue([], $pdo->writes, 'non-tenant reset must not write');

    // The card shows the current token, the reset form and the API guide link,
    // and explains that the Agent Token is a different credential.
    $render = new ReflectionMethod(\Leikwan\Master\Controllers\TenantController::class, 'renderApiTokenCard');
    $tenantController = (new ReflectionClass(\Leikwan\Master\Controllers\TenantController::class))->newInstanceWithoutConstructor();
    $card = (string) $render->invoke($tenantController, $newToken, 'csrf-value');
    assertContains('<code>' . $newToken . '</code>', $card, 'card shows the token');
    assertContains('data-copy-text="' . $newToken . '"', $card, 'card offers a copy button');
    assertContains('action="/tenant/security/api-token/reset"', $card, 'card posts to the reset route');
    assertContains('name="csrf_token" value="csrf-value"', $card, 'reset form carries CSRF');
    assertContains('/knowledgebase?thread=forward-lines-api', $card, 'card links to the API guide');
    assertContains('not the Agent Token', $card, 'card distinguishes the Agent Token');
    assertContains('Reset API token', $card, 'existing token offers reset');

    $empty = (string) $render->invoke($tenantController, '', 'csrf-value');
    assertContains('No API token yet.', $empty, 'missing token is explained');
    assertContains('Generate API token', $empty, 'missing token offers generation');
    assertNotContains('data-copy-text', $empty, 'missing token has nothing to copy');

    // The card lists the Service IDs the API paths take, so tenants stop
    // sending the Client ID instead.
    $withServices = (string) $render->invoke($tenantController, $newToken, 'csrf-value', [
        ['id' => 30, 'name' => 'Holiday plan'],
        ['id' => 37, 'name' => '<b>Mine</b>'],
    ]);
    assertContains('<code>30</code>', $withServices, 'card shows each Service ID');
    assertContains('data-copy-text="37"', $withServices, 'card offers copying a Service ID');
    assertContains('not the Client ID', $withServices, 'card distinguishes the Client ID');
    assertNotContains('<b>Mine</b>', $withServices, 'service names must be escaped');
    assertContains('No services yet.', $card, 'no services is explained');

    $hostile = (string) $render->invoke($tenantController, '"><script>x</script>', 'csrf-value');
    assertNotContains('<script>x</script>', $hostile, 'token must be escaped');
} finally {
    @unlink($env);
}

echo "ok\n";
