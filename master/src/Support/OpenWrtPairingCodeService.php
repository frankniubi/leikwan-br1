<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;

/**
 * One-time OpenWrt router pairing codes.
 *
 * A router has no hardware-backed key attestation, so onboarding is gated by a
 * short-lived, single-use code that an operator hands out from the panel. Only
 * the SHA-256 hash of the code is persisted; the plaintext exists exactly once,
 * in the issuing response, and is never recoverable afterwards.
 *
 * Scope is a single client_id: redeeming a code can only ever bind a device to
 * the client the code was issued for, whatever the request claims.
 */
final class OpenWrtPairingCodeService
{
    public const TTL_SECONDS = 900;
    public const MIN_TTL_SECONDS = 60;
    public const MAX_TTL_SECONDS = 86400;

    /** Ambiguous glyphs (I, O, 0, 1) are excluded so codes survive being read aloud or retyped. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const CODE_LENGTH = 8;

    public function __construct(private Database $db, private Config $config)
    {
    }

    public function enabled(): bool
    {
        return $this->config->getBool('OPENWRT_PAIRING_ENABLED', false);
    }

    public function assertAvailable(): void
    {
        if (!$this->enabled()) {
            throw new ApiException('openwrt_pairing_unavailable', 503);
        }
    }

    /**
     * Issue a fresh single-use pairing code for exactly one client.
     *
     * @return array{code:string,client_id:int,expires_at:string}
     */
    public function issue(int $clientId, ?int $actorId = null, ?int $ttlSeconds = null): array
    {
        if ($clientId <= 0) {
            throw new ApiException('client_not_found', 404);
        }

        $ttl = $ttlSeconds === null
            ? self::TTL_SECONDS
            : max(self::MIN_TTL_SECONDS, min(self::MAX_TTL_SECONDS, $ttlSeconds));
        $code = $this->generateCode();
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttl);
        $this->db->insert('openwrt_pairing_codes', [
            'client_id' => $clientId,
            'code_hash' => $this->hashCode((string) $this->canonicalCode($code)),
            'expires_at' => $expiresAt,
            'used_at' => null,
            'created_by_user_id' => $actorId,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return ['code' => $code, 'client_id' => $clientId, 'expires_at' => $expiresAt];
    }

    /**
     * Look up a usable (unused, unexpired) code. Malformed input is a miss.
     *
     * @return array<string, mixed>|null
     */
    public function findActive(string $code): ?array
    {
        $row = $this->findByCode($code);
        if ($row === null || $this->isUsed($row) || $this->isExpired($row)) {
            return null;
        }

        return $row;
    }

    /**
     * Raw hash lookup. Separated from findActive so the caller can tell an
     * expired code apart from an unknown or already-redeemed one without
     * weakening the response for unknown codes.
     *
     * @return array<string, mixed>|null
     */
    public function findByCode(string $code): ?array
    {
        $canonical = $this->canonicalCode($code);
        if ($canonical === null) {
            return null;
        }

        $hash = $this->hashCode($canonical);
        $row = $this->db->fetchOne(
            'SELECT * FROM openwrt_pairing_codes WHERE code_hash = :code_hash LIMIT 1',
            ['code_hash' => $hash]
        );
        if ($row === null) {
            return null;
        }

        // The unique index narrows the candidate row; the comparison below is
        // constant time and never leaks how much of the hash matched.
        if (!hash_equals((string) ($row['code_hash'] ?? ''), $hash)) {
            return null;
        }

        return $row;
    }

    /** @param array<string, mixed> $row */
    public function isUsed(array $row): bool
    {
        return ($row['used_at'] ?? null) !== null;
    }

    /** @param array<string, mixed> $row */
    public function isExpired(array $row): bool
    {
        return !$this->isFuture($row['expires_at'] ?? null);
    }

    /**
     * Atomically claim a code. Returns the claimed row, or null when the code
     * is unknown, already used, or expired. Two concurrent redemptions can
     * never both succeed: the compare-and-swap happens inside a transaction.
     *
     * @return array<string, mixed>|null
     */
    public function consume(string $code): ?array
    {
        return $this->db->transaction(function () use ($code): ?array {
            $row = $this->findActive($code);
            if ($row === null) {
                return null;
            }

            $updated = $this->db->execute(
                'UPDATE openwrt_pairing_codes
                 SET used_at = :used_at
                 WHERE id = :id AND used_at IS NULL',
                [
                    'id' => (int) $row['id'],
                    'used_at' => gmdate('Y-m-d H:i:s'),
                ]
            );

            return $updated === 1 ? $row : null;
        });
    }

    /**
     * Canonical form of a user-supplied code: uppercase, separators removed.
     * Returns null when the input cannot be a code this service issued, so a
     * malformed value can never be treated as a near match.
     */
    public function canonicalCode(string $code): ?string
    {
        $normalized = strtoupper(trim($code));
        $normalized = preg_replace('/[^A-Z0-9]/', '', $normalized) ?? '';
        if (strlen($normalized) !== self::CODE_LENGTH) {
            return null;
        }
        for ($index = 0; $index < self::CODE_LENGTH; $index++) {
            if (strpos(self::ALPHABET, $normalized[$index]) === false) {
                return null;
            }
        }

        return $normalized;
    }

    private function hashCode(string $canonicalCode): string
    {
        return hash('sha256', $canonicalCode);
    }

    private function generateCode(): string
    {
        $raw = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($index = 0; $index < self::CODE_LENGTH; $index++) {
            $raw .= self::ALPHABET[random_int(0, $max)];
        }

        return substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
    }

    private function isFuture(mixed $value): bool
    {
        $timestamp = is_string($value) ? strtotime($value . ' UTC') : false;
        return $timestamp !== false && $timestamp > time();
    }
}
