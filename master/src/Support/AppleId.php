<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Database\Database;

/**
 * The Apple ID a tenant registered for the iOS TestFlight invite.
 *
 * The invite link is handed out per account, so the Apple ID the tenant types
 * is recorded once: it is what support traces when an invite is not accepted,
 * and the tenant sees the same value later on Account Security.
 */
final class AppleId
{
    /** Total characters of local@domain; users.apple_id is VARCHAR(191). */
    public const MAX_LENGTH = 191;

    /**
     * The Apple ID as it should be stored, or null when the input is not a
     * usable value. Apple IDs are email addresses or +-aliases; the syntactical
     * check stays deliberately loose because Apple also accepts addresses on
     * its own legacy domains.
     */
    public static function normalize(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $value = trim((string) $value);
        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            return null;
        }
        if (preg_match('/[\x00-\x20\x7f]/', $value) === 1) {
            return null;
        }
        // The domain needs at least one dot. Phone-number Apple IDs are not
        // accepted by TestFlight, so the value must look like an address.
        if (preg_match('/^[^@]+@[^@.]+(\.[^@.]+)+$/', $value) !== 1) {
            return null;
        }
        return strtolower($value);
    }

    /**
     * Store the Apple ID for a first-time submitter and return the stored
     * value. A second submitter (or a resubmission with a different address)
     * keeps the address already on record: one account, one Apple ID.
     *
     * @return array{apple_id:string, created:bool}
     */
    public static function claim(Database $db, int $userId, string $appleId): array
    {
        $normalized = self::normalize($appleId);
        if ($normalized === null) {
            throw new ApiException('apple_id_invalid', 422);
        }

        $existing = $db->fetchOne('SELECT apple_id FROM users WHERE id = :id LIMIT 1', ['id' => $userId]);
        if ($existing === null) {
            throw new ApiException('user_auth_failed', 401);
        }
        $recorded = self::normalize($existing['apple_id'] ?? '');
        if ($recorded !== null) {
            return ['apple_id' => $recorded, 'created' => false];
        }

        $db->execute(
            'UPDATE users
             SET apple_id = :apple_id,
                 apple_id_submitted_at = :submitted_at,
                 updated_at = :updated_at
             WHERE id = :id AND apple_id IS NULL',
            [
                'apple_id' => $normalized,
                'submitted_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
                'id' => $userId,
            ]
        );

        // The guarded UPDATE keeps the first claim when two requests race; read
        // back so both callers report the same address.
        $stored = $db->fetchOne('SELECT apple_id FROM users WHERE id = :id LIMIT 1', ['id' => $userId]);
        $final = self::normalize($stored['apple_id'] ?? '');
        if ($final === null) {
            throw new ApiException('apple_id_save_failed', 500);
        }
        return ['apple_id' => $final, 'created' => true];
    }

    /**
     * Whether the account holds a valid package right now. Status alone is not
     * enough: nothing writes 'expired' to services.status (see ServiceStatus),
     * so a lapsed package still reads 'active' in the table.
     *
     * @param array<int, array<string, mixed>> $services rows with status + expire_at
     */
    public static function hasValidMembership(array $services): bool
    {
        foreach ($services as $service) {
            if (ServiceStatus::effective($service) === 'active') {
                return true;
            }
        }
        return false;
    }
}
