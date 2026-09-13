<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

/**
 * Automatic re-review of quarantined candidates by the source host.
 *
 * Reputation is built from client reports, and a client on a bad local network
 * reports healthy lines as dead. The source host sees the same line from the
 * provider side, so a passing review - big-packet UDP and DNS both usable for
 * the whole review window - disproves the reports that quarantined it:
 *
 *   - the quarantine is lifted and the rung it added is rolled back;
 *   - every device that reported a failure for that candidate takes a strike,
 *     and once a device has enough strikes its failures no longer count towards
 *     the independent-observer requirement, so it cannot quarantine a line alone;
 *   - the same devices are listed for the admin console.
 *
 * A failing review does the opposite for the devices: their report was right, so
 * they earn a strike back.
 */
final class UltraCandidateReviewService
{
    public const STATE_PENDING = 'pending';
    public const STATE_RUNNING = 'running';
    public const STATE_PASSED = 'passed';
    public const STATE_FAILED = 'failed';

    /** Review window the source host must keep clean, in seconds. */
    public const REVIEW_SECONDS = 600;
    /** Open reviews handed to one source per jobs cycle. */
    public const MAX_OPEN_PER_SOURCE = 2;
    /** Strikes at which a device stops counting as an independent observer. */
    public const DEMOTION_STRIKES = 2;
    /** A running review that stops reporting is retried after this long. */
    public const STALE_RUNNING_SECONDS = 1800;
    /** Claims one review may use before it is closed as abandoned. */
    public const MAX_ATTEMPTS = 5;
    /** How long a closed review keeps a still-quarantined candidate from being re-queued. */
    public const REENQUEUE_COOLDOWN_SECONDS = 21600;
    public const MAX_RECENT = 200;

    /** @var callable():int */
    private $clock;

    public function __construct(
        private ?object $db = null,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    /**
     * Hand the source host the reviews it should run, after queueing one for
     * every candidate that is sitting in quarantine without an open review.
     *
     * @return array<int, array<string, mixed>>
     */
    public function requestForSource(string $sourceId, int $limit = self::MAX_OPEN_PER_SOURCE): array
    {
        $sourceId = trim($sourceId);
        if ($sourceId === '' || $this->db === null) {
            return [];
        }
        $limit = max(1, min(8, $limit));
        $this->expireStaleRunning();
        $this->enqueueQuarantined($sourceId, $limit);
        return $this->claimPending($sourceId, $limit);
    }

    /**
     * Apply the outcome of one review. Returns the updated review row.
     *
     * @param array<string, mixed> $detail
     * @return array<string, mixed>
     */
    public function recordResult(int $reviewId, bool $passed, array $detail = [], string $note = ''): array
    {
        if ($this->db === null || $reviewId <= 0) {
            return [];
        }
        return $this->db->transaction(function () use ($reviewId, $passed, $detail, $note): array {
            $row = $this->db->fetchOne(
                'SELECT * FROM ultra_candidate_reviews WHERE id = :id LIMIT 1',
                ['id' => $reviewId]
            );
            if (!is_array($row)) {
                return [];
            }
            $hash = strtolower(trim((string) ($row['candidate_key_hash'] ?? '')));
            if ($hash === '') {
                return [];
            }
            $observers = $this->reportingObservers($hash);
            if ($passed) {
                $this->liftQuarantine($hash);
            }
            $this->adjustObserverStrikes($observers, $passed);
            $payload = [
                'passed' => $passed,
                'note' => mb_substr(trim($note), 0, 240),
                'detail' => $detail,
                'observers' => count($observers),
                'completed_at' => gmdate('Y-m-d\TH:i:s\Z', $this->now()),
            ];
            $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $this->db->execute(
                'UPDATE ultra_candidate_reviews
                    SET state = :state, completed_at = :completed_at, result_json = :result_json
                  WHERE id = :id',
                [
                    'state' => $passed ? self::STATE_PASSED : self::STATE_FAILED,
                    'completed_at' => gmdate('Y-m-d H:i:s', $this->now()),
                    'result_json' => $encoded === false ? null : $encoded,
                    'id' => $reviewId,
                ]
            );
            $row['state'] = $passed ? self::STATE_PASSED : self::STATE_FAILED;
            return $row;
        });
    }

    /** @return array<int, array<string, mixed>> */
    public function recent(int $limit = 50): array
    {
        if ($this->db === null) {
            return [];
        }
        $limit = max(1, min(self::MAX_RECENT, $limit));
        $rows = $this->db->fetchAll(
            'SELECT id, candidate_key_hash, source_id, line_id, protocol, state, attempts,
                    requested_at, started_at, completed_at, result_json
               FROM ultra_candidate_reviews
              ORDER BY id DESC LIMIT ' . $limit
        );
        return is_array($rows) ? $rows : [];
    }

    /** @return array<int, array<string, mixed>> */
    public function demotedDevices(int $limit = 50): array
    {
        if ($this->db === null) {
            return [];
        }
        $limit = max(1, min(self::MAX_RECENT, $limit));
        $rows = $this->db->fetchAll(
            'SELECT observer_hash, client_id, device_id, strikes, demoted_at, last_rebuttal_at, last_seen_at
               FROM ultra_observer_devices
              WHERE demoted_at IS NOT NULL OR strikes > 0
              ORDER BY strikes DESC, last_rebuttal_at DESC LIMIT ' . $limit
        );
        return is_array($rows) ? $rows : [];
    }

    public function isDemoted(string $observerHash): bool
    {
        $observerHash = strtolower(trim($observerHash));
        if ($this->db === null || preg_match('/^[a-f0-9]{64}$/', $observerHash) !== 1) {
            return false;
        }
        $row = $this->db->fetchOne(
            'SELECT demoted_at FROM ultra_observer_devices WHERE observer_hash = :observer_hash LIMIT 1',
            ['observer_hash' => $observerHash]
        );
        return is_array($row) && trim((string) ($row['demoted_at'] ?? '')) !== '';
    }

    /** Remember which client device an observer hash belongs to, for the admin console. */
    public function rememberObserver(int $clientId, string $deviceId, string $observerHash): void
    {
        $observerHash = strtolower(trim($observerHash));
        $deviceId = trim($deviceId);
        if ($this->db === null || $clientId <= 0 || $deviceId === ''
            || preg_match('/^[a-f0-9]{64}$/', $observerHash) !== 1) {
            return;
        }
        $now = gmdate('Y-m-d H:i:s', $this->now());
        $this->db->execute(
            'INSERT INTO ultra_observer_devices (observer_hash, client_id, device_id, strikes, last_seen_at, created_at)
             VALUES (:observer_hash, :client_id, :device_id, 0, :last_seen_at, :created_at)
             ON DUPLICATE KEY UPDATE last_seen_at = VALUES(last_seen_at), client_id = VALUES(client_id), device_id = VALUES(device_id)',
            [
                'observer_hash' => $observerHash,
                'client_id' => $clientId,
                'device_id' => mb_substr($deviceId, 0, 191),
                'last_seen_at' => $now,
                'created_at' => $now,
            ]
        );
    }

    /** Queue one review per quarantined candidate of this source that has none open. */
    private function enqueueQuarantined(string $sourceId, int $limit): void
    {
        $rows = $this->db->fetchAll(
            'SELECT c.candidate_key_hash, c.source_id, c.line_id, c.protocol
               FROM ultra_candidate_reputation_current c
               LEFT JOIN ultra_candidate_reviews r
                      ON r.candidate_key_hash = c.candidate_key_hash
                     AND (r.state IN (:pending, :running)
                          OR (r.state = :failed
                              AND r.completed_at IS NOT NULL
                              AND r.completed_at > :retry_after))
              WHERE c.state = :quarantined
                AND c.source_id = :source_id
                AND c.quarantine_until IS NOT NULL
                AND c.quarantine_until > :now
                AND r.id IS NULL
              ORDER BY c.quarantine_until ASC
              LIMIT ' . $limit,
            [
                'pending' => self::STATE_PENDING,
                'running' => self::STATE_RUNNING,
                'failed' => self::STATE_FAILED,
                'retry_after' => gmdate('Y-m-d H:i:s', $this->now() - self::REENQUEUE_COOLDOWN_SECONDS),
                'quarantined' => 'quarantined',
                'source_id' => $sourceId,
                'now' => gmdate('Y-m-d H:i:s', $this->now()),
            ]
        );
        if (!is_array($rows)) {
            return;
        }
        $requestedAt = gmdate('Y-m-d H:i:s', $this->now());
        foreach ($rows as $row) {
            $hash = strtolower(trim((string) ($row['candidate_key_hash'] ?? '')));
            if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                continue;
            }
            $this->db->execute(
                'INSERT INTO ultra_candidate_reviews
                    (candidate_key_hash, source_id, line_id, protocol, state, attempts, requested_at)
                 VALUES (:candidate_key_hash, :source_id, :line_id, :protocol, :state, 0, :requested_at)',
                [
                    'candidate_key_hash' => $hash,
                    'source_id' => (string) ($row['source_id'] ?? $sourceId),
                    'line_id' => max(0, (int) ($row['line_id'] ?? 0)),
                    'protocol' => mb_substr((string) ($row['protocol'] ?? 'tcp+udp'), 0, 16),
                    'state' => self::STATE_PENDING,
                    'requested_at' => $requestedAt,
                ]
            );
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function claimPending(string $sourceId, int $limit): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, candidate_key_hash, source_id, line_id, protocol, attempts
               FROM ultra_candidate_reviews
              WHERE source_id = :source_id AND state = :pending
              ORDER BY requested_at ASC
              LIMIT ' . $limit,
            ['source_id' => $sourceId, 'pending' => self::STATE_PENDING]
        );
        if (!is_array($rows)) {
            return [];
        }
        $claimed = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $updated = $this->db->execute(
                'UPDATE ultra_candidate_reviews
                    SET state = :running, started_at = :started_at, attempts = attempts + 1
                  WHERE id = :id AND state = :pending',
                [
                    'running' => self::STATE_RUNNING,
                    'started_at' => gmdate('Y-m-d H:i:s', $this->now()),
                    'id' => $id,
                    'pending' => self::STATE_PENDING,
                ]
            );
            if ($updated <= 0) {
                continue;
            }
            $claimed[] = [
                'review_id' => $id,
                'line_id' => (int) ($row['line_id'] ?? 0),
                'protocol' => (string) ($row['protocol'] ?? 'tcp+udp'),
                'seconds' => self::REVIEW_SECONDS,
            ];
        }
        return $claimed;
    }

    /**
     * A source that took a review and never reported back gets another chance -
     * but only up to MAX_ATTEMPTS. Past that the row is closed as abandoned.
     *
     * Leaving it in "running" would leak in two directions: the row would never
     * be handed out again, and enqueueQuarantined() treats an open review as a
     * reason not to queue another, so a still-quarantined candidate would sit
     * there with no way back for as long as the quarantine holds. Closing it
     * costs the line nothing: no probe ever disproved it, so neither the
     * quarantine nor any device's standing is touched.
     */
    private function expireStaleRunning(): void
    {
        $cutoff = gmdate('Y-m-d H:i:s', $this->now() - self::STALE_RUNNING_SECONDS);
        $this->db->execute(
            'UPDATE ultra_candidate_reviews
                SET state = :pending, started_at = NULL
              WHERE state = :running
                AND started_at IS NOT NULL
                AND started_at < :cutoff
                AND attempts < :max_attempts',
            [
                'pending' => self::STATE_PENDING,
                'running' => self::STATE_RUNNING,
                'cutoff' => $cutoff,
                'max_attempts' => self::MAX_ATTEMPTS,
            ]
        );
        $this->db->execute(
            'UPDATE ultra_candidate_reviews
                SET state = :failed, completed_at = :completed_at, result_json = :result_json
              WHERE state = :running
                AND started_at IS NOT NULL
                AND started_at < :cutoff
                AND attempts >= :max_attempts',
            [
                'failed' => self::STATE_FAILED,
                'running' => self::STATE_RUNNING,
                'cutoff' => $cutoff,
                'max_attempts' => self::MAX_ATTEMPTS,
                'completed_at' => gmdate('Y-m-d H:i:s', $this->now()),
                'result_json' => json_encode(
                    ['abandoned' => true, 'note' => 'source host did not report back'],
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                ),
            ]
        );
    }

    private function liftQuarantine(string $candidateHash): void
    {
        $this->db->execute(
            'UPDATE ultra_candidate_reputation_current
                SET state = :healthy,
                    quarantine_until = NULL,
                    consecutive_failures = 0,
                    consecutive_successes = 0,
                    backoff_level = GREATEST(backoff_level - 1, 0),
                    last_reason = :reason,
                    updated_at = :updated_at
              WHERE candidate_key_hash = :candidate_key_hash',
            [
                'healthy' => 'healthy',
                'reason' => 'review_passed',
                'updated_at' => gmdate('Y-m-d H:i:s', $this->now()),
                'candidate_key_hash' => $candidateHash,
            ]
        );
    }

    /** @return array<int, string> */
    private function reportingObservers(string $candidateHash): array
    {
        $rows = $this->db->fetchAll(
            'SELECT DISTINCT observer_hash
               FROM ultra_candidate_reputation_events
              WHERE candidate_key_hash = :candidate_key_hash
                AND event_type = \'failure\'
                AND observer_hash IS NOT NULL
                AND observer_hash <> \'\'',
            ['candidate_key_hash' => $candidateHash]
        );
        if (!is_array($rows)) {
            return [];
        }
        $observers = [];
        foreach ($rows as $row) {
            $hash = strtolower(trim((string) ($row['observer_hash'] ?? '')));
            if (preg_match('/^[a-f0-9]{64}$/', $hash) === 1) {
                $observers[] = $hash;
            }
        }
        return $observers;
    }

    /**
     * A rebutted report costs the device a strike; a confirmed one earns one back.
     * Striked devices stop counting towards the independent-observer requirement.
     *
     * @param array<int, string> $observers
     */
    private function adjustObserverStrikes(array $observers, bool $passed): void
    {
        if ($observers === []) {
            return;
        }
        $now = gmdate('Y-m-d H:i:s', $this->now());
        foreach ($observers as $observerHash) {
            if ($passed) {
                $this->db->execute(
                    'UPDATE ultra_observer_devices
                        SET demoted_at = CASE WHEN strikes + 1 >= :threshold THEN COALESCE(demoted_at, :now) ELSE demoted_at END,
                            strikes = strikes + 1,
                            last_rebuttal_at = :now
                      WHERE observer_hash = :observer_hash',
                    ['threshold' => self::DEMOTION_STRIKES, 'now' => $now, 'observer_hash' => $observerHash]
                );
                continue;
            }
            $this->db->execute(
                'UPDATE ultra_observer_devices
                    SET demoted_at = CASE WHEN strikes <= :threshold THEN NULL ELSE demoted_at END,
                        strikes = GREATEST(strikes - 1, 0)
                  WHERE observer_hash = :observer_hash',
                ['threshold' => self::DEMOTION_STRIKES, 'observer_hash' => $observerHash]
            );
        }
    }
    private function now(): int
    {
        return (int) ($this->clock)();
    }
}