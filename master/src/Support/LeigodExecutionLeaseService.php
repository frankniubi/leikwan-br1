<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;

final class LeigodExecutionLeaseService
{
    /** @var callable():int */
    private $clock;

    public function __construct(
        private Config $config,
        private Database $db,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    /** @return array<string, mixed> */
    public function acquireForNode(int $catalogLineId, int $nodeId): array
    {
        return $this->acquire($catalogLineId, 'node', $nodeId, null);
    }

    /** @return array<string, mixed> */
    public function acquireForClientDevice(int $catalogLineId, int $clientId, string $deviceId): array
    {
        return $this->acquire($catalogLineId, 'client_device', $clientId, $this->deviceId($deviceId));
    }

    /**
     * @param array<int, int|string> $candidateCatalogIds
     * @return array<int, array<string, mixed>>
     */
    public function allocateForClientDevice(
        int $clientId,
        string $deviceId,
        array $candidateCatalogIds,
        int $desiredCount,
    ): array {
        $this->positiveOwnerId($clientId);
        $deviceId = $this->deviceId($deviceId);
        $desiredCount = max(0, min(64, $desiredCount));
        if ($desiredCount === 0) {
            return [];
        }

        $candidateIds = array_values(array_unique(array_filter(array_map(
            static fn(int|string $value): int => (int) $value,
            $candidateCatalogIds
        ), static fn(int $value): bool => $value > 0)));
        if ($candidateIds === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($candidateIds as $index => $candidateId) {
            $key = 'candidate_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $candidateId;
        }
        return $this->db->transaction(function () use (
            $placeholders,
            $params,
            $clientId,
            $deviceId,
            $desiredCount,
        ): array {
            $lines = $this->db->fetchAll(
                'SELECT * FROM leigod_lines
                 WHERE id IN (' . implode(', ', $placeholders) . ')
                   AND is_active = 1
                   AND allow_client_execution = 1
                 ORDER BY sort_order ASC, id ASC
                 FOR UPDATE',
                $params
            );
            $eligible = array_values(array_filter($lines, static fn(array $line): bool =>
                (int) ($line['id'] ?? 0) > 0
                && (int) ($line['is_active'] ?? 0) === 1
                && (int) ($line['allow_client_execution'] ?? 0) === 1
            ));

            $now = $this->now();
            $existingByLine = [];
            $allocated = [];
            foreach ($eligible as $line) {
                $lineId = (int) $line['id'];
                $existing = $this->leaseForLineUpdate($lineId, false);
                $existingByLine[$lineId] = $existing;
                if (is_array($existing)
                    && !$this->expired($existing, $now)
                    && (string) ($existing['state'] ?? '') !== 'revoking'
                    && $this->ownerMatches($existing, 'client_device', $clientId, $deviceId)) {
                    $allocated[] = $existing;
                    if (count($allocated) >= $desiredCount) {
                        return $allocated;
                    }
                }
            }

            foreach ($eligible as $line) {
                if (count($allocated) >= $desiredCount) {
                    break;
                }
                $lineId = (int) $line['id'];
                $existing = $existingByLine[$lineId] ?? null;
                if (is_array($existing) && !$this->expired($existing, $now)) {
                    continue;
                }
                $allocated[] = $this->acquireLocked(
                    $line,
                    $existing,
                    'client_device',
                    $clientId,
                    $deviceId,
                    $now,
                );
            }

            return $allocated;
        });
    }

    /** @return array<string, mixed> */
    public function renew(
        string $leaseId,
        int $generation,
        string $ownerType,
        int $ownerId,
        ?string $deviceId,
    ): array {
        [$ownerType, $ownerId, $deviceId] = $this->owner($ownerType, $ownerId, $deviceId);
        $leaseId = $this->leaseId($leaseId);
        $generation = $this->generation($generation);

        return $this->db->transaction(function () use ($leaseId, $generation, $ownerType, $ownerId, $deviceId): array {
            $lease = $this->leaseByIdForUpdate($leaseId);
            $this->assertFence($lease, $leaseId, $generation);
            $this->assertOwner($lease, $ownerType, $ownerId, $deviceId);
            $now = $this->now();
            if ($this->expired($lease, $now) || (string) ($lease['state'] ?? '') === 'revoking') {
                throw new ApiException('leigod_line_lease_expired', 409);
            }

            if ((string) ($lease['state'] ?? '') === 'active') {
                $lease['expires_at'] = $this->timestamp($now + $this->activeTtlSeconds());
            }
            $lease['last_renewed_at'] = $this->timestamp($now);
            $lease['updated_at'] = $this->timestamp($now);
            $this->updateLease((int) $lease['leigod_line_id'], $lease);
            return $lease;
        });
    }

    /**
     * @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    public function acceptHealth(int $catalogLineId, string $leaseId, int $generation, array $report): array
    {
        $leaseId = $this->leaseId($leaseId);
        $generation = $this->generation($generation);
        $ownerType = (string) ($report['owner_type'] ?? '');
        $ownerId = (int) ($report['owner_id'] ?? 0);
        $deviceId = array_key_exists('owner_device_id', $report) ? (string) $report['owner_device_id'] : null;
        [$ownerType, $ownerId, $deviceId] = $this->owner($ownerType, $ownerId, $deviceId);

        return $this->db->transaction(function () use (
            $catalogLineId,
            $leaseId,
            $generation,
            $ownerType,
            $ownerId,
            $deviceId,
            $report,
        ): array {
            $line = $this->lineForUpdate($catalogLineId);
            $lease = $this->leaseForLineUpdate($catalogLineId);
            $this->assertFence($lease, $leaseId, $generation);
            $this->assertOwner($lease, $ownerType, $ownerId, $deviceId);
            if (!hash_equals((string) ($line['config_sha256'] ?? ''), strtolower(trim((string) ($report['config_sha256'] ?? ''))))) {
                throw new ApiException('leigod_line_lease_config_mismatch', 409);
            }
            $now = $this->now();
            if ($this->expired($lease, $now) || (string) ($lease['state'] ?? '') === 'revoking') {
                throw new ApiException('leigod_line_lease_expired', 409);
            }
            if (($report['healthy'] ?? false) !== true) {
                return $lease;
            }

            $lease['state'] = 'active';
            $lease['expires_at'] = $this->timestamp($now + $this->activeTtlSeconds());
            $lease['last_renewed_at'] = $this->timestamp($now);
            $lease['updated_at'] = $this->timestamp($now);
            $this->updateLease($catalogLineId, $lease);
            return $lease;
        });
    }

    /** @return array<string, mixed> */
    public function revoke(string $leaseId, int $generation, string $reason): array
    {
        $leaseId = $this->leaseId($leaseId);
        $generation = $this->generation($generation);
        $reason = substr(trim($reason), 0, 191);

        return $this->db->transaction(function () use ($leaseId, $generation, $reason): array {
            $lease = $this->leaseByIdForUpdate($leaseId);
            $this->assertFence($lease, $leaseId, $generation);
            $now = $this->now();
            $lease['state'] = 'revoking';
            $lease['expires_at'] = $this->timestamp($now + $this->revokingGraceSeconds());
            $lease['updated_at'] = $this->timestamp($now);
            $this->updateLease((int) $lease['leigod_line_id'], $lease);
            $lease['revocation_reason'] = $reason;
            return $lease;
        });
    }

    /** @return array<int, array<string, mixed>> */
    public function activeForNode(int $nodeId): array
    {
        return $this->activeForOwner('node', $nodeId, null);
    }

    /** @return array<int, array<string, mixed>> */
    public function activeForClientDevice(int $clientId, string $deviceId): array
    {
        return $this->activeForOwner('client_device', $clientId, $this->deviceId($deviceId));
    }

    public function reapExpired(): int
    {
        $now = $this->now();
        $rows = $this->db->fetchAll(
            'SELECT * FROM leigod_execution_leases
             WHERE expires_at <= :now OR (state = :pending AND ready_deadline_at <= :now)
             ORDER BY leigod_line_id ASC',
            ['now' => $this->timestamp($now), 'pending' => 'pending']
        );
        $removed = 0;
        foreach ($rows as $row) {
            if (!$this->expired($row, $now)) {
                continue;
            }
            $lineId = (int) ($row['leigod_line_id'] ?? 0);
            if ($lineId <= 0) {
                continue;
            }
            $removed += $this->db->transaction(function () use ($lineId, $now): int {
                $current = $this->leaseForLineUpdate($lineId);
                if (!$this->expired($current, $now)) {
                    return 0;
                }
                return $this->db->execute(
                    'DELETE FROM leigod_execution_leases WHERE leigod_line_id = :line_id',
                    ['line_id' => $lineId]
                );
            });
        }
        return $removed;
    }

    /** @return array<string, mixed> */
    private function acquire(int $catalogLineId, string $ownerType, int $ownerId, ?string $deviceId): array
    {
        [$ownerType, $ownerId, $deviceId] = $this->owner($ownerType, $ownerId, $deviceId);
        return $this->db->transaction(function () use ($catalogLineId, $ownerType, $ownerId, $deviceId): array {
            $line = $this->lineForUpdate($catalogLineId);
            $existing = $this->leaseForLineUpdate($catalogLineId, false);
            return $this->acquireLocked($line, $existing, $ownerType, $ownerId, $deviceId, $this->now());
        });
    }

    /**
     * @param array<string, mixed> $line
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    private function acquireLocked(
        array $line,
        ?array $existing,
        string $ownerType,
        int $ownerId,
        ?string $deviceId,
        int $now,
    ): array {
        $catalogLineId = (int) ($line['id'] ?? 0);
        $this->assertLinePolicy($line, $ownerType);
        if (is_array($existing) && !$this->expired($existing, $now)) {
            if ($this->ownerMatches($existing, $ownerType, $ownerId, $deviceId)
                && (string) ($existing['state'] ?? '') !== 'revoking') {
                return $existing;
            }
            throw new ApiException('leigod_line_lease_conflict', 409, [
                'leigod_line_id' => $catalogLineId,
            ]);
        }

        $generation = is_array($existing) ? ((int) ($existing['generation'] ?? 0) + 1) : 1;
        $issuedAt = $this->timestamp($now);
        $readyDeadline = $this->timestamp($now + $this->pendingReadySeconds());
        $lease = [
            'leigod_line_id' => $catalogLineId,
            'lease_id' => $this->uuidV4(),
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'owner_device_id' => $deviceId,
            'generation' => $generation,
            'max_parallel_lanes' => max(1, min(4, (int) ($line['max_parallel_lanes'] ?? 2))),
            'state' => 'pending',
            'issued_at' => $issuedAt,
            'ready_deadline_at' => $readyDeadline,
            'expires_at' => $readyDeadline,
            'last_renewed_at' => $issuedAt,
            'updated_at' => $issuedAt,
        ];
        if ($existing === null) {
            $this->db->insert('leigod_execution_leases', $lease);
        } else {
            $this->updateLease($catalogLineId, $lease);
        }
        return $lease;
    }

    /** @return array<int, array<string, mixed>> */
    private function activeForOwner(string $ownerType, int $ownerId, ?string $deviceId): array
    {
        [$ownerType, $ownerId, $deviceId] = $this->owner($ownerType, $ownerId, $deviceId);
        $params = ['owner_type' => $ownerType, 'owner_id' => $ownerId, 'active' => 'active'];
        $deviceSql = 'AND owner_device_id IS NULL';
        if ($ownerType === 'client_device') {
            $params['owner_device_id'] = $deviceId;
            $deviceSql = 'AND owner_device_id = :owner_device_id';
        }
        $rows = $this->db->fetchAll(
            'SELECT * FROM leigod_execution_leases
             WHERE owner_type = :owner_type AND owner_id = :owner_id ' . $deviceSql . '
               AND state = :active
             ORDER BY leigod_line_id ASC',
            $params
        );
        $now = $this->now();
        return array_values(array_filter($rows, fn(array $row): bool =>
            (string) ($row['state'] ?? '') === 'active' && !$this->expired($row, $now)
        ));
    }

    /** @return array<string, mixed> */
    private function lineForUpdate(int $catalogLineId): array
    {
        if ($catalogLineId <= 0) {
            throw new ApiException('leigod_line_not_found', 404);
        }
        $line = $this->db->fetchOne(
            'SELECT * FROM leigod_lines WHERE id = :line_id LIMIT 1 FOR UPDATE',
            ['line_id' => $catalogLineId]
        );
        if (!is_array($line) || (int) ($line['is_active'] ?? 0) !== 1) {
            throw new ApiException('leigod_line_not_found', 404, ['id' => $catalogLineId]);
        }
        return $line;
    }

    /** @return array<string, mixed>|null */
    private function leaseForLineUpdate(int $catalogLineId, bool $required = true): ?array
    {
        $lease = $this->db->fetchOne(
            'SELECT * FROM leigod_execution_leases WHERE leigod_line_id = :line_id LIMIT 1 FOR UPDATE',
            ['line_id' => $catalogLineId]
        );
        if ($required && !is_array($lease)) {
            throw new ApiException('leigod_line_lease_not_found', 404);
        }
        return $lease;
    }

    /** @return array<string, mixed> */
    private function leaseByIdForUpdate(string $leaseId): array
    {
        $lease = $this->db->fetchOne(
            'SELECT * FROM leigod_execution_leases WHERE lease_id = :lease_id LIMIT 1 FOR UPDATE',
            ['lease_id' => $leaseId]
        );
        if (!is_array($lease)) {
            throw new ApiException('leigod_line_lease_not_found', 404);
        }
        return $lease;
    }

    /** @param array<string, mixed> $lease */
    private function updateLease(int $catalogLineId, array $lease): void
    {
        $this->db->execute(
            'UPDATE leigod_execution_leases
             SET lease_id = :lease_id,
                 owner_type = :owner_type,
                 owner_id = :owner_id,
                 owner_device_id = :owner_device_id,
                 generation = :generation,
                 max_parallel_lanes = :max_parallel_lanes,
                 state = :state,
                 issued_at = :issued_at,
                 ready_deadline_at = :ready_deadline_at,
                 expires_at = :expires_at,
                 last_renewed_at = :last_renewed_at,
                 updated_at = :updated_at
             WHERE leigod_line_id = :line_id',
            [
                'line_id' => $catalogLineId,
                'lease_id' => (string) $lease['lease_id'],
                'owner_type' => (string) $lease['owner_type'],
                'owner_id' => (int) $lease['owner_id'],
                'owner_device_id' => $lease['owner_device_id'] ?? null,
                'generation' => (int) $lease['generation'],
                'max_parallel_lanes' => (int) $lease['max_parallel_lanes'],
                'state' => (string) $lease['state'],
                'issued_at' => (string) $lease['issued_at'],
                'ready_deadline_at' => (string) $lease['ready_deadline_at'],
                'expires_at' => (string) $lease['expires_at'],
                'last_renewed_at' => (string) $lease['last_renewed_at'],
                'updated_at' => (string) $lease['updated_at'],
            ]
        );
    }

    /** @param array<string, mixed> $line */
    private function assertLinePolicy(array $line, string $ownerType): void
    {
        if ($ownerType === 'client_device' && (int) ($line['allow_client_execution'] ?? 0) !== 1) {
            throw new ApiException('leigod_line_client_execution_forbidden', 409);
        }
        if ($ownerType === 'node' && (int) ($line['allow_node_execution'] ?? 1) !== 1) {
            throw new ApiException('leigod_line_node_execution_forbidden', 409);
        }
    }

    /** @param array<string, mixed> $lease */
    private function assertFence(array $lease, string $leaseId, int $generation): void
    {
        if (!hash_equals((string) ($lease['lease_id'] ?? ''), $leaseId)
            || (int) ($lease['generation'] ?? 0) !== $generation) {
            throw new ApiException('leigod_line_lease_fence_mismatch', 409);
        }
    }

    /** @param array<string, mixed> $lease */
    private function assertOwner(array $lease, string $ownerType, int $ownerId, ?string $deviceId): void
    {
        if (!$this->ownerMatches($lease, $ownerType, $ownerId, $deviceId)) {
            throw new ApiException('leigod_line_lease_owner_mismatch', 409);
        }
    }

    /** @param array<string, mixed> $lease */
    private function ownerMatches(array $lease, string $ownerType, int $ownerId, ?string $deviceId): bool
    {
        return (string) ($lease['owner_type'] ?? '') === $ownerType
            && (int) ($lease['owner_id'] ?? 0) === $ownerId
            && ($ownerType === 'node'
                ? ($lease['owner_device_id'] ?? null) === null
                : hash_equals((string) ($lease['owner_device_id'] ?? ''), (string) $deviceId));
    }

    /** @return array{0:string,1:int,2:?string} */
    private function owner(string $ownerType, int $ownerId, ?string $deviceId): array
    {
        if (!in_array($ownerType, ['client_device', 'node'], true)) {
            throw new ApiException('invalid_leigod_line_lease_owner_type', 422);
        }
        $this->positiveOwnerId($ownerId);
        if ($ownerType === 'client_device') {
            return [$ownerType, $ownerId, $this->deviceId((string) $deviceId)];
        }
        if ($deviceId !== null && trim($deviceId) !== '') {
            throw new ApiException('invalid_leigod_line_lease_device', 422);
        }
        return [$ownerType, $ownerId, null];
    }

    private function positiveOwnerId(int $ownerId): void
    {
        if ($ownerId <= 0) {
            throw new ApiException('invalid_leigod_line_lease_owner', 422);
        }
    }

    private function deviceId(string $deviceId): string
    {
        $deviceId = trim($deviceId);
        if ($deviceId === '' || strlen($deviceId) > 191) {
            throw new ApiException('invalid_leigod_line_lease_device', 422);
        }
        return $deviceId;
    }

    private function leaseId(string $leaseId): string
    {
        $leaseId = strtolower(trim($leaseId));
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $leaseId) !== 1) {
            throw new ApiException('invalid_leigod_line_lease_id', 422);
        }
        return $leaseId;
    }

    private function generation(int $generation): int
    {
        if ($generation <= 0) {
            throw new ApiException('leigod_line_lease_fence_mismatch', 409);
        }
        return $generation;
    }

    /** @param array<string, mixed> $lease */
    private function expired(array $lease, int $now): bool
    {
        $expiresAt = $this->parseTimestamp((string) ($lease['expires_at'] ?? ''));
        if ($expiresAt === null || $expiresAt <= $now) {
            return true;
        }
        if ((string) ($lease['state'] ?? '') === 'pending') {
            $readyDeadline = $this->parseTimestamp((string) ($lease['ready_deadline_at'] ?? ''));
            return $readyDeadline === null || $readyDeadline <= $now;
        }
        return false;
    }

    private function parseTimestamp(string $value): ?int
    {
        $value = trim($value);
        $parsed = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $value,
            new \DateTimeZone('UTC'),
        );
        if (!$parsed instanceof \DateTimeImmutable || $parsed->format('Y-m-d H:i:s') !== $value) {
            return null;
        }
        return $parsed->getTimestamp();
    }

    private function now(): int
    {
        return (int) ($this->clock)();
    }

    private function timestamp(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    private function pendingReadySeconds(): int
    {
        return max(5, min(300, $this->config->getInt('LEIGOD_EXECUTION_LEASE_PENDING_READY_SECONDS', 45)));
    }

    private function activeTtlSeconds(): int
    {
        return max(30, min(3600, $this->config->getInt('LEIGOD_EXECUTION_LEASE_ACTIVE_TTL_SECONDS', 90)));
    }

    private function revokingGraceSeconds(): int
    {
        return max(1, min(300, $this->config->getInt('LEIGOD_EXECUTION_LEASE_REVOKING_GRACE_SECONDS', 15)));
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-'
            . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12);
    }
}
