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

use Leikwan\Master\Support\OidcSessionStore;

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fail($message);
    }
}

function assertThrows(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable) {
        return;
    }
    fail($message);
}

$_SESSION = [];
$now = 1_700_000_000;
$store = new OidcSessionStore(static function () use (&$now): int {
    return $now;
});

$flow = $store->create('/account?tab=billing', true);
assertTrue(session_status() === PHP_SESSION_ACTIVE, 'OIDC flow store must initialize a PHP session');
assertTrue(preg_match('/^[A-Za-z0-9_-]{32,}$/', (string) ($flow['state'] ?? '')) === 1, 'state must be high entropy URL-safe data');
assertTrue(preg_match('/^[A-Za-z0-9_-]{32,}$/', (string) ($flow['nonce'] ?? '')) === 1, 'nonce must be high entropy URL-safe data');
assertTrue(($flow['return_to'] ?? null) === '/account?tab=billing', 'local return target must be retained');
assertTrue(($flow['expires_at'] ?? null) === $now + 600, 'flow must have ten minute TTL');
assertTrue(isset($flow['code_verifier'], $flow['code_challenge']), 'PKCE flow must have verifier and challenge');
assertTrue(hash_equals($flow['code_challenge'], rtrim(strtr(base64_encode(hash('sha256', $flow['code_verifier'], true)), '+/', '-_'), '=')), 'PKCE challenge must use S256');

$consumed = $store->consume($flow['state']);
assertTrue($consumed === $flow, 'consume must return the original flow exactly once');
assertThrows(static fn() => $store->consume($flow['state']), 'replayed state must be rejected');

$nonPkce = $store->create('https://attacker.invalid/', false);
assertTrue(($nonPkce['return_to'] ?? null) === '/', 'external return target must be reduced to root');
assertTrue(!isset($nonPkce['code_verifier'], $nonPkce['code_challenge']), 'non-PKCE flow must not store PKCE material');

$unsafe = $store->create('//attacker.invalid/path', false);
assertTrue(($unsafe['return_to'] ?? null) === '/', 'scheme-relative return target must be rejected');
$backslash = $store->create('/safe\\evil', false);
assertTrue(($backslash['return_to'] ?? null) === '/', 'backslash return target must be rejected');
$encodedUnsafe = $store->create('/%2f%2fattacker.invalid/%5cevil', false);
assertTrue(($encodedUnsafe['return_to'] ?? null) === '/', 'encoded protocol-relative and backslash return target must be rejected');

$expired = $store->create('/profile', false);
$now += 600;
assertThrows(static fn() => $store->consume($expired['state']), 'expired state must be rejected');

echo "ok\n";
