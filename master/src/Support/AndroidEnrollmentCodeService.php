<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Database\Database;

final class AndroidEnrollmentCodeService
{
    private const TTL_SECONDS = 300;

    public function __construct(private Database $db)
    {
    }

    /**
     * @return array{code:string,expires_at:string}
     */
    public function issue(int $clientId, ?int $actorId = null): array
    {
        if ($clientId <= 0) {
            throw new ApiException('client_not_found', 404);
        }

        $code = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $expiresAt = gmdate('Y-m-d H:i:s', time() + self::TTL_SECONDS);
        $this->db->insert('android_enrollment_codes', [
            'client_id' => $clientId,
            'code_hash' => hash('sha256', $code),
            'expires_at' => $expiresAt,
            'consumed_at' => null,
            'created_by_user_id' => $actorId,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return ['code' => $code, 'expires_at' => $expiresAt];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActive(string $code): ?array
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }
        $row = $this->db->fetchOne(
            'SELECT * FROM android_enrollment_codes WHERE code_hash = :code_hash LIMIT 1',
            ['code_hash' => hash('sha256', $code)]
        );
        if ($row === null || ($row['consumed_at'] ?? null) !== null || !$this->isFuture($row['expires_at'] ?? null)) {
            return null;
        }
        return $row;
    }

    /**
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
                'UPDATE android_enrollment_codes
                 SET consumed_at = :consumed_at
                 WHERE id = :id AND consumed_at IS NULL',
                [
                    'id' => (int) $row['id'],
                    'consumed_at' => gmdate('Y-m-d H:i:s'),
                ]
            );
            return $updated === 1 ? $row : null;
        });
    }

    private function isFuture(mixed $value): bool
    {
        $timestamp = is_string($value) ? strtotime($value . ' UTC') : false;
        return $timestamp !== false && $timestamp > time();
    }
}
