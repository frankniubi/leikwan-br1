<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

final class UltraMode
{
    public const BACKEND_MODE = 'ultra';
    public const CONTROL_PLANE_GROUP = 'ultra-control-plane';
    public const CAPABILITY_PROTOCOL = 'leikwan-ultra-capability-v1';
    public const POLICY_PROTOCOL = 'leikwan-ultra-policy-v2';
    public const LEASE_ENDPOINT = '/api/client/ultra/lease';
    public const MAX_POLICY_TTL_SECONDS = 300;
    public const MIN_POLICY_TTL_SECONDS = 60;
    public const MAX_QUOTA_LEASE_BYTES = 107374182400;
    public const ACCESS_FIELD = 'ultra_access';
    public const CACHE_KEY = 'ULTRA_CACHE_KEY';
    public const TOKEN_KEY = 'ULTRA_TOKEN_KEY';
    public const SOURCE_ID_KEY = 'ULTRA_SOURCE_ID';
    public const SOURCE_AUTH_KEY = 'ULTRA_SOURCE_AUTH_TOKEN';
    public const MATERIAL_MAX_TTL_KEY = 'ULTRA_MATERIAL_MAX_TTL_SECONDS';
    public const MAX_TTL_KEY = 'ULTRA_MAX_TTL_SECONDS';
    public const REFRESH_SKEW_KEY = 'ULTRA_REFRESH_SKEW_SECONDS';

    public static function isBackendMode(mixed $value): bool
    {
        return strtolower(trim((string) $value)) === self::BACKEND_MODE;
    }
}
