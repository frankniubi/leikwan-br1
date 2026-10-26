<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    if ($relative === false) {
        return;
    }

    $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use Leikwan\Master\Http\Request;
use Leikwan\Master\Support\TrustedClientIpResolver;

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertSame(?string $expected, ?string $actual, string $message): void
{
    if ($expected !== $actual) {
        fail("{$message}: expected {$expected}, got {$actual}");
    }
}

/** @param array<string, string> $headers */
function request(string $remoteAddress, array $headers = []): Request
{
    return new Request('GET', '/', [], [], $headers, '', $remoteAddress);
}

$resolver = new TrustedClientIpResolver([
    '10.0.0.0/8',
    '2001:db8:1::/48',
]);

assertSame(
    '203.0.113.8',
    $resolver->resolve(request('10.0.0.5', ['x-forwarded-for' => '203.0.113.8, 10.0.0.4'])),
    'trusted IPv4 proxy should supply the original client address'
);
assertSame(
    '203.0.113.8',
    $resolver->resolve(request('10.0.0.5', ['x-forwarded-for' => '192.0.2.66, 203.0.113.8'])),
    'a client-supplied leftmost X-Forwarded-For entry must not replace the address the proxy appended'
);
assertSame(
    '203.0.113.8',
    $resolver->resolve(request('10.0.0.5', ['x-forwarded-for' => 'junk, 203.0.113.8, 10.0.0.9'])),
    'trusted hops are skipped from the right and malformed client entries are ignored'
);
assertSame(
    '198.51.100.7',
    $resolver->resolve(request('198.51.100.7', ['x-forwarded-for' => '1.2.3.4'])),
    'untrusted peer must not override its source address'
);
assertSame(
    '2001:db8:feed::3',
    $resolver->resolve(request('2001:db8:1::10', ['x-real-ip' => '2001:db8:feed::3'])),
    'trusted IPv6 proxy should support X-Real-IP'
);
assertSame(
    '10.1.2.3',
    $resolver->resolve(request('10.1.2.3', ['x-forwarded-for' => 'not-an-ip'])),
    'malformed forwarded values must not replace the peer address'
);
assertSame(
    null,
    $resolver->resolve(request('', ['x-forwarded-for' => '203.0.113.8'])),
    'forwarded addresses without a trusted peer must be ignored'
);

echo "ok\n";
