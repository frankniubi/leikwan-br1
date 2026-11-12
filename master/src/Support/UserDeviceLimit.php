<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Database\Database;

final class UserDeviceLimit
{
    /**
     * Resolve the effective online device limit for a client, considering both
     * the client's own limit and any user-level override.
     *
     * @param array<string, mixed> $client
     * @return int The effective limit (always >= 1)
     */
    public static function effectiveForClient(array $client, Database $db): int
    {
        $override = self::lookupOverride($client, $db);
        if ($override !== null) {
            return $override;
        }
        return max(1, (int) ($client['online_device_limit'] ?? 1));
    }

    /**
     * Return the override limit for a user, or null if no override is set.
     *
     * @param array<string, mixed> $user
     * @return int|null The override limit (always >= 1 if not null), or null if no override
     */
    public static function overrideForUser(array $user): ?int
    {
        $override = $user['online_device_limit'] ?? null;
        if ($override === null || $override === '') {
            return null;
        }
        return max(1, (int) $override);
    }

    /**
     * Determine the source of the effective limit for a client: 'user' if a
     * user-level override applies, 'client' otherwise.
     *
     * @param array<string, mixed> $client
     * @return string Either 'user' or 'client'
     */
    public static function sourceForClient(array $client, Database $db): string
    {
        $override = self::lookupOverride($client, $db);
        return $override !== null ? 'user' : 'client';
    }

    /**
     * Look up the user-level override for a client, if one exists.
     *
     * @param array<string, mixed> $client
     * @return int|null The override limit (always >= 1 if not null), or null if no override
     */
    private static function lookupOverride(array $client, Database $db): ?int
    {
        $ownerUserId = (int) ($client['owner_user_id'] ?? 0);
        if ($ownerUserId <= 0) {
            return null;
        }

        try {
            $row = $db->fetchOne(
                'SELECT online_device_limit FROM users WHERE id = :id LIMIT 1',
                ['id' => $ownerUserId]
            );
            if ($row === null || !isset($row['online_device_limit'])) {
                return null;
            }
            $override = $row['online_device_limit'];
            if ($override === null || $override === '') {
                return null;
            }
            return max(1, (int) $override);
        } catch (\Throwable) {
            // Defensive: the column may not exist pre-migration, e.g. in tests
            // with hand-rolled schemas. A missing column must NOT 500 the pull endpoint.
            return null;
        }
    }
}
