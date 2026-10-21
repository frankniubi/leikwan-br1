<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Database\Database;
use Throwable;

/**
 * Sidecar-measured external UoT usage (plan C7).
 *
 * ingest() turns the cumulative per-forward UDP counters a sidecar process
 * reports (its usage.json) into deltas and adds them to
 * external_uot_usage_daily for the current UTC day. reconcile() later bills,
 * per forward and finished UTC day, whatever the sidecar measured beyond what
 * the client reported through ultra_usage_events, so billing is
 * max(client-reported, sidecar-measured) up to a small tolerance.
 */
final class ExternalUotUsageLedger
{
    /** A single report adding more than this for one forward is corrupt, not traffic. */
    public const MAX_DELTA_BYTES = 10 * 1024 * 1024 * 1024 * 1024;
    public const MIN_TOLERANCE_BYTES = 1024 * 1024;
    /** Tolerance is sidecar_bytes / 50, i.e. 2 %. */
    public const TOLERANCE_DIVISOR = 50;
    /** A day is only reconciled once it ended this long ago, so late client reports land first. */
    public const RECONCILE_GRACE_SECONDS = 7200;
    private const MAX_FORWARDS_PER_REPORT = 100000;

    public function __construct(
        private Database $db,
        private ?ProvisioningService $provisioning = null,
    ) {
    }

    /**
     * @param mixed $document a sidecar usage file ({"instance":..., "forwards":{id:{...}}})
     * @return array{forwards: int, bytes: int, ignored: int, rejected: int}
     */
    public function ingest(string $source, mixed $document, ?int $now = null): array
    {
        $summary = ['forwards' => 0, 'bytes' => 0, 'ignored' => 0, 'rejected' => 0];
        $source = trim($source);
        if ($source === '' || strlen($source) > 191 || !is_array($document)) {
            return $summary;
        }
        $instance = strtolower(trim((string) (is_string($document['instance'] ?? null) ? $document['instance'] : '')));
        $forwards = $document['forwards'] ?? null;
        if (preg_match('/^[0-9a-f]{32}$/', $instance) !== 1 || !is_array($forwards)
            || count($forwards) > self::MAX_FORWARDS_PER_REPORT) {
            return $summary;
        }
        $now ??= time();
        $timestamp = gmdate('Y-m-d H:i:s', $now);
        $date = gmdate('Y-m-d', $now);

        // A source runs one sidecar process; once a new instance reports, the
        // previous instance never will again.
        $this->db->execute(
            'DELETE FROM external_uot_usage_counters WHERE source = :source AND instance_id <> :instance_id',
            ['source' => $source, 'instance_id' => $instance]
        );

        foreach ($forwards as $rawId => $counters) {
            $forwardId = ctype_digit((string) $rawId) ? (int) $rawId : 0;
            $toTarget = is_array($counters) ? ($counters['to_target_bytes'] ?? null) : null;
            $fromTarget = is_array($counters) ? ($counters['from_target_bytes'] ?? null) : null;
            if ($forwardId <= 0 || !is_int($toTarget) || !is_int($fromTarget) || $toTarget < 0 || $fromTarget < 0) {
                $summary['ignored']++;
                continue;
            }
            $outcome = $this->ingestForward($source, $instance, $forwardId, $toTarget, $fromTarget, $timestamp, $date);
            if ($outcome === null) {
                $summary['ignored']++;
            } elseif ($outcome < 0) {
                $summary['rejected']++;
            } elseif ($outcome > 0) {
                $summary['forwards']++;
                $summary['bytes'] += $outcome;
            }
        }

        return $summary;
    }

    /** Returns the bytes added, -1 when rejected, null for an unknown forward. */
    private function ingestForward(
        string $source,
        string $instance,
        int $forwardId,
        int $toTarget,
        int $fromTarget,
        string $timestamp,
        string $date
    ): ?int {
        $forward = $this->db->fetchOne('SELECT id, service_id FROM forwards WHERE id = :id LIMIT 1', ['id' => $forwardId]);
        if (!is_array($forward)) {
            return null;
        }
        $key = ['source' => $source, 'instance_id' => $instance, 'forward_id' => $forwardId];
        $stored = $this->db->fetchOne(
            'SELECT to_target_bytes, from_target_bytes FROM external_uot_usage_counters
             WHERE source = :source AND instance_id = :instance_id AND forward_id = :forward_id LIMIT 1',
            $key
        );
        $storedTo = is_array($stored) ? (int) $stored['to_target_bytes'] : 0;
        $storedFrom = is_array($stored) ? (int) $stored['from_target_bytes'] : 0;
        // A counter that went backwards was reset, so its whole value is new.
        $delta = ($toTarget >= $storedTo ? $toTarget - $storedTo : $toTarget)
            + ($fromTarget >= $storedFrom ? $fromTarget - $storedFrom : $fromTarget);
        if ($delta > self::MAX_DELTA_BYTES) {
            error_log(sprintf('external-uot usage: rejected %d bytes for forward %d from %s', $delta, $forwardId, $source));

            return -1;
        }
        if ($delta === 0 && is_array($stored)) {
            return 0;
        }
        $serviceId = (int) ($forward['service_id'] ?? 0);

        return $this->db->transaction(function () use ($stored, $key, $storedTo, $storedFrom, $toTarget, $fromTarget, $timestamp, $delta, $forwardId, $date, $serviceId): int {
            if (is_array($stored)) {
                // Compare-and-set: a concurrent report of the same source already counted this step.
                $changed = $this->db->execute(
                    'UPDATE external_uot_usage_counters
                     SET to_target_bytes = :to_target_bytes, from_target_bytes = :from_target_bytes, updated_at = :updated_at
                     WHERE source = :source AND instance_id = :instance_id AND forward_id = :forward_id
                       AND to_target_bytes = :stored_to AND from_target_bytes = :stored_from',
                    $key + [
                        'to_target_bytes' => $toTarget,
                        'from_target_bytes' => $fromTarget,
                        'updated_at' => $timestamp,
                        'stored_to' => $storedTo,
                        'stored_from' => $storedFrom,
                    ]
                );
                if ($changed !== 1) {
                    return 0;
                }
            } else {
                try {
                    $this->db->insert('external_uot_usage_counters', $key + [
                        'to_target_bytes' => $toTarget,
                        'from_target_bytes' => $fromTarget,
                        'updated_at' => $timestamp,
                    ]);
                } catch (Throwable $exception) {
                    if (self::isDuplicate($exception)) {
                        return 0;
                    }
                    throw $exception;
                }
            }
            if ($delta > 0) {
                $this->addSidecarBytes($forwardId, $date, $serviceId, $delta, $timestamp);
            }

            return $delta;
        });
    }

    private function addSidecarBytes(int $forwardId, string $date, int $serviceId, int $delta, string $timestamp): void
    {
        $params = ['forward_id' => $forwardId, 'usage_date' => $date, 'delta' => $delta, 'updated_at' => $timestamp];
        $update = 'UPDATE external_uot_usage_daily SET sidecar_bytes = sidecar_bytes + :delta, updated_at = :updated_at
                   WHERE forward_id = :forward_id AND usage_date = :usage_date';
        if ($this->db->execute($update, $params) === 1) {
            return;
        }
        try {
            $this->db->insert('external_uot_usage_daily', [
                'forward_id' => $forwardId,
                'usage_date' => $date,
                'service_id' => $serviceId > 0 ? $serviceId : null,
                'sidecar_bytes' => $delta,
                'client_bytes' => 0,
                'billed_extra_bytes' => 0,
                'reconciled_at' => null,
                'updated_at' => $timestamp,
            ]);
        } catch (Throwable $exception) {
            if (!self::isDuplicate($exception)) {
                throw $exception;
            }
            $this->db->execute($update, $params);
        }
    }

    /**
     * Bills sidecar_bytes - client_bytes - tolerance for every unreconciled
     * day that ended at least RECONCILE_GRACE_SECONDS ago (optionally one
     * date). Each row is claimed before it is billed, so reruns never bill twice.
     *
     * @return list<array<string, mixed>>
     */
    public function reconcile(?string $onlyDate = null, bool $dryRun = false, ?int $now = null): array
    {
        if (!$dryRun && $this->provisioning === null) {
            throw new \LogicException('reconcile needs ProvisioningService to bill');
        }
        $now ??= time();
        $latest = gmdate('Y-m-d', $now - self::RECONCILE_GRACE_SECONDS - 86400);
        $sql = 'SELECT forward_id, usage_date, service_id, sidecar_bytes FROM external_uot_usage_daily
                WHERE reconciled_at IS NULL AND usage_date <= :latest';
        $params = ['latest' => $latest];
        if ($onlyDate !== null) {
            if ($onlyDate > $latest) {
                return [];
            }
            $sql .= ' AND usage_date = :usage_date';
            $params['usage_date'] = $onlyDate;
        }
        $rows = $this->db->fetchAll($sql . ' ORDER BY usage_date ASC, forward_id ASC', $params);

        $results = [];
        foreach ($rows as $row) {
            $forwardId = (int) $row['forward_id'];
            $date = substr((string) $row['usage_date'], 0, 10);
            $sidecarBytes = max(0, (int) $row['sidecar_bytes']);
            $start = $date . ' 00:00:00';
            $end = gmdate('Y-m-d', (int) strtotime($date . ' 00:00:00 UTC') + 86400) . ' 00:00:00';
            $client = $this->db->fetchOne(
                'SELECT COALESCE(SUM(bytes_in + bytes_out), 0) AS total FROM ultra_usage_events
                 WHERE forward_id = :forward_id AND created_at >= :day_start AND created_at < :day_end',
                ['forward_id' => $forwardId, 'day_start' => $start, 'day_end' => $end]
            );
            $clientBytes = max(0, (int) ($client['total'] ?? 0));
            $extra = self::extraBytes($sidecarBytes, $clientBytes);
            $serviceId = (int) ($row['service_id'] ?? 0);
            if ($serviceId <= 0) {
                $forward = $this->db->fetchOne('SELECT service_id FROM forwards WHERE id = :id LIMIT 1', ['id' => $forwardId]);
                $serviceId = (int) ($forward['service_id'] ?? 0);
            }
            $result = [
                'forward_id' => $forwardId,
                'usage_date' => $date,
                'service_id' => $serviceId,
                'sidecar_bytes' => $sidecarBytes,
                'client_bytes' => $clientBytes,
                'extra_bytes' => $extra,
                'billed' => false,
                'suspended' => false,
            ];
            if ($dryRun) {
                $results[] = $result;
                continue;
            }
            $outcome = $this->db->transaction(function () use ($forwardId, $date, $clientBytes, $extra, $serviceId, $now): ?array {
                $service = $serviceId > 0
                    ? $this->db->fetchOne('SELECT id FROM services WHERE id = :id LIMIT 1', ['id' => $serviceId])
                    : null;
                $billable = is_array($service) ? $extra : 0;
                $timestamp = gmdate('Y-m-d H:i:s', $now);
                $claimed = $this->db->execute(
                    'UPDATE external_uot_usage_daily
                     SET client_bytes = :client_bytes, billed_extra_bytes = :billed_extra_bytes,
                         reconciled_at = :reconciled_at, updated_at = :updated_at
                     WHERE forward_id = :forward_id AND usage_date = :usage_date AND reconciled_at IS NULL',
                    [
                        'client_bytes' => $clientBytes,
                        'billed_extra_bytes' => $billable,
                        'reconciled_at' => $timestamp,
                        'updated_at' => $timestamp,
                        'forward_id' => $forwardId,
                        'usage_date' => $date,
                    ]
                );
                if ($claimed !== 1) {
                    return null;
                }
                $suspended = $billable > 0
                    && $this->provisioning->addServiceTrafficBytes($serviceId, $billable);

                return ['billed' => $billable > 0, 'suspended' => $suspended, 'extra_bytes' => $billable];
            });
            if ($outcome === null) {
                continue;
            }
            $results[] = array_merge($result, $outcome);
        }

        return $results;
    }

    public static function extraBytes(int $sidecarBytes, int $clientBytes): int
    {
        $tolerance = max(self::MIN_TOLERANCE_BYTES, intdiv(max(0, $sidecarBytes), self::TOLERANCE_DIVISOR));

        return max(0, $sidecarBytes - max(0, $clientBytes) - $tolerance);
    }

    private static function isDuplicate(Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'duplicate') || str_contains($message, 'unique') || str_contains($message, '23000');
    }
}
