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

use Leikwan\Master\Support\AndroidDeviceAttestationVerifier;

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fail("{$message}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function derLength(int $length): string
{
    if ($length < 128) {
        return chr($length);
    }
    $bytes = '';
    while ($length > 0) {
        $bytes = chr($length & 0xff) . $bytes;
        $length >>= 8;
    }
    return chr(0x80 | strlen($bytes)) . $bytes;
}

function derTag(int $class, bool $constructed, int $tag): string
{
    $prefix = ($class << 6) | ($constructed ? 0x20 : 0);
    if ($tag < 31) {
        return chr($prefix | $tag);
    }
    $encoded = chr($tag & 0x7f);
    while (($tag >>= 7) > 0) {
        $encoded = chr(0x80 | ($tag & 0x7f)) . $encoded;
    }
    return chr($prefix | 0x1f) . $encoded;
}

function der(int $class, bool $constructed, int $tag, string $value): string
{
    return derTag($class, $constructed, $tag) . derLength(strlen($value)) . $value;
}

function sequence(string ...$children): string
{
    return der(0, true, 16, implode('', $children));
}

function setOf(string ...$children): string
{
    return der(0, true, 17, implode('', $children));
}

function integer(int $value): string
{
    return der(0, false, 2, chr($value));
}

function enumerated(int $value): string
{
    return der(0, false, 10, chr($value));
}

function octets(string $value): string
{
    return der(0, false, 4, $value);
}

function contextExplicit(int $tag, string $value): string
{
    return der(2, true, $tag, $value);
}

$expectedDigest = hash('sha256', 'fixture-signing-certificate');
$applicationId = sequence(
    setOf(sequence(octets('com.leikwan.bridge'), integer(1))),
    setOf(octets(hex2bin($expectedDigest)))
);
$softwareEnforced = sequence(contextExplicit(709, octets($applicationId)));
$rootOfTrust = sequence(
    octets('verified-boot-key'),
    der(0, false, 1, "\xff"),
    enumerated(0),
    octets('verified-boot-hash'),
);
$teeEnforced = sequence(contextExplicit(704, $rootOfTrust));
$keyDescription = sequence(
    integer(3),
    enumerated(1),
    integer(4),
    enumerated(1),
    octets('fixture-nonce'),
    octets(''),
    $softwareEnforced,
    $teeEnforced,
);

$verifier = new AndroidDeviceAttestationVerifier(
    'com.leikwan.bridge',
    [$expectedDigest],
    [hash('sha256', 'fixture-root')],
);
$method = new ReflectionMethod($verifier, 'parseKeyDescription');
$record = $method->invoke($verifier, $keyDescription);
if (!is_array($record)) {
    fail('expected a valid Android KeyDescription record');
}
assertSame('trusted_environment', $record['attestation_security_level'] ?? null, 'attestation security level');
assertSame('trusted_environment', $record['keymaster_security_level'] ?? null, 'keymaster security level');
assertSame('fixture-nonce', $record['challenge'] ?? null, 'attestation challenge');
assertSame(true, $record['device_locked'] ?? null, 'locked boot state');
assertSame('verified', $record['verified_boot_state'] ?? null, 'verified boot state');
assertSame(['com.leikwan.bridge'], $record['packages'] ?? null, 'attested package name');
assertSame([$expectedDigest], $record['signature_digests'] ?? null, 'attested signing certificate digest');

echo "ok\n";
