<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

final class UltraMode
{
    public const BACKEND_MODE = 'ultra';
    public const CONTROL_PLANE_GROUP = 'ultra-control-plane';
    public const CAPABILITY_PROTOCOL = 'leikwan-ultra-capability-v1';
    public const POLICY_PROTOCOL = 'leikwan-ultra-policy-v2';
    public const NATIVE_OUTBOUND_PURPOSE = 'native_outbound';
    public const NATIVE_SOCKS5_PROTOCOL = 'leikwan-ultra-native-socks5-v1';
    public const LEASE_ENDPOINT = '/api/client/ultra/lease';
    public const MIN_POLICY_TTL_SECONDS = 60;
    public const MAX_POLICY_TTL_SECONDS = 300;
    public const MAX_QUOTA_LEASE_BYTES = 107374182400;
    public const ACCESS_FIELD = 'ultra_access';
    public const CACHE_KEY = 'ULTRA_CACHE_KEY';
    public const TOKEN_KEY = 'ULTRA_TOKEN_KEY';
    public const SOURCE_ID_KEY = 'ULTRA_SOURCE_ID';
    public const SOURCE_AUTH_KEY = 'ULTRA_SOURCE_AUTH_TOKEN';
    public const SOURCE_CREDENTIALS_KEY = 'ULTRA_SOURCE_CREDENTIALS_JSON';
    public const MATERIAL_MAX_TTL_KEY = 'ULTRA_MATERIAL_MAX_TTL_SECONDS';
    public const MAX_TTL_KEY = 'ULTRA_MAX_TTL_SECONDS';
    public const REFRESH_SKEW_KEY = 'ULTRA_REFRESH_SKEW_SECONDS';

    public static function isBackendMode(mixed $value): bool
    {
        return strtolower(trim((string) $value)) === self::BACKEND_MODE;
    }

    public static function redactProviderText(string $value): string
    {
        $redacted = preg_replace('/Leigod|雷神/iu', 'Ultra', $value);
        return is_string($redacted) ? $redacted : 'Ultra';
    }

    public static function nativeScopeDigest(
        int $forwardId,
        string $direction,
        string $transport,
        string $routeScope,
    ): string {
        return hash('sha256', implode("\n", [
            'native-outbound:v1',
            $forwardId,
            strtolower(trim($direction)),
            strtolower(trim($transport)),
            strtolower(trim($routeScope)),
        ]));
    }

    /** @return array<int, string> */
    public static function nativeRouteScopesForDirection(string $direction): array
    {
        return self::isInternationalToMainlandChina($direction)
            ? ['cn_only', 'global']
            : ['global'];
    }

    public static function normalizeNativeRouteScope(string $scope, string $direction): ?string
    {
        $scope = strtolower(trim($scope));
        return in_array($scope, self::nativeRouteScopesForDirection($direction), true) ? $scope : null;
    }

    public static function isInternationalToMainlandChina(string $direction): bool
    {
        $raw = trim($direction);
        if ($raw === '') {
            return false;
        }
        $compact = strtolower((string) preg_replace('/[^a-z0-9]+/', '_', $raw));
        $compact = trim($compact, '_');
        if (in_array($compact, [
            'intl_to_cn', 'international_to_cn', 'intl_to_mainland',
            'international_to_mainland_china', 'hk_to_cn', 'hong_kong_to_cn',
            'hong_kong_to_mainland_china', 'overseas_to_cn',
        ], true)) {
            return true;
        }

        $normalized = strtolower($raw);
        $normalized = str_replace(['→', '⇒', '至', '到', '往', '前往'], ' to ', $normalized);
        $normalized = (string) preg_replace('/\s*(?:->|=>|—|–|－)\s*/u', ' to ', $normalized);
        $normalized = (string) preg_replace('/\s+/', ' ', $normalized);
        $mainland = '(?:mainland(?:\s+china)?|china\s+mainland|mainland|中国大陆|中国内地|大陆|内地|cn)';
        $internationalSource = '(?:hong\s*kong|hk|香港|macau|macao|澳门|taiwan|台湾|japan|日本|korea|韩国|singapore|新加坡|overseas|international|intl|海外|境外)';
        return preg_match('/' . $internationalSource . '\s+to\s+' . $mainland . '(?:\b|$)/iu', $normalized) === 1;
    }
}
