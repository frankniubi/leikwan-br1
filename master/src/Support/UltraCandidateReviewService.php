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
    // Evidence a review must carry before it may change anything.
    //
    // The probe runs on the source host and can only ever speak for the source
    // host's path to the relay it picked. A pass is discriminating only when the
    // probe exercised an endpoint the panel actually advertises for that line -
    // one of the relays the client is handed - and moved real bytes end to end
    // through it. A relay-local keep echo is not evidence about a client path,
    // and a probe of some other endpoint is not evidence about this candidate.
    // Non-discriminating results are recorded for audit and change NOTHING: a
    // device must never take a strike, and a candidate must never lose its
    // quarantine, on evidence that cannot discriminate.
    public const PROOF_OK = 'advertised_endpoint_end_to_end';
    public const PROOF_REASON_MISSING = 'proof_missing';
    public const PROOF_REASON_ENDPOINT = 'endpoint_not_advertised';
    public const PROOF_REASON_LOCAL = 'local_path_only';
    public const PROOF_REASON_TRANSFER = 'no_end_to_end_transfer';

    /** A running review that stops reporting is retried after this long. */
    public const STALE_RUNNING_SECONDS = 1800;
    /** Claims one review may use before it is closed as abandoned. */
    public const MAX_ATTEMPTS = 5;
    /** How long a closed review keeps a still-quarantined candidate from being re-queued. */
    public const REENQUEUE_COOLDOWN_SECONDS = 21600;
    // A strike is not a life sentence. A device that stops being disproved gets
    // one strike back per window and regains its standing as an independent
    // observer once it is below the demotion threshold, so a client bug cannot
    // demote a device for good.
    public const STRIKE_DECAY_SECONDS = 604800;
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
        $this->expireStaleRunning();
        $this->decayDeviceStrikes();
        $limit = max(0, min(8, $limit));
        if ($limit === 0) {
            // A source whose agent does not run reviews must not be handed any: it
            // would never report back, and the row would burn its attempts out.
            return [];
        }
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
            $proof = $this->reviewProof($row, $detail);
            $discriminating = (bool) ($proof['ok'] ?? false);
            // A device is only rebutted - and a quarantine only lifted - by a
            // review that could actually discriminate. Everything else keeps the
            // candidate quarantined and every witness unstruck.
            if ($discriminating) {
                if ($passed) {
                    $this->liftQuarantine($hash);
                }
                $this->adjustObserverStrikes($observers, $passed);
            }
            $payload = [
                'passed' => $passed,
                'note' => $this->clip(trim($note), 240),
                'detail' => $detail,
                'observers' => count($observers),
                'proof' => $proof,
                'rebuttal' => $discriminating ? 'applied' : 'blocked',
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
                'device_id' => $this->clip($deviceId, 191),
                'last_seen_at' => $now,
                'created_at' => $now,
            ]
        );
    }

    /**
     * mbstring is not built into every PHP the panel serves requests with, and the
     * CLI that runs migrations is a different build than the FPM pool - an
     * unguarded mb_substr() here is a fatal error on the source jobs endpoint.
     */
    private function clip(string $value, int $limit): string
    {
        return function_exists('mb_substr')
            ? mb_substr($value, 0, $limit, 'UTF-8')
            : substr($value, 0, $limit);
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
                    'protocol' => $this->clip((string) ($row['protocol'] ?? 'tcp+udp'), 16),
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

    /**
     * Give a strike back to every device that has not been disproved recently.
     *
     * demoted_at is assigned before strikes so the comparison sees the value the
     * device had when the statement ran.
     */
    private function decayDeviceStrikes(): void
    {
        $cutoff = gmdate('Y-m-d H:i:s', $this->now() - self::STRIKE_DECAY_SECONDS);
        $this->db->execute(
            'UPDATE ultra_observer_devices
                SET demoted_at = CASE WHEN strikes - 1 < :threshold THEN NULL ELSE demoted_at END,
                    strikes = strikes - 1,
                    last_decay_at = :now
              WHERE strikes > 0
                AND (last_decay_at IS NULL OR last_decay_at < :cutoff)
                AND (last_rebuttal_at IS NULL OR last_rebuttal_at < :cutoff)',
            [
                'threshold' => self::DEMOTION_STRIKES,
                'now' => gmdate('Y-m-d H:i:s', $this->now()),
                'cutoff' => $cutoff,
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
     * Decide whether a review result may change a device's standing.
     *
     * The proof has to come from the probe and is re-verified here against the
     * panel's own inventory: the panel never trusts an agent's claim that the
     * endpoint it used was an advertised one.
     *
     * @param array<string, mixed> $reviewRow
     * @param array<string, mixed> $detail
     * @return array<string, mixed>
     */
    private function reviewProof(array $reviewRow, array $detail): array
    {
        $raw = $detail['proof'] ?? null;
        if (!is_array($raw)) {
            // The probe that was running in the field before this gate existed
            // sent no proof at all, and an older agent never will.
            return ['ok' => false, 'reason' => self::PROOF_REASON_MISSING, 'endpoint' => ''];
        }
        $endpoint = strtolower(trim((string) ($raw['endpoint'] ?? '')));
        $host = str_contains($endpoint, ':')
            ? strtolower(trim((string) strstr($endpoint, ':', true)))
            : $endpoint;
        $advertised = $this->advertisedRelayIps($reviewRow);
        if ($advertised === [] || filter_var($host, FILTER_VALIDATE_IP) === false
            || !in_array($host, $advertised, true)) {
            return [
                'ok' => false,
                'reason' => self::PROOF_REASON_ENDPOINT,
                'endpoint' => $endpoint,
                'advertised' => $advertised,
            ];
        }
        if (self::flagTrue($raw['local_path_only'] ?? true)) {
            return ['ok' => false, 'reason' => self::PROOF_REASON_LOCAL, 'endpoint' => $endpoint];
        }
        if ((int) ($raw['end_to_end_ok'] ?? 0) < 1) {
            return ['ok' => false, 'reason' => self::PROOF_REASON_TRANSFER, 'endpoint' => $endpoint];
        }
        return ['ok' => true, 'reason' => self::PROOF_OK, 'endpoint' => $endpoint];
    }

    /**
     * The relays the panel advertises for the reviewed line - exactly what a
     * client can be handed - keyed off the last accepted source upload.
     *
     * @param array<string, mixed> $reviewRow
     * @return array<int, string>
     */
    private function advertisedRelayIps(array $reviewRow): array
    {
        if ($this->db === null) {
            return [];
        }
        $lineId = (int) ($reviewRow['line_id'] ?? 0);
        if ($lineId <= 0) {
            return [];
        }
        // The source_id columns of these tables do not share a collation, so the
        // match happens in PHP rather than in the join.
        $rows = $this->db->fetchAll(
            'SELECT source_id, relay_ips_json FROM ultra_lines WHERE line_id = :line_id',
            ['line_id' => $lineId]
        );
        if (!is_array($rows)) {
            return [];
        }
        $wanted = trim((string) ($reviewRow['source_id'] ?? ''));
        $ips = [];
        foreach ($rows as $row) {
            if (!is_array($row) || trim((string) ($row['source_id'] ?? '')) !== $wanted) {
                continue;
            }
            $decoded = json_decode((string) ($row['relay_ips_json'] ?? ''), true);
            foreach (is_array($decoded) ? $decoded : [] as $ip) {
                $ip = strtolower(trim((string) $ip));
                if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                    $ips[$ip] = true;
                }
            }
        }
        return array_keys($ips);
    }

    private static function flagTrue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }
        return false;
    }

    /**
     * Detection health.
     *
     * A detector that has never returned a real negative is itself a fault
     * signal, and so is a candidate that keeps collecting failure reports after
     * every witness that reported them has been struck off. Both are computed
     * here so something can say them out loud instead of reporting "healthy".
     *
     * @return array<string, mixed>
     */
    public function reviewHealth(int $windowSeconds = 86400): array
    {
        $windowSeconds = max(3600, min(2592000, $windowSeconds));
        $health = [
            'window_seconds' => $windowSeconds,
            'reviews_completed' => 0,
            'reviews_passed' => 0,
            'reviews_failed' => 0,
            'reviews_open' => 0,
            'pass_rate' => null,
            'candidates_reviewed' => 0,
            'candidates_total' => 0,
            'devices_rebutted' => 0,
            'devices_demoted' => 0,
            'client_failures_on_reviewed_candidates' => 0,
            'witness_losses' => [],
            'alarms' => [],
        ];
        if ($this->db === null) {
            return $health;
        }
        $since = gmdate('Y-m-d H:i:s', $this->now() - $windowSeconds);

        $rows = $this->db->fetchAll(
            'SELECT state, COUNT(*) AS total
               FROM ultra_candidate_reviews
              WHERE completed_at IS NOT NULL AND completed_at >= :since
              GROUP BY state',
            ['since' => $since]
        );
        foreach (is_array($rows) ? $rows : [] as $row) {
            $total = max(0, (int) ($row['total'] ?? 0));
            $health['reviews_completed'] += $total;
            if ((string) ($row['state'] ?? '') === self::STATE_PASSED) {
                $health['reviews_passed'] = $total;
            } elseif ((string) ($row['state'] ?? '') === self::STATE_FAILED) {
                $health['reviews_failed'] = $total;
            }
        }
        $open = $this->db->fetchOne(
            'SELECT COUNT(*) AS total FROM ultra_candidate_reviews WHERE state IN (:pending, :running)',
            ['pending' => self::STATE_PENDING, 'running' => self::STATE_RUNNING]
        );
        $health['reviews_open'] = is_array($open) ? (int) ($open['total'] ?? 0) : 0;
        if ($health['reviews_completed'] > 0) {
            $health['pass_rate'] = round($health['reviews_passed'] / $health['reviews_completed'], 4);
        }
        $total = $this->db->fetchOne('SELECT COUNT(*) AS total FROM ultra_candidate_reputation_current');
        $health['candidates_total'] = is_array($total) ? (int) ($total['total'] ?? 0) : 0;
        $reviewed = $this->db->fetchAll(
            'SELECT DISTINCT candidate_key_hash FROM ultra_candidate_reviews
              WHERE completed_at IS NOT NULL AND completed_at >= :since LIMIT 200',
            ['since' => $since]
        );
        $hashes = [];
        foreach (is_array($reviewed) ? $reviewed : [] as $row) {
            $hash = strtolower(trim((string) ($row['candidate_key_hash'] ?? '')));
            if (preg_match('/^[a-f0-9]{64}$/', $hash) === 1) {
                $hashes[$hash] = true;
            }
        }
        $health['candidates_reviewed'] = count($hashes);
        if ($hashes !== []) {
            $params = ['since' => $since];
            $placeholders = [];
            $index = 0;
            foreach (array_keys($hashes) as $hash) {
                $key = 'h' . $index++;
                $placeholders[] = ':' . $key;
                $params[$key] = $hash;
            }
            // The hash is a literal here on purpose: the reviews and events
            // tables do not share a collation, so joining them is an error.
            $failures = $this->db->fetchOne(
                'SELECT COUNT(*) AS total FROM ultra_candidate_reputation_events
                  WHERE event_type = \'failure\' AND observed_at >= :since
                    AND candidate_key_hash IN (' . implode(', ', $placeholders) . ')',
                $params
            );
            $health['client_failures_on_reviewed_candidates'] = is_array($failures)
                ? (int) ($failures['total'] ?? 0)
                : 0;
        }
        $rebutted = $this->db->fetchOne(
            'SELECT COUNT(*) AS total FROM ultra_observer_devices
              WHERE last_rebuttal_at IS NOT NULL AND last_rebuttal_at >= :since',
            ['since' => $since]
        );
        $health['devices_rebutted'] = is_array($rebutted) ? (int) ($rebutted['total'] ?? 0) : 0;
        $demoted = $this->db->fetchOne(
            'SELECT COUNT(*) AS total FROM ultra_observer_devices
              WHERE demoted_at IS NOT NULL AND demoted_at >= :since',
            ['since' => $since]
        );
        $health['devices_demoted'] = is_array($demoted) ? (int) ($demoted['total'] ?? 0) : 0;
        $health['witness_losses'] = $this->witnessLosses($windowSeconds);

        if ($health['reviews_completed'] > 0
            && $health['reviews_passed'] === $health['reviews_completed']
            && $health['client_failures_on_reviewed_candidates'] > 0) {
            $health['alarms'][] = 'review_pass_rate_100_percent_while_clients_report_failures';
        }
        if ($health['witness_losses'] !== []) {
            $health['alarms'][] = 'candidate_witness_pool_exhausted';
        }
        return $health;
    }

    /**
     * Candidates that keep collecting failure reports while every device that
     * ever reported one has been demoted: the quarantine path can no longer see
     * them at all, so they read as healthy forever.
     *
     * @return array<int, array<string, mixed>>
     */
    public function witnessLosses(int $windowSeconds = 86400, int $limit = 50): array
    {
        if ($this->db === null) {
            return [];
        }
        $windowSeconds = max(3600, min(2592000, $windowSeconds));
        $limit = max(1, min(self::MAX_RECENT, $limit));
        $rows = $this->db->fetchAll(
            'SELECT c.line_id, c.destination_zone,
                    COUNT(DISTINCT e.observer_hash) AS witnesses_all_time,
                    COUNT(DISTINCT CASE WHEN d.demoted_at IS NULL THEN e.observer_hash END) AS witnesses_eligible,
                    SUM(CASE WHEN e.observed_at >= :since THEN 1 ELSE 0 END) AS failures_in_window
               FROM ultra_candidate_reputation_current c
               JOIN ultra_candidate_reputation_events e
                 ON e.candidate_key_hash = c.candidate_key_hash
                AND e.event_type = \'failure\'
               LEFT JOIN ultra_observer_devices d ON d.observer_hash = e.observer_hash
              GROUP BY c.candidate_key_hash, c.line_id, c.destination_zone
             HAVING witnesses_eligible <= 1 AND failures_in_window > 0
              ORDER BY failures_in_window DESC, witnesses_all_time DESC
              LIMIT ' . $limit,
            ['since' => gmdate('Y-m-d H:i:s', $this->now() - $windowSeconds)]
        );
        return is_array($rows) ? $rows : [];
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