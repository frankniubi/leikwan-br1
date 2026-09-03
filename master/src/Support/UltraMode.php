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
    public const NATIVE_ROUTE_SCOPE_CN_ONLY = 'cn_only';
    public const NATIVE_ROUTE_SCOPE_INTL_ONLY = 'intl_only';
    public const NATIVE_ROUTE_SCOPE_GLOBAL = 'global';
    public const NATIVE_ROUTE_POLICY_RESTRICTED = 'restricted';
    public const NATIVE_ROUTE_POLICY_UNRESTRICTED = 'unrestricted';
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
        $redacted = preg_replace('/Leigod|\x{96f7}\x{795e}/iu', 'Ultra', $value);
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

    /**
     * Versioned digest used by clients that explicitly opt into a route
     * policy.  The legacy v1 digest above remains valid for restricted leases
     * so older Android/MacOS clients are not invalidated by a Panel upgrade.
     */
    public static function nativeScopeDigestV2(
        int $forwardId,
        string $direction,
        string $transport,
        string $routeScope,
        string $routePolicy,
    ): string {
        $normalizedPolicy = self::normalizeNativeRoutePolicy($routePolicy);
        if ($normalizedPolicy === null) {
            throw new \InvalidArgumentException('invalid native route policy');
        }
        return hash('sha256', implode("\n", [
            'native-outbound:v2',
            $forwardId,
            strtolower(trim($direction)),
            strtolower(trim($transport)),
            strtolower(trim($routeScope)),
            $normalizedPolicy,
        ]));
    }

    public static function normalizeNativeRoutePolicy(mixed $value): ?string
    {
        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return self::NATIVE_ROUTE_POLICY_RESTRICTED;
        }
        return in_array($normalized, [
            self::NATIVE_ROUTE_POLICY_RESTRICTED,
            self::NATIVE_ROUTE_POLICY_UNRESTRICTED,
        ], true) ? $normalized : null;
    }

    /** @return array<int, string> */
    public static function nativeRouteScopesForDirection(string $direction): array
    {
        if (self::isInternationalToMainlandChina($direction)) {
            return [self::NATIVE_ROUTE_SCOPE_CN_ONLY, self::NATIVE_ROUTE_SCOPE_GLOBAL];
        }
        if (self::isMainlandChinaToInternational($direction)) {
            return [self::NATIVE_ROUTE_SCOPE_INTL_ONLY, self::NATIVE_ROUTE_SCOPE_GLOBAL];
        }
        return [self::NATIVE_ROUTE_SCOPE_GLOBAL];
    }

    public static function normalizeNativeRouteScope(string $scope, string $direction): ?string
    {
        $scope = strtolower(trim($scope));
        // Accept longer aliases at the boundary, but emit one canonical value
        // so clients and digests never diverge.
        $scope = match ($scope) {
            'international_only', 'overseas_only' => self::NATIVE_ROUTE_SCOPE_INTL_ONLY,
            'mainland_only', 'china_only' => self::NATIVE_ROUTE_SCOPE_CN_ONLY,
            default => $scope,
        };
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

        $normalized = self::normalizeDirectionText($raw);
        $mainland = self::mainlandDirectionToken();
        $internationalSource = self::internationalDirectionToken();
        return preg_match('/' . $internationalSource . '\s+to\s+' . $mainland . '(?:\s|$)/iu', $normalized) === 1;
    }

    public static function isMainlandChinaToInternational(string $direction): bool
    {
        $raw = trim($direction);
        if ($raw === '') {
            return false;
        }
        $compact = strtolower((string) preg_replace('/[^a-z0-9]+/', '_', $raw));
        $compact = trim($compact, '_');
        if (in_array($compact, [
            'cn_to_intl', 'cn_to_international', 'cn_to_overseas', 'cn_to_foreign',
            'mainland_to_intl', 'mainland_to_international', 'mainland_to_overseas',
            'mainland_china_to_intl', 'mainland_china_to_international',
            'china_to_intl', 'china_to_international', 'domestic_to_international',
        ], true)) {
            return true;
        }

        $normalized = self::normalizeDirectionText($raw);
        $mainland = self::mainlandDirectionToken();
        $internationalDestination = self::internationalDirectionToken();
        return preg_match('/' . $mainland . '\s+to\s+' . $internationalDestination . '(?:\s|$)/iu', $normalized) === 1;
    }

    private static function normalizeDirectionText(string $direction): string
    {
        $normalized = strtolower(trim($direction));
        $normalized = (string) preg_replace(
            '/\s*(?:<=>|<->|->|=>|[\x{2192}\x{21d2}\x{2014}\x{2013}\x{ff0d}])\s*/u',
            ' to ',
            $normalized,
        );
        $normalized = (string) preg_replace('/[\x{81f3}\x{5230}\x{5f80}]/u', ' to ', $normalized);
        return trim((string) preg_replace('/\s+/', ' ', $normalized));
    }

    private static function mainlandDirectionToken(): string
    {
        // Ultra combinations commonly use IATA-style labels (for example
        // SHA -> TYO) instead of spelling out the country. Keep this list
        // deliberately limited to mainland airports so a short code cannot
        // accidentally turn an unrelated carrier into a restricted scope.
        return '(?:mainland(?:\s+china)?|china\s+mainland|china|cn|'
            . 'sha|shanghai|pek|beijing|bjs|can|guangzhou|szx|shenzhen|'
            . 'foc|xiamen|hgh|hangzhou|ctu|chengdu|ckg|chongqing|'
            . 'nkg|nanjing|wuh|wuhan|tsn|tianjin|cgo|zhengzhou|'
            . 'khn|nanchang|hrb|harbin|xiy|xian|xi-an|'
            . '\x{4e2d}\x{56fd}\x{5927}\x{9646}|'
            . '\x{4e2d}\x{56fd}\x{5185}\x{5730}|'
            . '\x{5927}\x{9646}|\x{5185}\x{5730})';
    }

    private static function internationalDirectionToken(): string
    {
        // Include the destination codes used by the existing Ultra catalog:
        // HKG, TYO, SIN, ICN, FRA, MOS, TPE and LAX. Longer names remain
        // supported for human-authored directions.
        $alternatives = 'hong\s*kong|hk|hkg|macau|macao|taiwan|tpe|japan|tyo|tokyo|'
            . 'korea|icn|seoul|south\s+korea|singapore|sin|frankfurt|fra|'
            . 'moscow|mos|overseas|international|intl|foreign|abroad|outside\s+china|'
            . 'los\s+angeles|lax|san\s+francisco|sfo|new\s+york|jfk|london|lhr|'
            . 'paris|ams|amsterdam|dubai|dxb|sydney|syd|melbourne|mel|'
            . 'us|usa|uk|gb|de|ger|ru|jp|jpn|kr|kor|sg|au|aus|ca|fr|it|es|'
            . 'nl|ae|in|vn|th|my|id|ph|br|mx|nz|ch|se|no|fi|dk|pl|tr|il|'
            . '\x{9999}\x{6e2f}|\x{6fb3}\x{95e8}|\x{53f0}\x{6e7e}|'
            . '\x{65e5}\x{672c}|\x{97e9}\x{56fd}|\x{65b0}\x{52a0}\x{5761}|'
            . '\x{6d77}\x{5916}|\x{5883}\x{5916}|\x{56fd}\x{9645}|\x{56fd}\x{5916}';
        // Do not treat every short code as foreign: mainland pairs such as
        // SHA -> CAN must remain unrestricted until their direction is known.
        return '(?:' . $alternatives . ')';
    }
}
