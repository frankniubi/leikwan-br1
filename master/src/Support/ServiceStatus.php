<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class ServiceStatus
{
    public const EXPIRED = 'expired';

    /**
     * Nothing writes 'expired' to services.status: a service past expire_at
     * stays 'active' until billing suspends it, and a service that never went
     * through WHMCS is never suspended. Access already stops at expire_at, so
     * what the panel shows must stop there too.
     *
     * @param array<string, mixed> $service needs status and expire_at
     */
    public static function effective(array $service, ?int $now = null): string
    {
        $status = (string) ($service['status'] ?? '');
        if ($status !== 'active') {
            return $status;
        }
        $expiresAt = self::expiryTimestamp((string) ($service['expire_at'] ?? ''));
        return $expiresAt !== null && $expiresAt <= ($now ?? time()) ? self::EXPIRED : $status;
    }

    /**
     * services.expire_at is UTC; strtotime() would read it in date.timezone.
     */
    private static function expiryTimestamp(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }
}
