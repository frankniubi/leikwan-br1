<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

final class BondLegReputationService
{
    public function __construct(private $db)
    {
    }

    public function ingestClientSnapshots(int $clientId, array $snapshots): void
    {
        $this->ingestSnapshots('client', $clientId, $snapshots);
    }

    public function ingestSidecarSnapshot(int $sidecarId, array $snapshot): void
    {
        if ($snapshot === []) {
            return;
        }

        $this->ingestSnapshots('sidecar', $sidecarId, [$snapshot]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function latestGlobalRows(int $limit = 20): array
    {
        if ($this->usesFakeTables()) {
            $rows = $this->db->tableRows('bond_leg_reputation_current');
            usort($rows, fn(array $a, array $b): int => $this->currentRowSortKey($a) <=> $this->currentRowSortKey($b));
            return array_slice($rows, 0, max(1, $limit));
        }

        return $this->db->fetchAll(
            'SELECT *
             FROM bond_leg_reputation_current
             ORDER BY FIELD(state, \'quarantined\', \'probation\', \'degraded\', \'healthy\'), updated_at DESC, id DESC
             LIMIT ' . max(1, (int) $limit)
        );
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function recentEvents(array $filters = [], int $limit = 50): array
    {
        if ($this->usesFakeTables()) {
            $rows = $this->db->tableRows('bond_leg_reputation_events');
            usort($rows, static fn(array $a, array $b): int => strcmp((string) ($b['observed_at'] ?? ''), (string) ($a['observed_at'] ?? '')));
            return array_slice($rows, 0, max(1, $limit));
        }

        $sql = 'SELECT e.*
                FROM bond_leg_reputation_events e';
        $params = [];
        $where = [];

        if (isset($filters['user_id'])) {
            $sql .= ' INNER JOIN forwards f ON f.id = e.forward_id
                      INNER JOIN services s ON s.id = f.service_id';
            $where[] = 's.user_id = :user_id';
            $params['user_id'] = (int) $filters['user_id'];
        }
        if (isset($filters['forward_id'])) {
            $where[] = 'e.forward_id = :forward_id';
            $params['forward_id'] = (int) $filters['forward_id'];
        }
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY e.observed_at DESC, e.id DESC LIMIT ' . max(1, (int) $limit);

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function scopedRowsForTenant(int $userId): array
    {
        if ($this->usesFakeTables()) {
            return $this->db->tableRows('bond_leg_reputation_current');
        }

        return $this->db->fetchAll(
            'SELECT c.*
             FROM bond_leg_reputation_current c
             INNER JOIN forwards f ON f.id = c.forward_id
             INNER JOIN services s ON s.id = f.service_id
             WHERE s.user_id = :user_id
             ORDER BY c.forward_id ASC,
                      FIELD(c.state, \'quarantined\', \'probation\', \'degraded\', \'healthy\'),
                      c.protocol ASC,
                      c.updated_at DESC',
            ['user_id' => $userId]
        );
    }

    /**
     * @param array<int, array<string, mixed>> $snapshots
     */
    private function ingestSnapshots(string $observerType, int $observerId, array $snapshots): void
    {
        foreach ($snapshots as $snapshot) {
            if (!is_array($snapshot)) {
                continue;
            }

            $forwardId = max(0, (int) ($snapshot['forward_id'] ?? 0));
            if ($forwardId <= 0 || !$this->forwardExists($forwardId)) {
                continue;
            }
            $updatedAt = $this->normalizeTimestamp($snapshot['updated_at'] ?? null);
            $legs = is_array($snapshot['legs'] ?? null) ? $snapshot['legs'] : [];

            foreach ($legs as $leg) {
                if (!is_array($leg)) {
                    continue;
                }
                $base = [
                    'observer_type' => $observerType,
                    'observer_id' => $observerId,
                    'forward_id' => $forwardId,
                    'endpoint_id' => max(0, (int) ($leg['endpoint_id'] ?? 0)),
                    'node_id' => max(0, (int) ($leg['node_id'] ?? 0)),
                    'host' => trim((string) ($leg['host'] ?? '')),
                    'updated_at' => $updatedAt,
                ];

                foreach (['tcp', 'udp'] as $protocol) {
                    $payload = is_array($leg[$protocol] ?? null) ? $leg[$protocol] : [];
                    if ($payload === []) {
                        continue;
                    }

                    $currentRow = [
                        'observer_type' => $base['observer_type'],
                        'observer_id' => $base['observer_id'],
                        'forward_id' => $base['forward_id'],
                        'endpoint_id' => $base['endpoint_id'],
                        'protocol' => $protocol,
                        'node_id' => $base['node_id'],
                        'host' => $base['host'],
                        'state' => strtolower(trim((string) ($payload['state'] ?? 'healthy'))) ?: 'healthy',
                        'score' => round((float) ($payload['score'] ?? 0), 4),
                        'effective_weight' => round((float) ($payload['effective_weight'] ?? 0), 4),
                        'delivered_ewma' => round((float) ($payload['delivered_bytes_ewma'] ?? 0), 4),
                        'penalty_ewma' => round((float) ($payload['penalty_ewma'] ?? 0), 4),
                        'last_reason' => trim((string) ($payload['last_reason'] ?? '')) ?: null,
                        'last_good_at' => $this->normalizeTimestamp($payload['last_good_at'] ?? null, allowNull: true),
                        'updated_at' => $base['updated_at'],
                    ];
                    $this->upsertCurrentRow($currentRow);
                }

                $events = is_array($leg['recent_events'] ?? null) ? $leg['recent_events'] : [];
                foreach ($events as $event) {
                    if (!is_array($event)) {
                        continue;
                    }
                    $protocol = strtolower(trim((string) ($event['protocol'] ?? '')));
                    $protocolPayload = is_array($leg[$protocol] ?? null) ? $leg[$protocol] : [];
                    $this->insertEventRow([
                        'observer_type' => $base['observer_type'],
                        'observer_id' => $base['observer_id'],
                        'forward_id' => $base['forward_id'],
                        'endpoint_id' => $base['endpoint_id'],
                        'protocol' => $protocol !== '' ? $protocol : 'tcp',
                        'host' => $base['host'],
                        'from_state' => strtolower(trim((string) ($event['from'] ?? ''))) ?: null,
                        'to_state' => strtolower(trim((string) ($event['to'] ?? ''))) ?: 'healthy',
                        'score' => round((float) ($protocolPayload['score'] ?? 0), 4),
                        'effective_weight' => round((float) ($protocolPayload['effective_weight'] ?? 0), 4),
                        'reason' => trim((string) ($event['reason'] ?? '')) ?: null,
                        'observed_at' => $this->normalizeTimestamp($event['observed_at'] ?? null),
                    ]);
                }
            }
        }
    }

    private function forwardExists(int $forwardId): bool
    {
        if ($this->usesFakeTables()) {
            return true;
        }

        return $this->db->fetchOne(
            'SELECT id FROM forwards WHERE id = :id LIMIT 1',
            ['id' => $forwardId]
        ) !== null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function upsertCurrentRow(array $row): void
    {
        if ($this->usesFakeTables()) {
            $this->db->insert('bond_leg_reputation_current', $row);
            return;
        }

        $this->db->transaction(function () use ($row): void {
            $this->db->execute(
                'DELETE FROM bond_leg_reputation_current
                 WHERE observer_type = :observer_type
                   AND observer_id = :observer_id
                   AND forward_id = :forward_id
                   AND endpoint_id = :endpoint_id
                   AND protocol = :protocol',
                [
                    'observer_type' => $row['observer_type'],
                    'observer_id' => $row['observer_id'],
                    'forward_id' => $row['forward_id'],
                    'endpoint_id' => $row['endpoint_id'],
                    'protocol' => $row['protocol'],
                ]
            );
            $this->db->insert('bond_leg_reputation_current', $row);
        });
    }

    /**
     * @param array<string, mixed> $row
     */
    private function insertEventRow(array $row): void
    {
        $this->db->insert('bond_leg_reputation_events', $row);
    }

    private function normalizeTimestamp(mixed $value, bool $allowNull = false): ?string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return $allowNull ? null : gmdate('Y-m-d H:i:s');
        }

        try {
            $dt = new \DateTimeImmutable($raw);
        } catch (\Throwable) {
            return $allowNull ? null : gmdate('Y-m-d H:i:s');
        }

        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * @param array<string, mixed> $row
     * @return array{0:int,1:string,2:int}
     */
    private function currentRowSortKey(array $row): array
    {
        $weight = (int) round((float) ($row['effective_weight'] ?? 0) * 10000);
        return [
            $this->stateRank((string) ($row['state'] ?? 'healthy')),
            0 - $weight,
            0 - (int) ($row['id'] ?? 0),
        ];
    }

    private function stateRank(string $state): int
    {
        return match (strtolower(trim($state))) {
            'quarantined' => 0,
            'probation' => 1,
            'degraded' => 2,
            default => 3,
        };
    }

    private function usesFakeTables(): bool
    {
        return is_object($this->db) && method_exists($this->db, 'tableRows');
    }
}
