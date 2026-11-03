<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Http\Response;

/**
 * Session cookie hardening and the default browser security headers.
 *
 * Every session_start() in the panel goes through startSession() so the
 * PHPSESSID cookie is always HttpOnly + SameSite=Lax (+ Secure on HTTPS), and
 * the front controller adds securityHeaders() to every response it sends.
 *
 * This file is also required directly (before the autoloader exists) by
 * public/index.php and public/install.php, so it must not depend on other
 * classes at load time.
 */
final class HttpSecurity
{
    /**
     * The panel is never meant to be framed (no iframe/embed consumer exists),
     * so framing is denied outright. A script-src CSP is deliberately NOT set:
     * the server-rendered pages rely on inline scripts.
     *
     * @var array<string, string>
     */
    private const SECURITY_HEADERS = [
        'X-Frame-Options' => 'DENY',
        'Content-Security-Policy' => "frame-ancestors 'none'",
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'same-origin',
    ];

    /**
     * True when the browser reached us over HTTPS.
     *
     * Forwarded-scheme headers (X-Forwarded-Proto from Cloudflare/nginx,
     * Cloudflare's CF-Visitor) are honoured without a trusted-proxy check on
     * purpose: they can only ever turn the Secure flag ON. A client spoofing
     * them only makes its own cookie stricter, and a victim's browser never
     * sends attacker-chosen headers, so trust is not needed in this direction.
     *
     * @param array<string, mixed> $server
     */
    public static function requestIsHttps(array $server): bool
    {
        $https = strtolower(trim((string) ($server['HTTPS'] ?? '')));
        if ($https !== '' && $https !== 'off') {
            return true;
        }
        if (strtolower(trim((string) ($server['REQUEST_SCHEME'] ?? ''))) === 'https') {
            return true;
        }
        if (trim((string) ($server['SERVER_PORT'] ?? '')) === '443') {
            return true;
        }
        $forwardedProto = strtolower(trim(explode(',', (string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''), 2)[0]));
        if ($forwardedProto === 'https') {
            return true;
        }
        $cfVisitor = strtolower((string) ($server['HTTP_CF_VISITOR'] ?? ''));
        return preg_match('/"scheme"\s*:\s*"https"/', $cfVisitor) === 1;
    }

    /**
     * @param array<string, mixed> $server
     * @return array{lifetime:int, path:string, domain:string, secure:bool, httponly:bool, samesite:string}
     */
    public static function sessionCookieParams(array $server): array
    {
        return [
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => self::requestIsHttps($server),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    /** @return array<string, string> */
    public static function sessionIniSettings(): array
    {
        return [
            'session.use_strict_mode' => '1',
            'session.use_only_cookies' => '1',
            'session.use_trans_sid' => '0',
            'session.cookie_httponly' => '1',
            'session.cookie_samesite' => 'Lax',
        ];
    }

    /**
     * Hardened replacement for a bare session_start().
     *
     * @param array<string, mixed>|null $server defaults to $_SERVER
     */
    public static function startSession(?array $server = null): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return true;
        }
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            foreach (self::sessionIniSettings() as $key => $value) {
                ini_set($key, $value);
            }
            session_set_cookie_params(self::sessionCookieParams($server ?? $_SERVER));
        }

        return session_start();
    }

    /** @return array<string, string> */
    public static function securityHeaders(): array
    {
        return self::SECURITY_HEADERS;
    }

    /**
     * Adds the default security headers to a response without overriding a
     * header the controller already chose (header names compared
     * case-insensitively).
     */
    public static function withSecurityHeaders(Response $response): Response
    {
        $present = [];
        foreach (array_keys($response->headers()) as $name) {
            $present[strtolower((string) $name)] = true;
        }
        $missing = [];
        foreach (self::SECURITY_HEADERS as $name => $value) {
            if (!isset($present[strtolower($name)])) {
                $missing[$name] = $value;
            }
        }

        return $missing === [] ? $response : $response->withHeaders($missing);
    }

    /** Drops the PHP version banner; harmless if expose_php is already off. */
    public static function removeServerBanner(): void
    {
        if (!headers_sent()) {
            header_remove('X-Powered-By');
        }
    }
}
