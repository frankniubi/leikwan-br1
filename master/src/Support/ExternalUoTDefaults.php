<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Config;

/**
 * Application defaults for the operator-owned external UoT carrier.
 *
 * The target map is deliberately configuration-driven so a panel can move a
 * sidecar without changing application code.  The Tokyo endpoint is retained
 * as a last-resort bootstrap target for a fresh panel; production panels
 * should set EXTERNAL_UOT_DEFAULT_TARGETS_JSON with their regional endpoints.
 */
final class ExternalUoTDefaults
{
    public const ENABLED_KEY = 'EXTERNAL_UOT_DEFAULT_ENABLED';
    public const TARGET_KEY = 'EXTERNAL_UOT_DEFAULT_TARGET';
    public const TARGETS_JSON_KEY = 'EXTERNAL_UOT_DEFAULT_TARGETS_JSON';
    public const BOOTSTRAP_TARGET = '185.220.238.143:7443';

    public static function enabled(Config $config): bool
    {
        return $config->getBool(self::ENABLED_KEY, true);
    }

    public static function targetForZone(Config $config, string $zone): string
    {
        $normalizedZone = strtolower(trim($zone));
        $targets = self::targets($config);
        if ($normalizedZone !== '' && isset($targets[$normalizedZone])) {
            return $targets[$normalizedZone];
        }

        $fallback = trim((string) ($config->get(self::TARGET_KEY, '') ?? ''));
        return $fallback !== '' ? $fallback : self::BOOTSTRAP_TARGET;
    }

    /** @return array<string, string> */
    public static function targets(Config $config): array
    {
        $raw = trim((string) ($config->get(self::TARGETS_JSON_KEY, '') ?? ''));
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $targets = [];
        foreach ($decoded as $zone => $target) {
            $zone = strtolower(trim((string) $zone));
            $target = trim((string) $target);
            if ($zone !== '' && $target !== '') {
                $targets[$zone] = $target;
            }
        }
        return $targets;
    }

    public static function generatedToken(): string
    {
        return bin2hex(random_bytes(24));
    }
}
