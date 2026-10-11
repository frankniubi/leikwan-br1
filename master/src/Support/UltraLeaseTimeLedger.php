<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Database\Database;
use Throwable;

/**
 * Server-side "connected time" for Ultra direct mode
 * (docs/plans/2026-10-02-line-protection-security-remediation-plan.md A.3-A.4).
 *
 * Ultra clients talk to LeiGod directly, so traffic bytes are self-reported
 * and a modified client can simply stop reporting them. The one thing the
 * panel itself observes is lease issuance: every lease covers a window
 * [issued, expires) for one client + device + forward, and a client that wants
 * to stay connected has to come back for a fresh one before it runs out.
 *
 * This ledger keeps the UNION of those windows, per client + device + forward
 * and UTC day, in ultra_lease_time. ultra_client_leases cannot serve as the
 * ledger because cleanup-tasks.php purges its rows after a few hours.
 *
 * Union, not sum: the lease endpoint mints up to four candidate leases per
 * forward in one request, and clients renew well before expiry, so summing
 * lease lifetimes would multiply-count the same wall-clock time. Each row
 * carries a covered_until watermark (epoch seconds) and an issue only adds the
 * part of its window past that watermark. Issue times per key are
 * non-decreasing (they are "now"), so a watermark is enough for an exact union.
 * A window that crosses UTC midnight is split, so a row's watermark only ever
 * describes its own day and no cross-row read is needed.
 *
 * Every write is one row-level UPDATE (or the first INSERT of the day), so
 * concurrent lease requests can never double-count: the second statement sees
 * the watermark the first one moved.
 *
 * What it measures, honestly: time the device HELD a valid lease, which is an
 * upper bound on time it could have used the line. A device that disconnects
 * right after a lease still accrues that lease's remaining window (at most
 * MAX_POLICY_TTL_SECONDS, or the offline-validity ceiling for clients that
 * asked for it). Seconds are per forward: two forwards held for an hour are
 * two line-hours, which is the concurrency-times-time unit A.4 bills on.
 */
final class UltraLeaseTimeLedger
{
    public const TABLE = 'ultra_lease_time';

    /** Longest single window the ledger accepts; matches the lease ceiling. */
    public const MAX_WINDOW_SECONDS = UltraMode::MAX_OFFLINE_VALIDITY_SECONDS;

    /** Seconds the last successful updateSegment() added. */
    private int $lastAdded = 0;

    public function __construct(private Database $db)
    {
    }

    /**
     * Record one issued lease covering [$issuedAt, $expiresAt). Best effort and
     * fail-soft: accounting can never change a lease outcome, so every failure
     * is logged and swallowed.
     *
     * @return int seconds newly added to the ledger (0 when fully overlapped
     *             or when the write failed)
     */
    public function recordLease(
        int $clientId,
        ?int $serviceId,
        string $deviceHash,
        int $forwardId,
        int $issuedAt,
        int $expiresAt,
    ): int {
        $deviceHash = strtolower(trim($deviceHash));
        if ($clientId <= 0 || $forwardId <= 0 || $issuedAt <= 0
            || preg_match('/^[a-f0-9]{64}$/', $deviceHash) !== 1) {
            return 0;
        }
        $serviceId = $serviceId !== null && $serviceId > 0 ? $serviceId : null;
        $expiresAt = min($expiresAt, $issuedAt + self::MAX_WINDOW_SECONDS);
        if ($expiresAt <= $issuedAt) {
            return 0;
        }

        $added = 0;
        foreach (self::splitByUtcDay($issuedAt, $expiresAt) as [$day, $segmentStart, $segmentEnd]) {
            try {
                $added += $this->recordSegment($clientId, $serviceId, $deviceHash, $forwardId, $day, $segmentStart, $segmentEnd, $issuedAt);
            } catch (Throwable $exception) {
                error_log(sprintf(
                    'ultra_lease_time_record_failed client=%d forward=%d day=%s %s',
                    $clientId,
                    $forwardId,
                    $day,
                    $exception::class
                ));
            }
        }
        return $added;
    }

    /**
     * Totals for one client over an inclusive UTC day range ('Y-m-d').
     *
     * @return array{leased_seconds:int,lease_count:int,devices:int,forwards:int}
     */
    public function totalsForClient(int $clientId, string $fromDay, string $toDay): array
    {
        return $this->totals('client_id', $clientId, $fromDay, $toDay);
    }

    /**
     * Totals for one service over an inclusive UTC day range ('Y-m-d').
     *
     * @return array{leased_seconds:int,lease_count:int,devices:int,forwards:int}
     */
    public function totalsForService(int $serviceId, string $fromDay, string $toDay): array
    {
        return $this->totals('service_id', $serviceId, $fromDay, $toDay);
    }

    /**
     * Split [$start, $end) at UTC midnights.
     *
     * @return list<array{0:string,1:int,2:int}> [day, segment start, segment end]
     */
    public static function splitByUtcDay(int $start, int $end): array
    {
        $segments = [];
        while ($start < $end) {
            $nextMidnight = (intdiv($start, 86400) + 1) * 86400;
            $segmentEnd = min($end, $nextMidnight);
            $segments[] = [gmdate('Y-m-d', $start), $start, $segmentEnd];
            $start = $segmentEnd;
        }
        return $segments;
    }

    private function recordSegment(
        int $clientId,
        ?int $serviceId,
        string $deviceHash,
        int $forwardId,
        string $day,
        int $segmentStart,
        int $segmentEnd,
        int $issuedAt,
    ): int {
        $key = [
            'client_id' => $clientId,
            'device_hash' => $deviceHash,
            'forward_id' => $forwardId,
            'period_day' => $day,
        ];
        if ($this->updateSegment($key, $serviceId, $segmentStart, $segmentEnd, $issuedAt)) {
            return $this->lastAdded;
        }
        try {
            $this->db->insert(self::TABLE, $key + [
                'service_id' => $serviceId,
                'leased_seconds' => $segmentEnd - $segmentStart,
                'lease_count' => 1,
                'covered_until' => $segmentEnd,
                'first_issued_at' => gmdate('Y-m-d H:i:s', $issuedAt),
                'last_issued_at' => gmdate('Y-m-d H:i:s', $issuedAt),
                'created_at' => gmdate('Y-m-d H:i:s', $issuedAt),
                'updated_at' => gmdate('Y-m-d H:i:s', $issuedAt),
            ]);
            return $segmentEnd - $segmentStart;
        } catch (Throwable $insertFailure) {
            // A concurrent request created the day row between our UPDATE and
            // INSERT (unique key). Fold this window into that row instead.
            if ($this->updateSegment($key, $serviceId, $segmentStart, $segmentEnd, $issuedAt)) {
                return $this->lastAdded;
            }
            throw $insertFailure;
        }
    }

    /**
     * Fold one window into an existing day row. The segment bounds are ints
     * from the panel clock and are inlined so both MySQL and SQLite do plain
     * integer arithmetic on them.
     *
     * MySQL evaluates single-table UPDATE assignments left to right with the
     * already-updated values, SQLite with the old ones; leased_seconds is
     * assigned before covered_until so both read the OLD watermark.
     *
     * @param array{client_id:int,device_hash:string,forward_id:int,period_day:string} $key
     */
    private function updateSegment(array $key, ?int $serviceId, int $segmentStart, int $segmentEnd, int $issuedAt): bool
    {
        $before = $this->db->fetchOne(
            'SELECT covered_until FROM ultra_lease_time
             WHERE client_id = :client_id AND device_hash = :device_hash
               AND forward_id = :forward_id AND period_day = :period_day
             LIMIT 1',
            $key
        );
        if ($before === null) {
            return false;
        }
        $start = (int) $segmentStart;
        $end = (int) $segmentEnd;
        $updated = $this->db->execute(
            sprintf(
                'UPDATE ultra_lease_time
                 SET leased_seconds = leased_seconds + CASE
                         WHEN covered_until >= %2$d THEN 0
                         WHEN covered_until > %1$d THEN %2$d - covered_until
                         ELSE %3$d
                     END,
                     lease_count = lease_count + 1,
                     covered_until = CASE WHEN covered_until > %2$d THEN covered_until ELSE %2$d END,
                     service_id = COALESCE(service_id, :service_id),
                     last_issued_at = :last_issued_at,
                     updated_at = :updated_at
                 WHERE client_id = :client_id AND device_hash = :device_hash
                   AND forward_id = :forward_id AND period_day = :period_day',
                $start,
                $end,
                $end - $start
            ),
            $key + [
                'service_id' => $serviceId,
                'last_issued_at' => gmdate('Y-m-d H:i:s', $issuedAt),
                'updated_at' => gmdate('Y-m-d H:i:s', $issuedAt),
            ]
        );
        if ($updated < 1) {
            return false;
        }
        // Reported for logging/tests only; the authoritative figure is the
        // row itself, which the single UPDATE above moved atomically.
        $watermark = (int) ($before['covered_until'] ?? 0);
        $this->lastAdded = $watermark >= $end ? 0 : $end - max($start, $watermark);
        return true;
    }

    /**
     * @return array{leased_seconds:int,lease_count:int,devices:int,forwards:int}
     */
    private function totals(string $column, int $id, string $fromDay, string $toDay): array
    {
        $empty = ['leased_seconds' => 0, 'lease_count' => 0, 'devices' => 0, 'forwards' => 0];
        if ($id <= 0 || !self::isDay($fromDay) || !self::isDay($toDay) || $fromDay > $toDay) {
            return $empty;
        }
        try {
            $row = $this->db->fetchOne(
                'SELECT COALESCE(SUM(leased_seconds), 0) AS leased_seconds,
                        COALESCE(SUM(lease_count), 0) AS lease_count,
                        COUNT(DISTINCT device_hash) AS devices,
                        COUNT(DISTINCT forward_id) AS forwards
                 FROM ultra_lease_time
                 WHERE ' . ($column === 'service_id' ? 'service_id' : 'client_id') . ' = :id
                   AND period_day >= :from_day AND period_day <= :to_day',
                ['id' => $id, 'from_day' => $fromDay, 'to_day' => $toDay]
            );
        } catch (Throwable $exception) {
            error_log('ultra_lease_time_lookup_failed ' . $exception::class);
            return $empty;
        }
        return [
            'leased_seconds' => max(0, (int) ($row['leased_seconds'] ?? 0)),
            'lease_count' => max(0, (int) ($row['lease_count'] ?? 0)),
            'devices' => max(0, (int) ($row['devices'] ?? 0)),
            'forwards' => max(0, (int) ($row['forwards'] ?? 0)),
        ];
    }

    private static function isDay(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }
}
