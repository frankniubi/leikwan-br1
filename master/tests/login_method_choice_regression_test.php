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

final class LoginMethodChoiceStatement extends PDOStatement
{
    public function __construct(private string $sql)
    {
    }

    public function execute(?array $params = null): bool
    {
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if (str_contains($this->sql, 'SELECT COUNT(*) AS total FROM users')) {
            return ['total' => 1];
        }

        return false;
    }
}

final class LoginMethodChoicePdo extends PDO
{
    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new LoginMethodChoiceStatement($query);
    }
}

$env = tempnam(sys_get_temp_dir(), 'lw-login-methods-');
if (!is_string($env)) {
    fail('failed to create temporary environment file');
}

file_put_contents($env, implode("\n", [
    'APP_NAME=LeiKwan Bridge',
    'APP_URL=https://panel.example.test',
    'DB_DRIVER=mysql',
    'AUTO_RUN_MIGRATIONS=false',
    'OIDC_ENABLED=true',
    'OIDC_PROVIDER_NAME=LeiKwan Buy',
    'OIDC_ISSUER_URL=https://buy.example.test',
    'OIDC_CLIENT_ID=login-method-test',
    'OIDC_CLIENT_SECRET=login-method-secret',
    'OIDC_REDIRECT_URI=https://panel.example.test/auth/oidc/callback',
    'OIDC_TOKEN_AUTH_METHOD=client_secret_post',
]) . "\n");

try {
    $app = new Bootstrap($env);
    $app->db()->setPdo(new LoginMethodChoicePdo());

    SessionAuth::boot();
    unset($_SESSION['auth'], $_SESSION['flash']);
    $_SESSION['csrf_token'] = 'login-method-choice-csrf';

    $defaultResponse = $app->handle(new Request('GET', '/login', [], [], [], ''));
    if ($defaultResponse->status() !== 200) {
        fail('default login page did not return 200');
    }
    $defaultBody = $defaultResponse->body();

    assertContains('使用LeiKwan Buy登录', $defaultBody, 'default login page must show the Buy login method');
    assertContains('(Via LeiKwan Buy)', $defaultBody, 'Buy login method must show its English label');
    assertContains('href="/auth/oidc/start"', $defaultBody, 'Buy login method must start OIDC');
    assertContains('href="/login?buy=1"', $defaultBody, 'Buy login method must expose a direct credential fallback');
    assertContains('使用会聚账户(旧)', $defaultBody, 'default login page must show the legacy login method');
    assertContains('(Via LeiKwan Bridge (Legacy))', $defaultBody, 'legacy login method must show its English label');
    assertContains('href="/login?legacy=1"', $defaultBody, 'legacy login method must open the legacy form');
    assertNotContains('name="email"', $defaultBody, 'default login page must hide the legacy email input');
    assertNotContains('name="password"', $defaultBody, 'default login page must hide the legacy password input');
    assertNotContains('href="/register"', $defaultBody, 'default login page must hide panel registration links');
    assertNotContains('Create Account', $defaultBody, 'default login page must hide panel registration actions');

    $homeResponse = $app->handle(new Request('GET', '/', [], [], [], ''));
    if ($homeResponse->status() !== 200) {
        fail('public home page did not return 200');
    }
    assertNotContains('href="/register"', $homeResponse->body(), 'public navigation must hide panel registration links');

    $legacyResponse = $app->handle(new Request('GET', '/login', ['legacy' => '1'], [], [], ''));
    if ($legacyResponse->status() !== 200) {
        fail('legacy login page did not return 200');
    }
    assertContains('name="email"', $legacyResponse->body(), 'legacy login page must show the email input');
    assertContains('name="password"', $legacyResponse->body(), 'legacy login page must show the password input');

    $buyResponse = $app->handle(new Request('GET', '/login', ['buy' => '1'], [], [], ''));
    if ($buyResponse->status() !== 200) {
        fail('Buy login page did not return 200');
    }
    assertContains('action="/login/buy"', $buyResponse->body(), 'Buy login form must use its dedicated POST route');
    assertContains('name="email"', $buyResponse->body(), 'Buy login page must show the Buy email input');
    assertContains('name="password"', $buyResponse->body(), 'Buy login page must show the Buy password input');

    $failedLogin = $app->handle(new Request('POST', '/login', [], [
        'csrf_token' => 'login-method-choice-csrf',
        'email' => 'missing@example.test',
        'password' => 'incorrect',
    ], [], ''));
    if ($failedLogin->status() !== 302 || ($failedLogin->headers()['Location'] ?? '') !== '/login?legacy=1') {
        fail('failed legacy login must redirect back to the expanded legacy form');
    }

    $css = (string) file_get_contents(__DIR__ . '/../public/assets/bridge-modern.css');
    assertContains('.login-method-pill', $css, 'login methods must have a dedicated pill selector');
    assertContains('border-radius: 999px', $css, 'login method controls must use a pill shape');

    $assetVersion = '/assets/bridge-modern.css?v=20260914a';
    foreach ([
        __DIR__ . '/../src/Controllers/BaseController.php',
        __DIR__ . '/../public/index.php',
        __DIR__ . '/../public/install.php',
    ] as $shellPath) {
        assertContains($assetVersion, (string) file_get_contents($shellPath), 'every public shell must load the updated login styles');
    }

    $bootstrap = (string) file_get_contents(__DIR__ . '/../src/Bootstrap.php');
    $authSource = (string) file_get_contents(__DIR__ . '/../src/Controllers/AuthController.php');
    $whmcsIdentitySource = (string) file_get_contents(__DIR__ . '/../src/Support/WhmcsIdentityService.php');
    assertContains("'POST', '/login/buy'", $bootstrap, 'Buy login POST route must be registered');
    assertContains('public function buyLogin(', $authSource, 'Buy login handler must exist');
    assertContains('loginWithAuthenticatedIdentity(', $authSource, 'Buy login must resolve the authenticated client identity');
    assertContains('public function loginWithAuthenticatedIdentity(', $whmcsIdentitySource, 'authenticated Buy login resolver must exist');
    assertContains('bindUserToProfile(', $whmcsIdentitySource, 'authenticated Buy login must preserve client binding checks');
} finally {
    @unlink($env);
}

echo "ok\n";
