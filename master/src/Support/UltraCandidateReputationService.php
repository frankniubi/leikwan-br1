<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Database\Database;

/**
 * Scoped, time-bounded reputation for Ultra inventory candidates.
 *
 * This service deliberately keeps dynamic reputation separate from the static
 * `ultra_lines.is_enabled` and combination-membership controls. The latter
 * remain hard operator-owned gates.
 */
final class UltraCandidateReputationService
{
    public const STATE_HEALTHY = 'healthy';
    public const STATE_PROBATION = 'probation';
    public const STATE_QUARANTINED = 'quarantined';

    public const DEFAULT_PROBATION_FAILURES = 2;
    public const DEFAULT_QUARANTINE_FAILURES = 3;
    public const DEFAULT_MIN_INDEPENDENT_OBSERVERS = 2;
    // A dead line is expensive: one client keeps re-selecting it and every
    // connection through it fails. Start at an hour and double per repeat
    // offence up to a day, so a chronically bad candidate spends most of its
    // time out of rotation instead of being retried every 30 minutes.
    public const DEFAULT_QUARANTINE_SECONDS = 3600;
    public const DEFAULT_MAX_BACKOFF_SECONDS = 86400;
    // Serve a rung off the ladder for every clean day: a line that was really
    // fixed works its way back to a normal quarantine instead of carrying a
    // permanent record.
    public const DEFAULT_BACKOFF_DECAY_SECONDS = 86400;
    public const DEFAULT_RECOVERY_SUCCESSES = 3;
    public const MAX_FEEDBACK = 32;
    // One observer counts for one failure per window, however many times it
    // retried inside it. The client retries a candidate every few seconds, and
    // each attempt carries a fresh evidence id, so an unbounded window let a
    // single device manufacture a quarantine out of one broken local path.
    public const FAILURE_EVIDENCE_WINDOW_SECONDS = 600;

    /** @var array<int, string> */
    private const FAILURE_REASONS = [
        'provider_handshake_failed',
        'udp_association_failed',
        'relay_connect_timeout',
        'stream_reset',
        'data_plane_no_reply',
        // The relay could not be reached at all. That is evidence about the line.
        'udp_endpoint_unreachable',
    ];
    // Deliberately not failure reasons: a candidate that was never armed says
    // nothing about the line. The relay accepted the control connection and then
    // refused the session ('udp_arm_failed'), which the very next attempt from
    // the same host usually disproves. Clients used to report these as
    // udp_association_failed, which is how healthy lines were quarantined.
    public const TRANSPORT_REASONS = [
        'udp_arm_failed',
        // Selection probes the whole candidate batch before it arms anything, so
        // a rejected candidate is not yet a claim about the line - it is a claim
        // that this endpoint did not answer the probe from this host. Recorded,
        // never counted as a failure strike, exactly like udp_arm_failed.
        //
        // These two closed a real blind spot: a relay that accepted the TCP
        // control connection and then closed the datagram handshake
        // ("datagram handshake: EOF") produced no feedback at all, because the
        // runtime only reported the batch-level error and rejected the candidate
        // locally. Nothing could demote such an endpoint, so every batch probed
        // it again.
        'probe_udp_failed',
        'probe_tcp_failed',
    ];

    /** @var callable():int */
    private $clock;

    /** @var array<string, array<string, mixed>> */
    private array $memoryRows = [];

    /** @var array<string, true> */
    private array $memoryEvidence = [];

    /** @var array<string, array<string, true>> */
    private array $memoryObservers = [];

    /** @var array<string, array<string, true>> */
    private array $memorySuccessObservers = [];

    /** @var array<string, int> */
    private array $memoryLastFailure = [];

    private int $probationFailures;
    private int $quarantineFailures;
    private int $minimumObservers;
    private int $quarantineSeconds;
    private int $maxBackoffSeconds;
    private int $recoverySuccesses;
    private int $backoffDecaySeconds;

    /**
     * @param object|null $db Existing Database wrapper; null is an in-memory
     *                       implementation used by contract tests and dry runs.
     * @param callable():int|null $clock
     * @param array<string, int>|null $options
     */
    public function __construct(
        private $db = null,
        ?callable $clock = null,
        ?array $options = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
        $options ??= [];
        $this->probationFailures = self::boundedOption($options, 'probation_failures', self::DEFAULT_PROBATION_FAILURES, 1, 32);
        $this->quarantineFailures = max(
            $this->probationFailures,
            self::boundedOption($options, 'quarantine_failures', self::DEFAULT_QUARANTINE_FAILURES, 1, 64)
        );
        $this->minimumObservers = self::boundedOption($options, 'minimum_observers', self::DEFAULT_MIN_INDEPENDENT_OBSERVERS, 1, 64);
        $this->quarantineSeconds = self::boundedOption($options, 'quarantine_seconds', self::DEFAULT_QUARANTINE_SECONDS, 60, 86400);
        $this->maxBackoffSeconds = self::boundedOption($options, 'max_backoff_seconds', self::DEFAULT_MAX_BACKOFF_SECONDS, $this->quarantineSeconds, 604800);
        $this->recoverySuccesses = self::boundedOption($options, 'recovery_successes', self::DEFAULT_RECOVERY_SUCCESSES, 1, 32);
        $this->backoffDecaySeconds = self::boundedOption($options, 'backoff_decay_seconds', self::DEFAULT_BACKOFF_DECAY_SECONDS, 3600, 604800);
    }

    /** @param array<string, mixed> $scope */
    public static function normalizeScope(array $scope): ?array
    {
        $sourceId = trim((string) ($scope['source_id'] ?? ''));
        $lineId = (int) ($scope['line_id'] ?? 0);
        $ingress = self::normalizeZone($scope['ingress_zone'] ?? 'unknown');
        $destination = self::normalizeZone($scope['destination_zone'] ?? 'unknown');
        $protocol = strtolower(trim((string) ($scope['protocol'] ?? '')));
        if ($sourceId === '' || preg_match('/^[A-Za-z0-9._:-]{1,191}$/', $sourceId) !== 1
            || $lineId < 1 || $lineId > 45535
            || $ingress === null || $destination === null
            || !in_array($protocol, ['tcp', 'udp', 'tcp+udp', 'native_socks5'], true)) {
            return null;
        }
        return [
            'source_id' => $sourceId,
            'line_id' => $lineId,
            'ingress_zone' => $ingress,
            'destination_zone' => $destination,
            'protocol' => $protocol,
        ];
    }

    /** @param array<string, mixed> $scope */
    public static function candidateKey(array $scope): string
    {
        $normalized = self::normalizeScope($scope);
        if ($normalized === null) {
            return '';
        }
        return implode('|', [
            $normalized['source_id'],
            (string) $normalized['line_id'],
            $normalized['ingress_zone'],
            $normalized['destination_zone'],
            $normalized['protocol'],
        ]);
    }

    /** @param array<string, mixed> $scope */
    public static function candidateKeyHash(array $scope): string
    {
        $key = self::candidateKey($scope);
        return $key === '' ? '' : hash('sha256', $key);
    }

    /**
     * Return a deterministic fingerprint of the dynamic states that can
     * change candidate eligibility. Failure counters alone are intentionally
     * excluded; generation rotation occurs when a candidate enters/leaves
     * probation or quarantine, not for every telemetry sample.
     *
     * @param array<int, array<string, mixed>> $scopes
     */
    public function snapshotFingerprint(array $scopes): string
    {
        $parts = [];
        foreach ($scopes as $scope) {
            if (!is_array($scope)) {
                continue;
            }
            $normalized = self::normalizeScope($scope);
            if ($normalized === null) {
                continue;
            }
            $candidateHash = self::candidateKeyHash($normalized);
            $row = $this->stateFor($normalized);
            $parts[$candidateHash] = implode(':', [
                $candidateHash,
                (string) ($row['state'] ?? self::STATE_HEALTHY),
                (string) ((int) ($row['quarantine_until'] ?? 0)),
                (string) ((int) ($row['backoff_level'] ?? 0)),
            ]);
        }
        ksort($parts, SORT_STRING);
        return hash('sha256', $parts === [] ? 'empty' : implode('|', array_values($parts)));
    }

    /** @return array<string, mixed> */
    public function scopeForHash(string $candidateKeyHash): array
    {
        $candidateKeyHash = strtolower(trim($candidateKeyHash));
        if (preg_match('/^[a-f0-9]{64}$/', $candidateKeyHash) !== 1) {
            return [];
        }
        if ($this->db === null || $this->usesFakeTables()) {
            $row = $this->memoryRows[$candidateKeyHash] ?? null;
            return is_array($row) ? $this->scopeFromRow($row) : [];
        }
        $row = $this->db->fetchOne(
            'SELECT source_id, line_id, ingress_zone, destination_zone, protocol
             FROM ultra_candidate_reputation_current
             WHERE candidate_key_hash = :candidate_key_hash
             LIMIT 1',
            ['candidate_key_hash' => $candidateKeyHash]
        );
        return is_array($row) ? self::normalizeScope($row) ?? [] : [];
    }

    /** @return array<string, mixed> */
    public function observeFailureByHash(
        string $candidateKeyHash,
        string $reason,
        string $observerHash = '',
        string $evidenceId = '',
    ): array {
        $scope = $this->scopeForHash($candidateKeyHash);
        return $scope === [] ? [] : $this->observeFailure($scope, $reason, $observerHash, $evidenceId);
    }

    /** @return array<string, mixed> */
    public function observeSuccessByHash(
        string $candidateKeyHash,
        string $observerHash = '',
        string $evidenceId = '',
    ): array {
        $scope = $this->scopeForHash($candidateKeyHash);
        return $scope === [] ? [] : $this->observeSuccess($scope, $observerHash, $evidenceId);
    }

    public static function isFailureReason(string $reason): bool
    {
        return in_array(strtolower(trim($reason)), self::FAILURE_REASONS, true);
    }

    /** A transport hiccup that must not be read as evidence about the line. */
    public static function isTransportReason(string $reason): bool
    {
        return in_array(strtolower(trim($reason)), self::TRANSPORT_REASONS, true);
    }

    /** @param array<string, mixed> $scope @return array<string, mixed> */
    public function stateFor(array $scope): array
    {
        $normalized = self::normalizeScope($scope);
        if ($normalized === null) {
            return [];
        }
        $row = $this->loadRow($normalized, false);
        return $this->expireIfNeeded($normalized, $row);
    }

    /** @param array<string, mixed> $scope */
    public function isQuarantined(array $scope): bool
    {
        $row = $this->stateFor($scope);
        return ($row['state'] ?? self::STATE_HEALTHY) === self::STATE_QUARANTINED
            && (int) ($row['quarantine_until'] ?? 0) > $this->now();
    }

    /**
     * Remove dynamically quarantined candidates while preserving the original
     * order. If every candidate is quarantined, retain the requested floor.
     *
     * @param array<int, array<string, mixed>> $candidates
     * @return array<int, array<string, mixed>>
     */
    public function filterCandidates(array $candidates, int $floor = 1): array
    {
        $floor = max(1, min(2, $floor));
        $healthy = [];
        $quarantined = [];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $scope = is_array($candidate['_candidate_scope'] ?? null)
                ? $candidate['_candidate_scope']
                : $candidate;
            if ($this->isQuarantined($scope)) {
                $quarantined[] = $candidate;
            } else {
                $healthy[] = $candidate;
            }
        }
        if ($healthy === []) {
            return array_slice($quarantined, 0, $floor);
        }
        if (count($healthy) >= $floor) {
            return $healthy;
        }
        return array_merge($healthy, array_slice($quarantined, 0, $floor - count($healthy)));
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function observeFailure(
        array $scope,
        string $reason,
        string $observerHash = '',
        string $evidenceId = '',
    ): array {
        $normalized = self::normalizeScope($scope);
        $reason = strtolower(trim($reason));
        if ($normalized === null || !self::isFailureReason($reason)) {
            return [];
        }
        $candidateHash = self::candidateKeyHash($normalized);
        $observerHash = self::normalizeObserverHash($observerHash);
        $evidenceHash = $this->evidenceHash($candidateHash, 'failure', $observerHash, $reason, $evidenceId);
        return $this->withTransaction(function () use ($normalized, $candidateHash, $observerHash, $reason, $evidenceHash): array {
            $row = $this->loadRow($normalized, true);
            if ($this->evidenceSeen($candidateHash, $evidenceHash)) {
                return $row;
            }
            $this->rememberEvidence($candidateHash, $evidenceHash, $observerHash);
            $now = $this->now();
            $row['consecutive_failures'] = min(65535, (int) ($row['consecutive_failures'] ?? 0) + 1);
            $row['consecutive_successes'] = 0;
            $row['sample_count'] = min(4294967295, (int) ($row['sample_count'] ?? 0) + 1);
            $row['last_reason'] = $reason;
            $row['last_observed_at'] = $now;
            $row['independent_observers'] = $this->observerCount($candidateHash, $observerHash);
            $state = (string) ($row['state'] ?? self::STATE_HEALTHY);
            $until = (int) ($row['quarantine_until'] ?? 0);
            if ($state === self::STATE_QUARANTINED && $until > $now) {
                $this->persistRow($row);
                $this->persistEvent($row, 'failure', $reason, $observerHash, $evidenceHash);
                return $row;
            }
            // backoff_level counts how many times this candidate has already
            // been quarantined, and it survives recovery. A candidate with at
            // least one behind it is a repeat offender: the next corroborated
            // failure puts it straight back into quarantine instead of walking
            // the probation ramp again, and it resumes on the same ladder.
            // A full clean day buys one rung back, evaluated here because this
            // is the only place the level is consumed and the write path is
            // rare - stateFor() runs for every candidate on every resolution.
            $row = $this->decayBackoff($row);
            $previousQuarantines = (int) ($row['backoff_level'] ?? 0);
            if ($row['consecutive_failures'] >= $this->probationFailures) {
                $row['state'] = self::STATE_PROBATION;
            }
            $requiredFailures = $previousQuarantines >= 1 ? 1 : $this->quarantineFailures;
            if ($row['consecutive_failures'] >= $requiredFailures
                && $row['independent_observers'] >= $this->minimumObservers) {
                $row['state'] = self::STATE_QUARANTINED;
                $row['quarantine_until'] = $now + $this->backoffSeconds($previousQuarantines);
                $row['backoff_level'] = min(32, $previousQuarantines + 1);
            }
            $this->persistRow($row);
            $this->persistEvent($row, 'failure', $reason, $observerHash, $evidenceHash);
            return $row;
        });
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function observeSuccess(
        array $scope,
        string $observerHash = '',
        string $evidenceId = '',
    ): array {
        $normalized = self::normalizeScope($scope);
        if ($normalized === null) {
            return [];
        }
        $candidateHash = self::candidateKeyHash($normalized);
        $observerHash = self::normalizeObserverHash($observerHash);
        $evidenceHash = $this->evidenceHash($candidateHash, 'success', $observerHash, 'canary_probe_ok', $evidenceId);
        return $this->withTransaction(function () use ($normalized, $candidateHash, $observerHash, $evidenceHash): array {
            $row = $this->expireIfNeeded($normalized, $this->loadRow($normalized, true));
            if ($this->evidenceSeen($candidateHash, $evidenceHash)) {
                return $row;
            }
            $this->rememberEvidence($candidateHash, $evidenceHash, $observerHash);
            $now = $this->now();
            $row['sample_count'] = min(4294967295, (int) ($row['sample_count'] ?? 0) + 1);
            $row['consecutive_failures'] = 0;
            $row['consecutive_successes'] = min(65535, (int) ($row['consecutive_successes'] ?? 0) + 1);
            $row['last_reason'] = 'canary_probe_ok';
            $row['last_good_at'] = $now;
            $row['last_observed_at'] = $now;
            // Counted on EVERY success, not only once the threshold is crossed.
            // successObserverCount() is what accumulates the in-memory observer
            // set, exactly as observerCount() does on the failure side, so
            // calling it lazily inside the release branch would always see a
            // single device and the floor could never be met.
            $successObservers = $this->successObserverCount($candidateHash, $observerHash);
            $successes = (int) $row['consecutive_successes'];
            $quarantined = (string) ($row['state'] ?? self::STATE_HEALTHY) === self::STATE_QUARANTINED;
            if (!$quarantined && $successes >= $this->recoverySuccesses) {
                $row['state'] = self::STATE_HEALTHY;
                // backoff_level is deliberately NOT reset: the ladder is the
                // record of how often this candidate has been quarantined, and
                // a fixed line still works normally - the level only decides how
                // long the next quarantine lasts and whether the ramp is skipped.
                $row['quarantine_until'] = 0;
            } elseif ($quarantined && $successes >= $this->recoverySuccesses
                && $successObservers >= $this->minimumObservers) {
                // A quarantine used to be escapable only by its own timer. The
                // review path that was meant to disprove the reports cannot
                // discriminate (a source-host probe is not a client path), and
                // the failure path needs two independent witnesses to quarantine
                // in the first place - so the fleet's own successes are the only
                // honest signal left that a candidate works again.
                //
                // The asymmetry is deliberate and is the whole safety argument.
                // Releasing wrongly costs one serving window: the candidate
                // lands on PROBATION - the same state the timer expiry uses -
                // with its backoff rung intact, so the next corroborated failure
                // puts it straight back into quarantine, and that path still
                // demands the same independent witnesses. Never releasing costs
                // the candidate for the entire backoff, up to max_backoff_seconds.
                //
                // The floor is the same one a quarantine needs, so no single
                // device can manufacture a release any more than it can
                // manufacture a quarantine. Deliberately NOT HEALTHY: probation
                // restores serving identically (isQuarantined() is false once
                // quarantine_until is cleared) while keeping the ladder honest.
                $row['state'] = self::STATE_PROBATION;
                $row['quarantine_until'] = 0;
                $row['last_reason'] = 'quarantine_lifted_by_independent_successes';
            }
            $this->persistRow($row);
            $this->persistEvent($row, 'success', 'canary_probe_ok', $observerHash, $evidenceHash);
            return $row;
        });
    }

    /** @return array<int, array<string, mixed>> */
    public function latest(int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        if ($this->usesFakeTables() || $this->db === null) {
            $rows = array_values($this->memoryRows);
            usort($rows, static fn(array $left, array $right): int => [
                self::stateRank((string) ($left['state'] ?? self::STATE_HEALTHY)),
                -((int) ($left['last_observed_at'] ?? 0)),
            ] <=> [
                self::stateRank((string) ($right['state'] ?? self::STATE_HEALTHY)),
                -((int) ($right['last_observed_at'] ?? 0)),
            ]);
            return array_slice($rows, 0, $limit);
        }
        return $this->db->fetchAll(
            'SELECT * FROM ultra_candidate_reputation_current
             ORDER BY FIELD(state, \'quarantined\', \'probation\', \'healthy\'), updated_at DESC, id DESC
             LIMIT ' . $limit
        );
    }

    /** @param array<string, mixed> $options */
    private static function boundedOption(array $options, string $key, int $default, int $minimum, int $maximum): int
    {
        $value = array_key_exists($key, $options) ? (int) $options[$key] : $default;
        return max($minimum, min($maximum, $value));
    }

    private static function normalizeZone(mixed $value): ?string
    {
        $zone = strtolower(trim((string) $value));
        return preg_match('/^[a-z0-9-]{1,32}$/', $zone) === 1 ? $zone : null;
    }

    private static function normalizeObserverHash(string $value): string
    {
        $value = strtolower(trim($value));
        if (preg_match('/^[a-f0-9]{64}$/', $value) === 1) {
            return $value;
        }
        return hash('sha256', $value !== '' ? $value : 'anonymous');
    }

    private function evidenceHash(string $candidateHash, string $type, string $observerHash, string $reason, string $evidenceId): string
    {
        $evidenceId = trim($evidenceId);
        if ($type === 'failure') {
            // Ignore the client's id on purpose: it is per attempt, not per
            // observation, and the retry loop made it a failure amplifier.
            $evidenceId = 'window:' . (string) intdiv($this->now(), self::FAILURE_EVIDENCE_WINDOW_SECONDS);
        } elseif ($evidenceId === '') {
            $evidenceId = (string) intdiv($this->now(), 60);
        }
        return hash('sha256', implode('|', [$candidateHash, $type, $observerHash, $reason, $evidenceId]));
    }

    /** @param array<string, mixed> $scope @return array<string, mixed> */
    private function loadRow(array $scope, bool $forUpdate): array
    {
        $candidateHash = self::candidateKeyHash($scope);
        $default = $this->defaultRow($scope);
        if ($this->db === null || $this->usesFakeTables()) {
            if (!isset($this->memoryRows[$candidateHash])) {
                // Materialize a newly observed scope so shadow-mode feedback
                // can resolve the opaque hash even without a backing DB.
                $this->memoryRows[$candidateHash] = $default;
            }
            return $this->memoryRows[$candidateHash];
        }
        $sql = 'SELECT id, candidate_key_hash, source_id, line_id, ingress_zone,
                       destination_zone, protocol, state, consecutive_failures,
                       consecutive_successes, independent_observers, backoff_level,
                       quarantine_until, last_reason, last_observed_at, last_good_at,
                       sample_count, updated_at
                FROM ultra_candidate_reputation_current
                WHERE candidate_key_hash = :candidate_key_hash
                LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '');
        $row = $this->db->fetchOne($sql, ['candidate_key_hash' => $candidateHash]);
        if (!is_array($row)) {
            $now = gmdate('Y-m-d H:i:s', $this->now());
            $this->db->execute(
                'INSERT IGNORE INTO ultra_candidate_reputation_current
                    (candidate_key_hash, source_id, line_id, ingress_zone, destination_zone, protocol,
                     state, consecutive_failures, consecutive_successes, independent_observers,
                     backoff_level, quarantine_until, last_reason, last_observed_at, last_good_at,
                     sample_count, created_at, updated_at)
                 VALUES
                    (:candidate_key_hash, :source_id, :line_id, :ingress_zone, :destination_zone, :protocol,
                     :state, 0, 0, 0, 0, NULL, NULL, NULL, NULL, 0, :created_at, :updated_at)',
                [
                    'candidate_key_hash' => $candidateHash,
                    'source_id' => $scope['source_id'],
                    'line_id' => $scope['line_id'],
                    'ingress_zone' => $scope['ingress_zone'],
                    'destination_zone' => $scope['destination_zone'],
                    'protocol' => $scope['protocol'],
                    'state' => self::STATE_HEALTHY,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
            $row = $this->db->fetchOne($sql, ['candidate_key_hash' => $candidateHash]);
        }
        return is_array($row) ? $this->normalizeStoredRow($row, $scope) : $default;
    }

    /** @param array<string, mixed> $scope @return array<string, mixed> */
    private function defaultRow(array $scope): array
    {
        return [
            'candidate_key_hash' => self::candidateKeyHash($scope),
            'source_id' => $scope['source_id'],
            'line_id' => (int) $scope['line_id'],
            'ingress_zone' => $scope['ingress_zone'],
            'destination_zone' => $scope['destination_zone'],
            'protocol' => $scope['protocol'],
            'state' => self::STATE_HEALTHY,
            'consecutive_failures' => 0,
            'consecutive_successes' => 0,
            'independent_observers' => 0,
            'backoff_level' => 0,
            'quarantine_until' => 0,
            'last_reason' => '',
            'last_observed_at' => 0,
            'last_good_at' => 0,
            'sample_count' => 0,
            'updated_at' => $this->now(),
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function scopeFromRow(array $row): array
    {
        $scope = self::normalizeScope($row);
        return $scope ?? [];
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $scope @return array<string, mixed> */
    private function normalizeStoredRow(array $row, array $scope): array
    {
        $normalized = $this->defaultRow($scope);
        foreach ($normalized as $key => $default) {
            if (array_key_exists($key, $row)) {
                $normalized[$key] = $row[$key];
            }
        }
        foreach (['line_id', 'consecutive_failures', 'consecutive_successes', 'independent_observers', 'backoff_level', 'sample_count'] as $key) {
            $normalized[$key] = max(0, (int) ($normalized[$key] ?? 0));
        }
        foreach (['quarantine_until', 'last_observed_at', 'last_good_at', 'updated_at'] as $key) {
            $value = $normalized[$key] ?? 0;
            $normalized[$key] = is_numeric($value) ? (int) $value : (strtotime((string) $value) ?: 0);
        }
        $normalized['state'] = in_array((string) $normalized['state'], [self::STATE_HEALTHY, self::STATE_PROBATION, self::STATE_QUARANTINED], true)
            ? (string) $normalized['state']
            : self::STATE_HEALTHY;
        return $normalized;
    }

    /** @param array<string, mixed> $scope @param array<string, mixed> $row @return array<string, mixed> */
    private function expireIfNeeded(array $scope, array $row): array
    {
        if (($row['state'] ?? self::STATE_HEALTHY) !== self::STATE_QUARANTINED
            || (int) ($row['quarantine_until'] ?? 0) <= 0
            || (int) ($row['quarantine_until'] ?? 0) > $this->now()) {
            return $row;
        }
        $row['state'] = self::STATE_PROBATION;
        $row['quarantine_until'] = 0;
        $row['consecutive_successes'] = 0;
        $row['last_reason'] = 'quarantine_expired';
        $row['updated_at'] = $this->now();
        $this->persistRow($row);
        return $row;
    }

    /** @param array<string, mixed> $row */
    private function persistRow(array $row): void
    {
        $candidateHash = (string) ($row['candidate_key_hash'] ?? '');
        if ($candidateHash === '') {
            return;
        }
        $row['updated_at'] = $this->now();
        if ($this->db === null || $this->usesFakeTables()) {
            $this->memoryRows[$candidateHash] = $row;
            return;
        }
        $this->db->execute(
            'UPDATE ultra_candidate_reputation_current
             SET state = :state,
                 consecutive_failures = :consecutive_failures,
                 consecutive_successes = :consecutive_successes,
                 independent_observers = :independent_observers,
                 backoff_level = :backoff_level,
                 quarantine_until = :quarantine_until,
                 last_reason = :last_reason,
                 last_observed_at = :last_observed_at,
                 last_good_at = :last_good_at,
                 sample_count = :sample_count,
                 updated_at = :updated_at
             WHERE candidate_key_hash = :candidate_key_hash',
            [
                'candidate_key_hash' => $candidateHash,
                'state' => $row['state'],
                'consecutive_failures' => (int) ($row['consecutive_failures'] ?? 0),
                'consecutive_successes' => (int) ($row['consecutive_successes'] ?? 0),
                'independent_observers' => (int) ($row['independent_observers'] ?? 0),
                'backoff_level' => (int) ($row['backoff_level'] ?? 0),
                'quarantine_until' => (int) ($row['quarantine_until'] ?? 0) > 0
                    ? gmdate('Y-m-d H:i:s', (int) $row['quarantine_until'])
                    : null,
                'last_reason' => (string) ($row['last_reason'] ?? ''),
                'last_observed_at' => (int) ($row['last_observed_at'] ?? 0) > 0
                    ? gmdate('Y-m-d H:i:s', (int) $row['last_observed_at'])
                    : null,
                'last_good_at' => (int) ($row['last_good_at'] ?? 0) > 0
                    ? gmdate('Y-m-d H:i:s', (int) $row['last_good_at'])
                    : null,
                'sample_count' => (int) ($row['sample_count'] ?? 0),
                'updated_at' => gmdate('Y-m-d H:i:s', $this->now()),
            ]
        );
    }

    /** @param array<string, mixed> $row */
    private function persistEvent(array $row, string $eventType, string $reason, string $observerHash, string $evidenceHash): void
    {
        $candidateHash = (string) ($row['candidate_key_hash'] ?? '');
        $eventKey = $candidateHash . ':' . $evidenceHash;
        if ($eventType === 'failure') {
            $this->memoryLastFailure[$candidateHash] = $this->now();
        }
        if ($this->db === null || $this->usesFakeTables()) {
            $this->memoryEvidence[$eventKey] = true;
            return;
        }
        $this->db->execute(
            'INSERT IGNORE INTO ultra_candidate_reputation_events
                (candidate_key_hash, event_type, state, reason, observer_hash, evidence_hash, observed_at)
             VALUES
                (:candidate_key_hash, :event_type, :state, :reason, :observer_hash, :evidence_hash, :observed_at)',
            [
                'candidate_key_hash' => $candidateHash,
                'event_type' => $eventType,
                'state' => (string) ($row['state'] ?? self::STATE_HEALTHY),
                'reason' => $reason,
                'observer_hash' => $observerHash,
                'evidence_hash' => $evidenceHash,
                'observed_at' => gmdate('Y-m-d H:i:s', $this->now()),
            ]
        );
    }

    private function evidenceSeen(string $candidateHash, string $evidenceHash): bool
    {
        if (isset($this->memoryEvidence[$candidateHash . ':' . $evidenceHash])) {
            return true;
        }
        if ($this->db === null || $this->usesFakeTables()) {
            return false;
        }
        return $this->db->fetchOne(
            'SELECT id FROM ultra_candidate_reputation_events
             WHERE candidate_key_hash = :candidate_key_hash AND evidence_hash = :evidence_hash
             LIMIT 1',
            ['candidate_key_hash' => $candidateHash, 'evidence_hash' => $evidenceHash]
        ) !== null;
    }

    private function rememberEvidence(string $candidateHash, string $evidenceHash, string $observerHash): void
    {
        $this->memoryEvidence[$candidateHash . ':' . $evidenceHash] = true;
        $this->memoryObservers[$candidateHash][$observerHash] = true;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function decayBackoff(array $row): array
    {
        $level = (int) ($row['backoff_level'] ?? 0);
        if ($level <= 0) {
            return $row;
        }
        $lastFailure = $this->lastFailureAt((string) ($row['candidate_key_hash'] ?? ''));
        if ($lastFailure <= 0) {
            return $row;
        }
        $elapsed = $this->now() - $lastFailure;
        if ($elapsed < $this->backoffDecaySeconds) {
            return $row;
        }
        $row['backoff_level'] = max(0, $level - intdiv($elapsed, $this->backoffDecaySeconds));
        return $row;
    }

    private function lastFailureAt(string $candidateHash): int
    {
        if ($candidateHash === '') {
            return 0;
        }
        if ($this->db === null || $this->usesFakeTables()) {
            return (int) ($this->memoryLastFailure[$candidateHash] ?? 0);
        }
        try {
            $row = $this->db->fetchOne(
                'SELECT MAX(observed_at) AS last_failure_at
                   FROM ultra_candidate_reputation_events
                  WHERE candidate_key_hash = :candidate_key_hash
                    AND event_type = \'failure\'',
                ['candidate_key_hash' => $candidateHash]
            );
        } catch (\Throwable) {
            return 0;
        }
        $value = $row['last_failure_at'] ?? null;
        if (!is_string($value) || trim($value) === '') {
            return 0;
        }
        // observed_at is written with gmdate(), so read it back as UTC.
        return (int) (strtotime(trim($value) . ' UTC') ?: 0);
    }

    private function observerCount(string $candidateHash, string $observerHash): int
    {
        $this->memoryObservers[$candidateHash][$observerHash] = true;
        if ($this->db === null || $this->usesFakeTables()) {
            return count($this->memoryObservers[$candidateHash] ?? []);
        }
        // A device whose reports the source host has disproved stops counting as
        // an independent observer: its failures still land in the event stream
        // for audit, but it can no longer quarantine a line on its own. Rows
        // without a device record (reports from before the reviews existed)
        // keep counting, which is why the join is a LEFT JOIN on demotion alone.
        $row = $this->db->fetchOne(
            'SELECT COUNT(DISTINCT e.observer_hash) AS observer_count,
                    SUM(CASE WHEN e.observer_hash = :observer_hash THEN 1 ELSE 0 END) AS observer_seen
               FROM ultra_candidate_reputation_events e
               LEFT JOIN ultra_observer_devices d ON d.observer_hash = e.observer_hash
              WHERE e.candidate_key_hash = :candidate_key_hash
                AND e.event_type = \'failure\'
                AND d.demoted_at IS NULL',
            ['candidate_key_hash' => $candidateHash, 'observer_hash' => $observerHash]
        );
        $count = (int) ($row['observer_count'] ?? 0);
        // This runs before the current failure is persisted, so the reporting
        // observer is never in the table yet. Counting it here keeps the
        // database path in step with the in-memory one, which counts it through
        // $memoryObservers; without it production needed one failure more than
        // the contract test to reach the same state.
        if ((int) ($row['observer_seen'] ?? 0) === 0) {
            $count++;
        }
        return max(1, $count);
    }

    /**
     * Distinct devices that have reported this candidate WORKING, excluding any
     * whose reports the source host has disproved.
     *
     * Structurally identical to observerCount() with the event type flipped,
     * except that it returns the honest count instead of max(1, ...): this
     * number gates a RELEASE, and inflating "nobody" into "one device" is the
     * same unrepresentable-zero defect that lets a single witness read as a
     * quorum on the failure side. observerCount()'s own max(1, ...) is left
     * alone here - it is load-bearing for the quarantine path and changing it is
     * a separate decision, not a side effect of this one.
     */
    private function successObserverCount(string $candidateHash, string $observerHash): int
    {
        $this->memorySuccessObservers[$candidateHash][$observerHash] = true;
        if ($this->db === null || $this->usesFakeTables()) {
            return count($this->memorySuccessObservers[$candidateHash] ?? []);
        }
        $row = $this->db->fetchOne(
            'SELECT COUNT(DISTINCT e.observer_hash) AS observer_count,
                    SUM(CASE WHEN e.observer_hash = :observer_hash THEN 1 ELSE 0 END) AS observer_seen
               FROM ultra_candidate_reputation_events e
               LEFT JOIN ultra_observer_devices d ON d.observer_hash = e.observer_hash
              WHERE e.candidate_key_hash = :candidate_key_hash
                AND e.event_type = \'success\'
                AND d.demoted_at IS NULL',
            ['candidate_key_hash' => $candidateHash, 'observer_hash' => $observerHash]
        );
        $count = (int) ($row['observer_count'] ?? 0);
        // This runs before the current success is persisted, so the reporting
        // observer is not in the table yet; count it here to stay in step with
        // the in-memory path.
        if ((int) ($row['observer_seen'] ?? 0) === 0) {
            $count++;
        }
        return $count;
    }

    private function backoffSeconds(int $level): int
    {
        $level = max(0, min(16, $level));
        $multiplier = 2 ** $level;
        return min($this->maxBackoffSeconds, $this->quarantineSeconds * $multiplier);
    }

    /** @param callable():array<string, mixed> $callback @return array<string, mixed> */
    private function withTransaction(callable $callback): array
    {
        if ($this->db === null || $this->usesFakeTables() || !method_exists($this->db, 'transaction')) {
            return $callback();
        }
        return $this->db->transaction(static fn() => $callback());
    }

    private function usesFakeTables(): bool
    {
        return is_object($this->db) && method_exists($this->db, 'tableRows');
    }

    private function now(): int
    {
        return (int) ($this->clock)();
    }

    private static function stateRank(string $state): int
    {
        return match (strtolower(trim($state))) {
            self::STATE_QUARANTINED => 0,
            self::STATE_PROBATION => 1,
            default => 2,
        };
    }
}
