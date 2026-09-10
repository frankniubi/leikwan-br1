<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Database\Database;
use Leikwan\Master\Http\Request;

final class SessionAuth
{
    public static function boot(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function login(array $user): void
    {
        self::boot();
        session_regenerate_id(true);
        $_SESSION['auth'] = [
            'user_id' => (int) $user['id'],
            'role' => (string) $user['role'],
        ];
    }

    public static function logout(): void
    {
        self::boot();
        unset($_SESSION['auth']);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function identity(): ?array
    {
        self::boot();
        $identity = $_SESSION['auth'] ?? null;
        return is_array($identity) ? $identity : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function currentUser(Database $db): ?array
    {
        $identity = self::identity();
        if ($identity === null) {
            return null;
        }

        $user = $db->fetchOne('SELECT * FROM users WHERE id = :id LIMIT 1', ['id' => $identity['user_id']]);
        if ($user === null || (string) ($user['status'] ?? '') !== 'active') {
            self::logout();
            return null;
        }
        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    public static function requireRole(Database $db, string ...$roles): array
    {
        $user = self::currentUser($db);
        if ($user === null || !in_array((string) $user['role'], $roles, true)) {
            throw new ApiException('session_auth_failed', 401);
        }
        return $user;
    }

    public static function csrfToken(): string
    {
        self::boot();
        if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
        }
        return $_SESSION['csrf_token'];
    }

    public static function validateCsrf(Request $request): void
    {
        self::boot();
        $token = (string) $request->input('csrf_token', '');
        $expected = (string) ($_SESSION['csrf_token'] ?? '');
        if ($token === '' || $expected === '' || !hash_equals($expected, $token)) {
            throw new ApiException('csrf_validation_failed', 419);
        }
    }

    public static function flash(string $type, string $message): void
    {
        self::boot();
        $_SESSION['flash'] = [
            'type' => $type,
            'message' => $message,
        ];
    }

    /**
     * @return array<string, string>|null
     */
    public static function consumeFlash(): ?array
    {
        self::boot();
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return is_array($flash) ? $flash : null;
    }
}
