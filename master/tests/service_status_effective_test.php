<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Support/ServiceStatus.php';

use Leikwan\Master\Support\ServiceStatus;

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function expectStatus(array $service, int $now, string $expected, string $label): void
{
    $actual = ServiceStatus::effective($service, $now);
    if ($actual !== $expected) {
        fail($label . ': expected ' . $expected . ', got ' . $actual);
    }
}

$now = (new DateTimeImmutable('2026-10-04 12:00:00', new DateTimeZone('UTC')))->getTimestamp();

expectStatus(['status' => 'active', 'expire_at' => '2026-11-07 23:59:59'], $now, 'active', 'future expiry stays active');
expectStatus(['status' => 'active', 'expire_at' => '2026-09-24 14:43:07'], $now, 'expired', 'past expiry shows expired');
expectStatus(['status' => 'active', 'expire_at' => '2026-10-04 12:00:00'], $now, 'expired', 'expiry instant counts as expired, like the lease broker');
expectStatus(['status' => 'suspended', 'expire_at' => '2026-09-24 14:43:07'], $now, 'suspended', 'billing status wins over expiry');
expectStatus(['status' => 'traffic_suspended', 'expire_at' => '2026-11-07 23:59:59'], $now, 'traffic_suspended', 'traffic suspension kept');
expectStatus(['status' => 'active', 'expire_at' => ''], $now, 'active', 'missing expiry never expires');
expectStatus(['status' => 'active'], $now, 'active', 'absent expiry never expires');
expectStatus(['status' => 'active', 'expire_at' => 'not-a-date'], $now, 'active', 'unparsable expiry never expires');

$previous = date_default_timezone_get();
date_default_timezone_set('PRC');
expectStatus(['status' => 'active', 'expire_at' => '2026-10-04 15:00:00'], $now, 'active', 'expire_at is read as UTC, not the PHP timezone');
date_default_timezone_set($previous);

echo "ok\n";
