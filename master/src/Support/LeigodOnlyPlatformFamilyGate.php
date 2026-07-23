<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use InvalidArgumentException;

final class LeigodOnlyPlatformFamilyGate
{
    /** @var list<string> */
    private const SUPPORTED = ['linux-bond', 'windows-bond'];

    public static function assertSupported(string $platformFamily, string $context): void
    {
        if (self::isSupported($platformFamily)) {
            return;
        }

        throw new InvalidArgumentException(
            'unsupported_platform_family:' . $platformFamily . ' context=' . $context
        );
    }

    public static function isSupported(string $platformFamily): bool
    {
        return in_array($platformFamily, self::SUPPORTED, true);
    }
}
