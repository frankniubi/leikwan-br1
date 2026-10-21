<?php
declare(strict_types=1);

// Needs pdo_sqlite: wsl -d Debian -- php master/tests/auth_rate_limiter_test.php

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

use Leikwan\Master\Config;
use Leikwan\Master\Controllers\AuthController;
use Leikwan\Master\Controllers\ClientAgentController;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Http\Request;
use Leikwan\Master\Http\Response;
use Leikwan\Master\Support\ApiException;
use Leikwan\Master\Support\AuthRateLimiter;
use Leikwan\Master\Support\ProvisioningService;

ob_start();

function fail(string $message): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fail($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

/** @param callable(): void $callback */
function expectRateLimited(callable $callback, string $message): int
{
    try {
        $callback();
    } catch (ApiException $exception) {
        if ($exception->getMessage() !== 'rate_limited' || $exception->statusCode() !== 429) {
            fail($message . ': expected 429 rate_limited, got ' . $exception->getMessage());
        }
        return (int) ($exception->details()['retry_after'] ?? 0);
    }
    fail($message . ': expected the attempt to be refused');
}

$envPath = tempnam(sys_get_temp_dir(), 'lw-auth-rate-');
file_put_contents($envPath, "DB_DRIVER=sqlite\nDB_NAME=:memory:\nTRUSTED_PROXY_CIDRS=10.0.0.0/8\n");
$config = new Config($envPath);
$db = new Database($config);
$pdo = $db->pdo();
$pdo->exec('CREATE TABLE auth_rate_limits (
    bucket_key TEXT NOT NULL PRIMARY KEY,
    window_started_at INTEGER NOT NULL,
    hits INTEGER NOT NULL DEFAULT 0,
    expires_at INTEGER NOT NULL
)');
$pdo->exec('CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT, email TEXT UNIQUE, role TEXT, status TEXT, password_hash TEXT,
    email_verified_at TEXT, created_at TEXT, updated_at TEXT
)');
$pdo->exec('CREATE TABLE clients (
    id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, agent_token TEXT, owner_user_id INTEGER, status TEXT
)');

// ---------------------------------------------------------------- limiter
$limiter = new AuthRateLimiter($db, 1);
$t = 2_000_000_000;
$account = [AuthRateLimiter::bucket('t:account', '1.2.3.4|a@example.test', 3, 900)];
for ($i = 0; $i < 3; $i++) {
    $limiter->hit($account, $t + $i);
}
$retry = expectRateLimited(static fn() => $limiter->hit($account, $t + 100), 'fourth hit in the window');
assertSame(800, $retry, 'retry_after counts to the end of the window that started at the first hit');
expectRateLimited(static fn() => $limiter->hit($account, $t + 899), 'refused hits keep the bucket refused');
$limiter->hit($account, $t + 900); // window over: starts again at 1
$limiter->hit($account, $t + 901);
$limiter->hit($account, $t + 902);
expectRateLimited(static fn() => $limiter->hit($account, $t + 903), 'the new window has the same limit');

$other = [AuthRateLimiter::bucket('t:account', '1.2.3.4|b@example.test', 3, 900)];
$limiter->hit($other, $t + 903); // other identifiers are independent

$limiter->reset($account);
$limiter->hit($account, $t + 904); // reset forgets the bucket

$refunded = [AuthRateLimiter::bucket('t:refund', 'x', 2, 900)];
for ($i = 0; $i < 10; $i++) {
    $limiter->hit($refunded, $t);
    $limiter->refund($refunded); // successes do not consume the budget
}
$limiter->hit($refunded, $t);
$limiter->hit($refunded, $t);
expectRateLimited(static fn() => $limiter->hit($refunded, $t), 'refunds only give back what was taken');

$stored = $db->fetchAll('SELECT bucket_key FROM auth_rate_limits');
foreach ($stored as $row) {
    if (!preg_match('/^[a-f0-9]{64}$/', (string) $row['bucket_key'])) {
        fail('bucket keys must be hashes, got ' . $row['bucket_key']);
    }
}
$limiter->hit([AuthRateLimiter::bucket('t:prune', 'y', 5, 10)], $t + 5000); // prunes expired rows
assertSame(1, (int) ($db->fetchOne('SELECT COUNT(*) AS total FROM auth_rate_limits')['total'] ?? -1), 'expired windows are pruned');

// Fails open when the table is missing.
$brokenEnv = tempnam(sys_get_temp_dir(), 'lw-auth-rate-broken-');
file_put_contents($brokenEnv, "DB_DRIVER=sqlite\nDB_NAME=:memory:\n");
$broken = new AuthRateLimiter(new Database(new Config($brokenEnv)), 1);
$previousErrorLog = ini_set('error_log', $brokenEnv . '.log');
for ($i = 0; $i < 5; $i++) {
    $broken->hit([AuthRateLimiter::bucket('t:broken', 'z', 1, 60)], $t);
}
ini_set('error_log', (string) $previousErrorLog);
@unlink($brokenEnv . '.log');

// ------------------------------------------------------------- helpers
$request = static fn(string $peer, array $headers = []): Request => new Request('POST', '/login', [], [], $headers, '', $peer);
assertSame('203.0.113.9', AuthRateLimiter::clientIp($config, $request('10.0.0.2', ['x-forwarded-for' => '198.51.100.1, 203.0.113.9'])), 'trusted proxy: the address the proxy appended');
assertSame('198.51.100.20', AuthRateLimiter::clientIp($config, $request('198.51.100.20', ['x-forwarded-for' => '203.0.113.9'])), 'untrusted peers cannot claim another address');
assertSame('2001:db8:1:2::/64', AuthRateLimiter::clientIp($config, $request('2001:db8:1:2:aaaa:bbbb:cccc:dddd')), 'IPv6 clients are bucketed per /64');

$hash = password_hash('right-password', PASSWORD_DEFAULT);
assertSame(true, AuthRateLimiter::verifyPassword('right-password', $hash), 'stored hash verifies');
assertSame(false, AuthRateLimiter::verifyPassword('wrong', $hash), 'wrong password fails');
assertSame(false, AuthRateLimiter::verifyPassword('anything', null), 'missing hash never verifies');
assertSame(false, AuthRateLimiter::verifyPassword('', ''), 'empty hash never verifies');

// ---------------------------------------------------- panel login form
$pdo->exec('DELETE FROM auth_rate_limits');
$now = gmdate('Y-m-d H:i:s');
$db->insert('users', ['name' => 'Tenant', 'email' => 'tenant@example.test', 'role' => 'tenant', 'status' => 'active', 'password_hash' => $hash, 'email_verified_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
$db->insert('users', ['name' => 'Pending', 'email' => 'pending@example.test', 'role' => 'tenant', 'status' => 'pending_verification', 'password_hash' => $hash, 'email_verified_at' => null, 'created_at' => $now, 'updated_at' => $now]);

session_id('authratelimitertest');
$_SESSION = [];
$csrf = \Leikwan\Master\Support\SessionAuth::csrfToken();
$auth = new AuthController($config, $db);
$login = static function (string $email, string $password, string $peer = '198.51.100.50') use ($auth, $csrf): Response {
    unset($_SESSION['auth'], $_SESSION['flash']);
    return $auth->login(new Request('POST', '/login', [], ['csrf_token' => $csrf, 'email' => $email, 'password' => $password], [], '', $peer), []);
};
$flash = static fn(): string => (string) ($_SESSION['flash']['message'] ?? '');

$response = $login('pending@example.test', 'wrong');
$genericError = $flash();
$login('nobody@example.test', 'wrong');
assertSame($genericError, $flash(), 'a pending account with a wrong password looks like an unknown email');
$login('pending@example.test', 'right-password');
if (!str_contains($flash(), 'waiting for email verification')) {
    fail('the pending notice is still shown to someone who knows the password');
}

$pdo->exec('DELETE FROM auth_rate_limits');
for ($i = 0; $i < AuthRateLimiter::LOGIN_ACCOUNT_LIMIT; $i++) {
    $response = $login('tenant@example.test', 'wrong-' . $i);
    assertSame(302, $response->status(), 'failed login ' . $i . ' is a normal redirect');
}
$response = $login('tenant@example.test', 'right-password');
assertSame(429, $response->status(), 'the account is locked for this address after ' . AuthRateLimiter::LOGIN_ACCOUNT_LIMIT . ' failures, even with the right password');
if ((int) ($response->headers()['Retry-After'] ?? 0) <= 0 || !str_contains($response->body(), 'Too many sign-in attempts')) {
    fail('the HTML 429 carries Retry-After and a friendly message');
}
if (isset($_SESSION['auth'])) {
    fail('a throttled login must not sign in');
}
assertSame(302, $login('tenant@example.test', 'right-password', '198.51.100.51')->status(), 'the same account from another address is not locked (per IP+account)');
if (!isset($_SESSION['auth'])) {
    fail('the other address signs in');
}

// Window reset: expire every bucket, the account can sign in again.
$pdo->exec('UPDATE auth_rate_limits SET expires_at = ' . (time() - 1));
assertSame(302, $login('tenant@example.test', 'right-password')->status(), 'after the window the account signs in');
if (!isset($_SESSION['auth'])) {
    fail('sign in after the window');
}

// Per-IP budget across many emails.
$pdo->exec('DELETE FROM auth_rate_limits');
for ($i = 0; $i < AuthRateLimiter::LOGIN_IP_LIMIT; $i++) {
    assertSame(302, $login('spray' . $i . '@example.test', 'x', '198.51.100.60')->status(), 'spray attempt ' . $i);
}
assertSame(429, $login('another@example.test', 'x', '198.51.100.60')->status(), 'the address is throttled after ' . AuthRateLimiter::LOGIN_IP_LIMIT . ' failures across accounts');
assertSame(302, $login('another@example.test', 'x', '198.51.100.61')->status(), 'other addresses are unaffected');

// A successful login gives back its per-IP attempt and clears the account bucket.
$pdo->exec('DELETE FROM auth_rate_limits');
for ($i = 0; $i < AuthRateLimiter::LOGIN_ACCOUNT_LIMIT + 5; $i++) {
    $login('tenant@example.test', 'right-password', '198.51.100.70');
    if (!isset($_SESSION['auth'])) {
        fail('repeated successful logins must not lock the account (attempt ' . $i . ')');
    }
}

// ------------------------------------------------ forgot password
$pdo->exec('DELETE FROM auth_rate_limits');
$reset = static fn(string $email, string $peer = '198.51.100.80'): Response => $auth->requestPasswordReset(
    new Request('POST', '/forgot-password', [], ['csrf_token' => $csrf, 'email' => $email], [], '', $peer),
    []
);
for ($i = 0; $i < AuthRateLimiter::RESET_EMAIL_LIMIT; $i++) {
    assertSame(302, $reset('nobody@example.test')->status(), 'reset request ' . $i);
}
assertSame(429, $reset('nobody@example.test')->status(), 'reset mail is limited per email');
assertSame(302, $reset('someone-else@example.test')->status(), 'other emails can still ask');

// ------------------------------------------------- desktop client JSON
$pdo->exec('DELETE FROM auth_rate_limits');
$clientController = new ClientAgentController($config, $db, new ProvisioningService($config, $db));
$clientLogin = static fn(string $email, string $password, string $peer = '198.51.100.90'): Response => $clientController->desktopLogin(
    new Request('POST', '/api/client/login', [], ['email' => $email, 'password' => $password], [], '', $peer),
    []
);
for ($i = 0; $i < AuthRateLimiter::LOGIN_ACCOUNT_LIMIT; $i++) {
    assertSame(401, $clientLogin('tenant@example.test', 'wrong')->status(), 'client login failure ' . $i);
}
$response = $clientLogin('tenant@example.test', 'right-password');
assertSame(429, $response->status(), 'client login is locked like the panel form');
$payload = json_decode($response->body(), true);
assertSame(false, $payload['ok'] ?? null, '429 JSON ok flag');
assertSame('rate_limited', $payload['error'] ?? null, '429 JSON error code');
if ((int) ($payload['retry_after'] ?? 0) <= 0 || (string) ($response->headers()['Retry-After'] ?? '') !== (string) $payload['retry_after']) {
    fail('429 JSON carries retry_after and the Retry-After header');
}
assertSame(401, $clientLogin('unknown@example.test', 'x', '198.51.100.91')->status(), 'unknown email is a plain 401');

while (ob_get_level() > 0) {
    ob_end_clean();
}
@unlink($envPath);
@unlink($brokenEnv);
echo "ok\n";
