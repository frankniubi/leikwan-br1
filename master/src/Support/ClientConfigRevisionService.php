<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Database\Database;

final class ClientConfigRevisionService
{
    /** @var callable():float */
    private $clock;
    /** @var callable(int):void */
    private $sleeper;

    public function __construct(
        private Database $db,
        ?callable $clock = null,
        ?callable $sleeper = null,
    ) {
        $this->clock = $clock ?? static fn(): float => hrtime(true) / 1_000_000_000;
        $this->sleeper = $sleeper ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
    }

    public function current(int $clientId): int
    {
        if ($clientId <= 0) {
            return 0;
        }
        $row = $this->db->fetchOne(
            'SELECT revision FROM client_config_revisions WHERE client_id = :client_id LIMIT 1',
            ['client_id' => $clientId]
        );
        return max(0, (int) ($row['revision'] ?? 0));
    }

    public function bump(int $clientId): int
    {
        if ($clientId <= 0) {
            throw new \InvalidArgumentException('client_id_required');
        }
        $updatedAt = gmdate('Y-m-d H:i:s');
        $this->db->execute(
            'INSERT INTO client_config_revisions (client_id, revision, updated_at)
             VALUES (:client_id, 1, :updated_at)
             ON DUPLICATE KEY UPDATE
                revision = revision + 1,
                updated_at = VALUES(updated_at)',
            ['client_id' => $clientId, 'updated_at' => $updatedAt]
        );
        return $this->current($clientId);
    }

    /**
     * @param array<int, int|string> $clientIds
     * @return array<int, int>
     */
    public function bumpMany(array $clientIds): array
    {
        $unique = [];
        foreach ($clientIds as $clientId) {
            $clientId = (int) $clientId;
            if ($clientId > 0) {
                $unique[$clientId] = true;
            }
        }
        $revisions = [];
        foreach (array_keys($unique) as $clientId) {
            $revisions[(int) $clientId] = $this->bump((int) $clientId);
        }
        return $revisions;
    }

    /** @return array{changed:bool,revision:int} */
    public function watch(int $clientId, int $knownRevision, int $waitSeconds): array
    {
        $knownRevision = max(0, $knownRevision);
        $waitSeconds = max(0, min(25, $waitSeconds));
        $deadline = ($this->clock)() + $waitSeconds;
        while (true) {
            $revision = $this->current($clientId);
            if ($revision > $knownRevision) {
                return ['changed' => true, 'revision' => $revision];
            }
            if ($waitSeconds === 0 || ($this->clock)() >= $deadline) {
                return ['changed' => false, 'revision' => $revision];
            }
            ($this->sleeper)(250000);
        }
    }
}
