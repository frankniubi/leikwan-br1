<?php
declare(strict_types=1);

// services.expire_at is written in UTC. The panel's CLI workers run with
// date.timezone = PRC, where strtotime() read it eight hours early: the broker
// refused a grant and the client config dropped a service that still had up to
// eight hours left. Every service-expiry parse must read the value as UTC.

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

use Leikwan\Master\Support\LeigodTokenBroker;
use Leikwan\Master\Support\ProvisioningService;
use Leikwan\Master\Support\UltraLeaseService;

function assertServiceExpiryUtc(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

date_default_timezone_set('Asia/Shanghai');

$parsers = [
    [LeigodTokenBroker::class, 'parseServiceExpiry'],
    [ProvisioningService::class, 'serviceExpiryTimestamp'],
    [UltraLeaseService::class, 'serviceExpiryTimestamp'],
];

$expected = gmmktime(14, 43, 7, 9, 24, 2026);
foreach ($parsers as [$class, $method]) {
    $instance = (new ReflectionClass($class))->newInstanceWithoutConstructor();
    $parse = (new ReflectionMethod($class, $method))->getClosure($instance);
    $label = $class . '::' . $method;

    assertServiceExpiryUtc($parse('2026-09-24 14:43:07') === $expected, $label . ' must read a bare expire_at as UTC');
    assertServiceExpiryUtc($parse(' 2026-09-24 14:43:07 ') === $expected, $label . ' must trim the value');
    assertServiceExpiryUtc($parse('2026-09-24T14:43:07Z') === $expected, $label . ' must honour an explicit Z');
    assertServiceExpiryUtc($parse('2026-09-24 22:43:07+08:00') === $expected, $label . ' must honour an explicit offset');
    assertServiceExpiryUtc($parse('') === null, $label . ' must treat an empty expiry as none');
    assertServiceExpiryUtc($parse('not a date') === null, $label . ' must treat garbage as no expiry');

    // A service expiring in two hours is still live, whatever date.timezone says.
    $soon = gmdate('Y-m-d H:i:s', time() + 7200);
    assertServiceExpiryUtc($parse($soon) > time(), $label . ' must not expire a live service early under PRC');
}

echo "ok\n";
