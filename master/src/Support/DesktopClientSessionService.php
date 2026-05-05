<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;

final class DesktopClientSessionService
{
    public function __construct(private Database $db, private Config $config)
    {
    }

    /** @return array<string, mixed> */
    public function issueForUser(int $userId, string $email, string $username): array
    {
        $sessionToken = bin2hex(random_bytes(24));
        $now = gmdate('Y-m-d H:i:s');
        $expiresAt = gmdate('Y-m-d H:i:s', time() + 86400 * 30);

        $this->db->execute(
            'INSERT INTO desktop_client_sessions (session_token, user_id, email, username, expires_at, created_at, updated_at)
             VALUES (:session_token, :user_id, :email, :username, :expires_at, :created_at, :updated_at)',
            [
                'session_token' => $sessionToken,
                'user_id' => $userId,
                'email' => $email,
                'username' => $username,
                'expires_at' => $expiresAt,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        return [
            'session_token' => $sessionToken,
            'user_id' => $userId,
            'email' => $email,
            'username' => $username,
            'expires_at' => $expiresAt,
        ];
    }

    /** @return array<string, mixed>|null */
    public function findActive(string $sessionToken): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM desktop_client_sessions WHERE session_token = :session_token AND expires_at > :now LIMIT 1',
            [
                'session_token' => $sessionToken,
                'now' => gmdate('Y-m-d H:i:s'),
            ]
        );
    }
}
