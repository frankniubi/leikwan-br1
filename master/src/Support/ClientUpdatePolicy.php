<?php

declare(strict_types=1);

namespace Leikwan\Master\Support;

/**
 * Per-platform minimum client version, carried in the configs/pull response as
 * `client_update`. Only the GUI clients that ship the update gate get it
 * (Windows, macOS, Android, iOS). Linux, OpenWrt and anything that does not send a
 * recognised X-Device-Platform get nothing, so their responses stay as they were.
 *
 * Config (CLIENT_MINIMUM_VERSIONS_JSON), empty by default so nothing changes until set:
 *   {"windows-desktop":"0.1.20.69","macos-desktop":"1.0.59","android-host":"0.2.45","ios":52}
 * iOS is compared by build number because every TestFlight build reports version 1.0.
 */
final class ClientUpdatePolicy
{
    public const CONFIG_KEY = 'CLIENT_MINIMUM_VERSIONS_JSON';

    private const VERSION_PLATFORMS = ['windows-desktop', 'macos-desktop', 'android-host'];

    /** @return array<string, int|string>|null */
    public static function forPlatform(string $platform, string $configJson): ?array
    {
        $platform = strtolower(trim($platform));
        $decoded = json_decode(trim($configJson), true);
        if ($platform === '' || !is_array($decoded) || !array_key_exists($platform, $decoded)) {
            return null;
        }
        $value = $decoded[$platform];

        if (in_array($platform, self::VERSION_PLATFORMS, true)) {
            $version = is_string($value) ? trim($value) : '';
            return preg_match('/^\d+(\.\d+){0,3}$/', $version) === 1 ? ['minimum_version' => $version] : null;
        }
        if ($platform === 'ios') {
            $build = is_array($value) ? ($value['build'] ?? null) : $value;
            if (is_string($build) && preg_match('/^\d{1,9}$/', trim($build)) === 1) {
                $build = (int) trim($build);
            }
            return is_int($build) && $build > 0 && $build <= 999999999 ? ['minimum_build' => $build] : null;
        }
        return null;
    }

    /** Folded into the config revision so clients long-polling with since_revision see a change. */
    public static function revisionMarker(string $configJson): string
    {
        $configJson = trim($configJson);
        return $configJson === '' ? '' : hash('sha256', $configJson);
    }
}
