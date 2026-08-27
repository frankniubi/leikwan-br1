<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Support/IpAddressMatcher.php';

use Leikwan\Master\Support\IpAddressMatcher;

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertTrue(bool $value, string $message): void
{
    if (!$value) {
        fail($message);
    }
}

function assertFalse(bool $value, string $message): void
{
    if ($value) {
        fail($message);
    }
}

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fail($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$v4 = IpAddressMatcher::canonicalize('203.0.113.10');
assertSame('203.0.113.10', $v4['canonical'] ?? null, 'IPv4 canonical value');
assertSame(4, $v4['family'] ?? null, 'IPv4 family');
assertSame(4, strlen((string) ($v4['packed'] ?? '')), 'IPv4 packed length');

$v6 = IpAddressMatcher::canonicalize('2001:0DB8:0:0:0:0:0:1');
assertSame('2001:db8::1', $v6['canonical'] ?? null, 'IPv6 canonical value');
assertSame(6, $v6['family'] ?? null, 'IPv6 family');
assertSame(16, strlen((string) ($v6['packed'] ?? '')), 'IPv6 packed length');

assertSame(null, IpAddressMatcher::canonicalize('203.0.113.001'), 'leading-zero IPv4 must be rejected');
assertSame(null, IpAddressMatcher::canonicalize('not-an-ip'), 'invalid IP must be rejected');
assertSame(null, IpAddressMatcher::canonicalCidr('203.0.113.0/33'), 'IPv4 prefix overflow');
assertSame(null, IpAddressMatcher::canonicalCidr('2001:db8::/129'), 'IPv6 prefix overflow');
assertSame(null, IpAddressMatcher::canonicalCidr('203.0.113.0'), 'CIDR requires prefix');

assertTrue(IpAddressMatcher::contains('203.0.113.0/24', '203.0.113.0'), 'CIDR network boundary');
assertTrue(IpAddressMatcher::contains('203.0.113.0/24', '203.0.113.255'), 'CIDR broadcast boundary');
assertFalse(IpAddressMatcher::contains('203.0.113.0/24', '203.0.114.1'), 'CIDR outside network');
assertFalse(IpAddressMatcher::contains('203.0.113.0/24', '2001:db8::1'), 'CIDR family mismatch');
assertTrue(IpAddressMatcher::contains('2001:db8::/32', '2001:db8:abcd::10'), 'IPv6 CIDR match');
assertFalse(IpAddressMatcher::contains('2001:db8::/32', '2001:db9::10'), 'IPv6 CIDR outside network');

$network = IpAddressMatcher::canonicalCidr('2001:0DB8:0000::/48');
assertSame('2001:db8::/48', $network['canonical'] ?? null, 'IPv6 CIDR canonical value');
assertSame('2001:db8::', IpAddressMatcher::canonicalize('2001:0db8::')['canonical'] ?? null, 'IPv6 zero compression');

echo "ok\n";
