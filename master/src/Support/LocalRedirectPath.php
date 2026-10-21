<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

/**
 * Validates "return to" paths used after sign-in (OIDC, WHMCS SSO).
 *
 * Only same-origin absolute paths are allowed. Browsers strip tab/CR/LF from
 * URLs and treat "\" like "/", so "/\t/evil.example" or "/\evil.example"
 * would become the protocol-relative "//evil.example". We therefore reject
 * any control byte, space or backslash, and require a single leading "/"
 * that is not followed by "/" or "\". The same checks run on the
 * percent-decoded form so double-encoded variants are refused as well.
 */
final class LocalRedirectPath
{
    public static function isSafe(string $path): bool
    {
        if ($path === '' || strlen($path) > 2048) {
            return false;
        }
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $path) === 1) {
            return false;
        }
        if (!self::hasSingleLeadingSlash($path)) {
            return false;
        }

        $decoded = rawurldecode($path);
        if (preg_match('/[\x00-\x1f\x7f\\\\]/', $decoded) === 1) {
            return false;
        }

        return self::hasSingleLeadingSlash($decoded);
    }

    public static function sanitize(string $path, string $fallback = '/'): string
    {
        $path = trim($path);
        return self::isSafe($path) ? $path : $fallback;
    }

    private static function hasSingleLeadingSlash(string $path): bool
    {
        if (!str_starts_with($path, '/')) {
            return false;
        }
        $second = $path[1] ?? '';
        return $second !== '/' && $second !== '\\';
    }
}
