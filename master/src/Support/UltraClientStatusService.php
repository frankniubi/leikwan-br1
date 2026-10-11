<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;

final class UltraClientStatusService
{
    public const MAX_STATUSES = 32;
    public const MAX_STATUS_BYTES = 65536;

    /**
     * Comma-separated extra known-good runtime build fingerprints, on top of
     * every released one (artifacts/versions.json plus
     * ULTRA_ATTESTATION_EXTRA_HASHES_JSON, the same set lease enforcement
     * accepts). Use it only for builds the panel never published. With no
     * releases and nothing configured there is no baseline: every attested
     * session is reported as unknown and nothing is ever denied.
     */
    public const RUNTIME_FINGERPRINTS_KEY = 'ULTRA_RUNTIME_FINGERPRINTS';
    /**
     * A released runtime stamps itself "<version>.<shortsha>" at link time
     * (e.g. 2026.09.12.1.4b106b02, see scripts/build-ultra-*.ps1); an older or
     * third-party build may report a bare hex build id instead. Both shapes are
     * accepted — anything else is dropped as unknown rather than persisted.
     */
    public const RUNTIME_FINGERPRINT_PATTERN = '/^(?:[0-9]{4}\.[0-9]{2}\.[0-9]{2}\.[0-9]+(?:\.[0-9a-f]{7,40})?|[a-f0-9]{8,64})$/';
    public const RUNTIME_SELF_SHA256_PATTERN = '/^[a-f0-9]{64}$/';

    public const ATTESTATION_STATE_KNOWN = 'known';
    public const ATTESTATION_STATE_UNKNOWN = 'unknown';
    public const ATTESTATION_STATE_MISMATCH = 'mismatch';

    public const MAX_MISMATCH_ROWS = 200;

    /** @param callable():int|null $clock */
    public function __construct(
        private Config $config,
        private Database $db,
        private $clock = null,
        ?UltraCandidateReputationService $candidateReputation = null,
        ?UltraCandidateReviewService $candidateReviews = null,
        private ?string $releaseManifestPath = null,
    ) {
        $this->clock ??= static fn(): int => time();
        $this->candidateReputation = $candidateReputation ?? new UltraCandidateReputationService($db, $this->clock);
        $this->candidateReviews = $candidateReviews ?? new UltraCandidateReviewService($db, $this->clock);
    }

    private UltraCandidateReputationService $candidateReputation;

    private UltraCandidateReviewService $candidateReviews;

    /** @var list<string>|null */
    private ?array $runtimeFingerprints = null;

    /**
     * @param array<int, mixed> $statuses
     * @param mixed $runtimeAttestation Optional session-level attestation for
     *        callers that already split it out of the status payload. An
     *        attestation carried inside a status entry is picked up as well.
     */
    public function ingest(int $clientId, string $deviceId, array $statuses, mixed $runtimeAttestation = null): int
    {
        $deviceId = trim($deviceId);
        if ($clientId <= 0 || $deviceId === '' || strlen($deviceId) > 191) {
            return 0;
        }
        $entries = array_slice(array_values($statuses), 0, self::MAX_STATUSES);
        $attestation = $this->extractRuntimeAttestation($runtimeAttestation, $entries);
        $assignments = $this->assignmentsForClients([$clientId]);
        $allowed = $assignments[$clientId] ?? [];
        $normalized = [];
        foreach ($entries as $status) {
            if (!is_array($status)) {
                continue;
            }
            $forwardId = (int) ($status['forward_id'] ?? 0);
            if (!isset($allowed[$forwardId]) || isset($normalized[$forwardId])) {
                continue;
            }
            $entry = $this->normalizeStatus($status, $forwardId);
            if ($entry !== null) {
                $normalized[$forwardId] = $entry;
            }
        }
        $encoded = json_encode(array_values($normalized), JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || strlen($encoded) > self::MAX_STATUS_BYTES) {
            return 0;
        }
        $now = gmdate('Y-m-d H:i:s', $this->now());
        $params = [
            'client_id' => $clientId,
            'device_id' => $deviceId,
            'ultra_status_json' => $encoded,
            'ultra_status_updated_at' => $now,
            'updated_at' => $now,
        ];
        $sql = 'UPDATE client_device_sessions
             SET ultra_status_json = :ultra_status_json,
                 ultra_status_updated_at = :ultra_status_updated_at,
                 updated_at = :updated_at';
        if ($attestation !== null) {
            // Only a payload that actually carries an attestation writes these
            // columns, so an older client never erases the last known values.
            $sql .= ',
                 ultra_runtime_fingerprint = :ultra_runtime_fingerprint,
                 ultra_runtime_self_sha256 = :ultra_runtime_self_sha256,
                 ultra_runtime_debugger = :ultra_runtime_debugger,
                 ultra_runtime_instrumented = :ultra_runtime_instrumented,
                 ultra_runtime_attested_at = :ultra_runtime_attested_at';
            $params['ultra_runtime_fingerprint'] = $attestation['fingerprint'];
            $params['ultra_runtime_self_sha256'] = $attestation['self_sha256'];
            $params['ultra_runtime_debugger'] = $attestation['debugger'] === null ? null : ($attestation['debugger'] ? 1 : 0);
            $params['ultra_runtime_instrumented'] = $attestation['instrumented'] === null ? null : ($attestation['instrumented'] ? 1 : 0);
            $params['ultra_runtime_attested_at'] = $now;
        }
        $sql .= '
             WHERE client_id = :client_id AND device_id = :device_id';
        $updated = $this->db->execute($sql, $params);
        return $updated > 0 ? count($normalized) : 0;
    }

    /**
     * Clear one device session's cached runtime identity so the next lease
     * attempt can bootstrap the runtime and repopulate attestation via
     * heartbeat. This is an operator recovery action, not a new trust grant.
     */
    public function clearRuntimeAttestation(int $clientId, string $deviceId): bool
    {
        $deviceId = trim($deviceId);
        if ($clientId <= 0 || $deviceId === '' || strlen($deviceId) > 191) {
            return false;
        }
        $session = $this->db->fetchOne(
            'SELECT id FROM client_device_sessions
              WHERE client_id = :client_id AND device_id = :device_id
              LIMIT 1',
            ['client_id' => $clientId, 'device_id' => $deviceId]
        );
        if (!is_array($session)) {
            return false;
        }
        $this->db->execute(
            'UPDATE client_device_sessions
                SET ultra_runtime_fingerprint = NULL,
                    ultra_runtime_self_sha256 = NULL,
                    ultra_runtime_debugger = NULL,
                    ultra_runtime_instrumented = NULL,
                    ultra_runtime_attested_at = NULL,
                    updated_at = :updated_at
              WHERE client_id = :client_id AND device_id = :device_id',
            [
                'client_id' => $clientId,
                'device_id' => $deviceId,
                'updated_at' => gmdate('Y-m-d H:i:s', $this->now()),
            ]
        );
        return true;
    }

    /**
     * Ingest authenticated, bounded data-plane outcomes keyed by the opaque
     * candidate hash issued in a lease. Client-supplied line IDs are never
     * accepted here.
     *
     * @param array<int, mixed> $feedback
     */
    public function ingestFeedback(int $clientId, string $deviceId, array $feedback): int
    {
        $deviceId = trim($deviceId);
        if ($clientId <= 0 || $deviceId === '') {
            return 0;
        }
        $assignments = $this->assignmentsForClients([$clientId]);
        $allowed = $assignments[$clientId] ?? [];
        $observerHash = hash('sha256', $clientId . ':' . $deviceId);
        // Keep the observer hash resolvable to a client device: the review flow
        // has to name the device whose failure reports it disproved.
        $this->candidateReviews->rememberObserver($clientId, $deviceId, $observerHash);
        $accepted = 0;
        $seen = [];
        $transport = [];
        foreach (array_slice(array_values($feedback), 0, UltraCandidateReputationService::MAX_FEEDBACK) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $forwardId = (int) ($item['forward_id'] ?? 0);
            $candidateHash = strtolower(trim((string) ($item['candidate_key_hash'] ?? '')));
            if ($forwardId <= 0 || !isset($allowed[$forwardId])
                || preg_match('/^[a-f0-9]{64}$/', $candidateHash) !== 1
                || !$this->feedbackBoundToLease($clientId, $deviceId, $forwardId, $candidateHash, $item['lease_hash'] ?? null)
                || isset($seen[$forwardId . ':' . $candidateHash])) {
                continue;
            }
            $scope = $this->candidateReputation->scopeForHash($candidateHash);
            $assignment = $allowed[$forwardId] ?? null;
            if ($scope === [] || !is_array($assignment)) {
                continue;
            }
            $protocol = strtolower(trim((string) ($item['protocol'] ?? '')));
            $scopeProtocol = (string) ($scope['protocol'] ?? '');
            $protocolMatches = $protocol !== ''
                && ($protocol === $scopeProtocol
                    || ($scopeProtocol === 'tcp+udp' && in_array($protocol, ['tcp', 'udp', 'tcp+udp'], true)));
            if (!$protocolMatches) {
                continue;
            }
            $destination = strtolower(trim((string) ($assignment['ultra_destination_zone'] ?? 'unknown')));
            if ($destination !== (string) ($scope['destination_zone'] ?? 'unknown')) {
                continue;
            }
            $reportedIngress = strtolower(trim((string) ($item['detected_ingress_zone'] ?? 'unknown')));
            if ($reportedIngress !== '' && $reportedIngress !== 'unknown'
                && $reportedIngress !== (string) ($scope['ingress_zone'] ?? 'unknown')) {
                continue;
            }
            $outcome = strtolower(trim((string) ($item['outcome'] ?? '')));
            $reason = strtolower(trim((string) ($item['reason'] ?? '')));
            // process_stderr is a diagnostic stream event, not data-plane proof.
            if (!in_array($outcome, ['failure', 'success'], true) || $reason === 'process_stderr') {
                continue;
            }
            if ($outcome === 'failure' && UltraCandidateReputationService::isTransportReason($reason)) {
                // A candidate that was never armed is not evidence about the
                // line, and the runtime emits one of these every few seconds
                // per forward. Count it for the log, keep it out of reputation.
                $transport[$reason] = (int) ($transport[$reason] ?? 0) + 1;
                continue;
            }
            if (($outcome === 'failure' && !UltraCandidateReputationService::isFailureReason($reason))
                || ($outcome === 'success' && $reason !== 'canary_probe_ok')) {
                continue;
            }
            $evidenceId = trim((string) ($item['evidence_id'] ?? ''));
            if ($evidenceId !== '' && preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $evidenceId) !== 1) {
                continue;
            }
            $result = $outcome === 'failure'
                ? $this->candidateReputation->observeFailureByHash($candidateHash, $reason, $observerHash, $evidenceId)
                : $this->candidateReputation->observeSuccessByHash($candidateHash, $observerHash, $evidenceId);
            if ($result !== []) {
                $seen[$forwardId . ':' . $candidateHash] = true;
                $accepted++;
            }
        }
        if ($transport !== []) {
            $parts = [];
            foreach ($transport as $reason => $count) {
                $parts[] = $reason . '=' . $count;
            }
            error_log(
                'ultra_candidate_transport_feedback client=' . $clientId
                . ' device=' . substr($deviceId, 0, 24) . ' ' . implode(' ', $parts)
            );
        }
        return $accepted;
    }

    /**
     * A candidate hash alone is not an authentication factor. Require the
     * client/device/forward tuple to point at a consumed, non-revoked lease
     * carrying the same opaque candidate and lease hashes. The lookup is
     * deliberately fail-closed so telemetry cannot weaken the lease boundary.
     */
    private function feedbackBoundToLease(
        int $clientId,
        string $deviceId,
        int $forwardId,
        string $candidateKeyHash,
        mixed $leaseHash,
    ): bool {
        $leaseHash = strtolower(trim((string) $leaseHash));
        if ($clientId <= 0 || $forwardId <= 0
            || preg_match('/^[a-f0-9]{64}$/', $candidateKeyHash) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $leaseHash) !== 1) {
            return false;
        }
        try {
            $row = $this->db->fetchOne(
                'SELECT id
                 FROM ultra_client_leases
                 WHERE client_id = :client_id
                   AND device_hash = :device_hash
                   AND forward_id = :forward_id
                   AND candidate_key_hash = :candidate_key_hash
                   AND lease_hash = :lease_hash
                   AND consumed_at IS NOT NULL
                   AND revoked_at IS NULL
                 LIMIT 1',
                [
                    'client_id' => $clientId,
                    'device_hash' => hash('sha256', trim($deviceId)),
                    'forward_id' => $forwardId,
                    'candidate_key_hash' => $candidateKeyHash,
                    'lease_hash' => $leaseHash,
                ]
            );
            return is_array($row) && isset($row['id']);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Normalize the client runtime attestation object.
     *
     * Report-only contract: a missing object, a missing field, or a field that
     * fails validation is preserved as NULL ("unknown"). Nothing here can fail
     * an ingest and an older client that never sends the object keeps working.
     * A malformed fingerprint/self_sha256 is never persisted.
     *
     * @return array{fingerprint:?string,self_sha256:?string,debugger:?bool,instrumented:?bool}|null
     *         null when the payload carries no usable attestation at all.
     */
    public static function normalizeRuntimeAttestation(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        $fingerprint = self::runtimeFingerprintOrNull($raw['fingerprint'] ?? null);
        $selfSha256 = self::runtimeSha256OrNull($raw['self_sha256'] ?? null);
        $debugger = self::coerceAttestationFlag($raw['debugger'] ?? null);
        $instrumented = self::coerceAttestationFlag($raw['instrumented'] ?? null);
        if ($fingerprint === null && $selfSha256 === null && $debugger === null && $instrumented === null) {
            return null;
        }
        return [
            'fingerprint' => $fingerprint,
            'self_sha256' => $selfSha256,
            'debugger' => $debugger,
            'instrumented' => $instrumented,
        ];
    }

    /** Hex build id injected at build time; validated, never echoed back raw. */
    public static function runtimeFingerprintOrNull(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $fingerprint = strtolower(trim((string) $value));
        return preg_match(self::RUNTIME_FINGERPRINT_PATTERN, $fingerprint) === 1 ? $fingerprint : null;
    }

    public static function runtimeSha256OrNull(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $hash = strtolower(trim((string) $value));
        return preg_match(self::RUNTIME_SELF_SHA256_PATTERN, $hash) === 1 ? $hash : null;
    }

    public static function coerceAttestationFlag(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            $number = (int) $value;
            if ($number === 1) {
                return true;
            }
            return $number === 0 ? false : null;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }
        return null;
    }

    /**
     * Pull the attestation out of an explicit argument or, when the caller
     * passes the raw status payload, out of the first status entry carrying one.
     *
     * @param array<int, mixed> $entries
     * @return array{fingerprint:?string,self_sha256:?string,debugger:?bool,instrumented:?bool}|null
     */
    private function extractRuntimeAttestation(mixed $explicit, array $entries): ?array
    {
        $normalized = self::normalizeRuntimeAttestation($explicit);
        if ($normalized !== null) {
            return $normalized;
        }
        foreach ($entries as $entry) {
            if (!is_array($entry) || !array_key_exists('runtime_attestation', $entry)) {
                continue;
            }
            $normalized = self::normalizeRuntimeAttestation($entry['runtime_attestation']);
            if ($normalized !== null) {
                return $normalized;
            }
        }
        return null;
    }

    /** @return list<string> */
    public function runtimeFingerprintAllowlist(): array
    {
        if ($this->runtimeFingerprints !== null) {
            return $this->runtimeFingerprints;
        }
        // Released fingerprints come first so a runtime release is known the
        // moment it is published; the configured list was hand-maintained and
        // went stale after every release, flagging all current clients.
        $configured = (string) ($this->config->get(self::RUNTIME_FINGERPRINTS_KEY, '') ?? '');
        $candidates = array_merge(
            array_map('strval', array_keys(UltraLeaseService::releasedRuntimeHashMap($this->config, $this->releaseManifestPath))),
            explode(',', $configured)
        );
        $list = [];
        foreach ($candidates as $entry) {
            $fingerprint = self::runtimeFingerprintOrNull(trim($entry));
            if ($fingerprint !== null) {
                $list[$fingerprint] = true;
            }
        }
        return $this->runtimeFingerprints = array_keys($list);
    }

    public function isRuntimeFingerprintAllowlisted(mixed $fingerprint): bool
    {
        $fingerprint = self::runtimeFingerprintOrNull($fingerprint);
        return $fingerprint !== null && in_array($fingerprint, $this->runtimeFingerprintAllowlist(), true);
    }

    /**
     * known    -> attested fingerprint is released or in the operator allow-list
     * mismatch -> attested fingerprint is not in a non-empty allow-list
     * unknown  -> no attestation, unusable attestation, or no allow-list yet
     */
    public function attestationState(mixed $fingerprint): string
    {
        $fingerprint = self::runtimeFingerprintOrNull($fingerprint);
        if ($fingerprint === null) {
            return self::ATTESTATION_STATE_UNKNOWN;
        }
        $allowlist = $this->runtimeFingerprintAllowlist();
        if ($allowlist === []) {
            // Empty default: report every attested session as unknown, never as
            // a mismatch and never as a refusal, because no baseline exists.
            return self::ATTESTATION_STATE_UNKNOWN;
        }
        return in_array($fingerprint, $allowlist, true)
            ? self::ATTESTATION_STATE_KNOWN
            : self::ATTESTATION_STATE_MISMATCH;
    }

    /**
     * Report-only admin list of sessions whose attested runtime fingerprint is
     * not a known-good build. Nothing in this path changes or denies a lease.
     *
     * @return array<int, array<string, mixed>>
     */
    public function runtimeAttestationMismatches(int $limit = 100): array
    {
        $limit = max(1, min(self::MAX_MISMATCH_ROWS, $limit));
        $allowlist = $this->runtimeFingerprintAllowlist();
        $rows = $this->db->fetchAll(
            'SELECT cds.client_id, cds.device_id, cds.device_name, cds.platform, cds.last_ip, cds.last_seen_at,
                    cds.ultra_runtime_fingerprint, cds.ultra_runtime_self_sha256,
                    cds.ultra_runtime_debugger, cds.ultra_runtime_instrumented, cds.ultra_runtime_attested_at,
                    c.name AS client_name, u.name AS user_name, u.email AS user_email
             FROM client_device_sessions cds
             INNER JOIN clients c ON c.id = cds.client_id
             LEFT JOIN users u ON u.id = c.owner_user_id
             WHERE cds.ultra_runtime_fingerprint IS NOT NULL
               AND cds.last_seen_at >= :since
             ORDER BY cds.last_seen_at DESC, cds.id DESC
             LIMIT 200',
            ['since' => gmdate('Y-m-d H:i:s', $this->now() - 86400)]
        );
        $result = [];
        foreach ($rows as $row) {
            $fingerprint = self::runtimeFingerprintOrNull($row['ultra_runtime_fingerprint'] ?? null);
            if ($fingerprint === null || in_array($fingerprint, $allowlist, true)) {
                continue;
            }
            $result[] = [
                'client_id' => (int) ($row['client_id'] ?? 0),
                'client_name' => self::text($row['client_name'] ?? '', 191),
                'user_name' => self::text($row['user_name'] ?? '', 191),
                'user_email' => self::text($row['user_email'] ?? '', 191),
                'device_id' => self::text($row['device_id'] ?? '', 191),
                'device_name' => self::text($row['device_name'] ?? '', 191),
                'platform' => self::text($row['platform'] ?? '', 64),
                'client_ip' => self::ip($row['last_ip'] ?? ''),
                'runtime_fingerprint' => $fingerprint,
                'runtime_self_sha256' => (string) (self::runtimeSha256OrNull($row['ultra_runtime_self_sha256'] ?? null) ?? ''),
                'runtime_debugger' => self::coerceAttestationFlag($row['ultra_runtime_debugger'] ?? null),
                'runtime_instrumented' => self::coerceAttestationFlag($row['ultra_runtime_instrumented'] ?? null),
                'runtime_attested_at' => self::text($row['ultra_runtime_attested_at'] ?? '', 40),
                'last_seen_at' => self::text($row['last_seen_at'] ?? '', 40),
                'attestation_state' => $this->attestationState($fingerprint),
                'reason' => $allowlist === []
                    ? 'no_allowlist_configured'
                    : 'fingerprint_not_allowlisted',
            ];
        }
        return array_slice($result, 0, $limit);
    }

    /** @return array<int, array<string, mixed>> */
    public function sessionsForAdmin(array $filters, int $limit, int $offset): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $since = gmdate('Y-m-d H:i:s', $this->now() - 86400);
        $sessions = $this->db->fetchAll(
            'SELECT cds.client_id, cds.device_id, cds.device_name, cds.platform, cds.last_ip,
                    cds.last_seen_at, cds.last_heartbeat_at, cds.ultra_status_json, cds.ultra_status_updated_at,
                    cds.ultra_runtime_fingerprint, cds.ultra_runtime_self_sha256,
                    cds.ultra_runtime_debugger, cds.ultra_runtime_instrumented, cds.ultra_runtime_attested_at,
                    c.name AS client_name, c.group_id, u.name AS user_name, u.email AS user_email
             FROM client_device_sessions cds
             INNER JOIN clients c ON c.id = cds.client_id
             LEFT JOIN users u ON u.id = c.owner_user_id
             WHERE cds.last_seen_at >= :since
               AND cds.ultra_status_json IS NOT NULL
             ORDER BY cds.last_seen_at DESC, cds.id DESC
             LIMIT 200',
            ['since' => $since]
        );
        $clientIds = [];
        foreach ($sessions as $session) {
            $clientId = (int) ($session['client_id'] ?? 0);
            if ($clientId > 0) {
                $clientIds[$clientId] = $clientId;
            }
        }
        $assignments = $this->assignmentsForClients(array_values($clientIds));
        $stateFilter = strtolower(trim((string) ($filters['state'] ?? '')));
        $zoneFilter = strtolower(trim((string) ($filters['destination_zone'] ?? '')));
        $search = strtolower(trim((string) ($filters['search'] ?? '')));
        $forwardSearch = strtolower(trim((string) ($filters['forward'] ?? '')));
        $rows = [];
        foreach ($sessions as $session) {
            $clientId = (int) ($session['client_id'] ?? 0);
            $lastSeen = self::utcTimestamp($session['last_seen_at'] ?? null);
            $state = $this->sessionState($lastSeen);
            if ($stateFilter !== '' && $stateFilter !== strtolower($state)) {
                continue;
            }
            $haystack = strtolower(implode(' ', [
                (string) ($session['user_name'] ?? ''),
                (string) ($session['user_email'] ?? ''),
                (string) ($session['client_name'] ?? ''),
                (string) ($session['device_id'] ?? ''),
                (string) ($session['device_name'] ?? ''),
                (string) ($session['last_ip'] ?? ''),
            ]));
            if ($search !== '' && !str_contains($haystack, $search)) {
                continue;
            }
            $sessionFingerprint = self::runtimeFingerprintOrNull($session['ultra_runtime_fingerprint'] ?? null);
            $sessionSelfSha256 = self::runtimeSha256OrNull($session['ultra_runtime_self_sha256'] ?? null);
            $decoded = is_string($session['ultra_status_json'] ?? null)
                ? json_decode((string) $session['ultra_status_json'], true)
                : [];
            foreach (array_slice(is_array($decoded) ? $decoded : [], 0, self::MAX_STATUSES) as $status) {
                if (!is_array($status)) {
                    continue;
                }
                $forwardId = (int) ($status['forward_id'] ?? 0);
                $assignment = $assignments[$clientId][$forwardId] ?? null;
                if (!is_array($assignment)) {
                    continue;
                }
                $destinationZone = strtolower(trim((string) ($assignment['ultra_destination_zone'] ?? 'unknown')));
                if ($zoneFilter !== '' && $destinationZone !== $zoneFilter) {
                    continue;
                }
                $forwardHaystack = strtolower($forwardId . ' ' . (string) ($assignment['tenant_label'] ?? '') . ' ' . (string) ($assignment['name'] ?? ''));
                if ($forwardSearch !== '' && !str_contains($forwardHaystack, $forwardSearch)) {
                    continue;
                }
                $tcp = is_array($status['latest_tcp'] ?? null) ? $status['latest_tcp'] : [];
                $udp = is_array($status['active_udp'] ?? null) ? $status['active_udp'] : [];
                $tcpRef = self::selectionRef($tcp['selection_ref'] ?? $tcp['entry_ref'] ?? '');
                $udpRef = self::selectionRef($udp['selection_ref'] ?? $udp['entry_ref'] ?? '');
                $rows[] = [
                    'state' => $state,
                    'user_name' => self::text($session['user_name'] ?? '', 191),
                    'user_email' => self::text($session['user_email'] ?? '', 191),
                    'client_id' => $clientId,
                    'client_name' => self::text($session['client_name'] ?? '', 191),
                    'device_id' => self::text($session['device_id'] ?? '', 191),
                    'device_name' => self::text($session['device_name'] ?? '', 191),
                    'client_ip' => self::ip($session['last_ip'] ?? ''),
                    'forward_id' => $forwardId,
                    'forward_label' => self::text($assignment['tenant_label'] ?? $assignment['name'] ?? '', 191),
                    'destination' => self::text($assignment['target_host'] ?? '', 255) . ':' . (int) ($assignment['target_port'] ?? 0),
                    'destination_zone' => $destinationZone,
                    'detected_ingress_zone' => self::zone($status['detected_ingress_zone'] ?? 'unknown'),
                    'latest_tcp_selection_ref' => $tcpRef,
                    'active_udp_selection_ref' => $udpRef,
                    // Compatibility keys stay empty so no provider line/IP is serialized.
                    'latest_tcp_line' => '',
                    'latest_tcp_ip' => '',
                    'active_udp_line' => '',
                    'active_udp_ip' => '',
                    'last_seen_at' => (string) ($session['last_seen_at'] ?? ''),
                    'status_updated_at' => (string) ($session['ultra_status_updated_at'] ?? ''),
                    'error_code' => self::text($status['error_code'] ?? '', 64),
                    // The runtime's own state, so the operator view can tell an
                    // enabled-but-not-running forward apart from a serving one.
                    'runtime_state' => self::text($status['state'] ?? '', 16),
                    // Runtime attestation is session scoped and report-only; the
                    // stored values are re-validated so a legacy row can never
                    // fabricate a "known" state.
                    'runtime_fingerprint' => (string) ($sessionFingerprint ?? ''),
                    'runtime_self_sha256' => (string) ($sessionSelfSha256 ?? ''),
                    'runtime_debugger' => self::coerceAttestationFlag($session['ultra_runtime_debugger'] ?? null),
                    'runtime_instrumented' => self::coerceAttestationFlag($session['ultra_runtime_instrumented'] ?? null),
                    'runtime_attested_at' => self::text($session['ultra_runtime_attested_at'] ?? '', 40),
                    'attestation_state' => $this->attestationState($sessionFingerprint),
                    'attestation_allowlisted' => $this->isRuntimeFingerprintAllowlisted($sessionFingerprint),
                ];
            }
        }
        $liveLeases = $this->forwardsWithLiveLease(array_column($rows, 'forward_id'));
        foreach ($rows as $index => $row) {
            $rows[$index] += self::runtimeHealthVerdict(
                (string) ($row['runtime_state'] ?? ''),
                (string) ($row['error_code'] ?? ''),
                isset($liveLeases[(int) ($row['forward_id'] ?? 0)])
            );
        }
        return array_slice($rows, $offset, $limit);
    }

    /**
     * @return array{online:int,stale:int,offline:int,attestation_known:int,attestation_unknown:int,attestation_mismatch:int}
     */
    public function statusCounts(): array
    {
        $now = $this->now();
        $rows = $this->db->fetchAll(
            'SELECT last_seen_at, ultra_runtime_fingerprint FROM client_device_sessions
             WHERE last_seen_at >= :since AND ultra_status_json IS NOT NULL
             ORDER BY last_seen_at DESC LIMIT 1000',
            ['since' => gmdate('Y-m-d H:i:s', $now - 86400)]
        );
        $counts = [
            'online' => 0,
            'stale' => 0,
            'offline' => 0,
            'attestation_known' => 0,
            'attestation_unknown' => 0,
            'attestation_mismatch' => 0,
        ];
        foreach ($rows as $row) {
            $state = strtolower($this->sessionState(self::utcTimestamp($row['last_seen_at'] ?? null)));
            if (isset($counts[$state])) {
                $counts[$state]++;
            }
            // Absent or unusable attestation counts as unknown, never as a failure.
            $counts['attestation_' . $this->attestationState($row['ultra_runtime_fingerprint'] ?? null)]++;
        }
        return $counts;
    }

    /** @param array<int, int> $clientIds @return array<int, array<int, array<string, mixed>>> */
    private function assignmentsForClients(array $clientIds): array
    {
        if ($clientIds === []) {
            return [];
        }
        $clients = $this->db->fetchAll(
            'SELECT id, group_id FROM clients WHERE id IN (' . implode(',', array_map('intval', $clientIds)) . ')'
        );
        $rows = $this->db->fetchAll(
            'SELECT f.id, f.line_client_id, f.line_client_group_id, f.line_client_group_ids_json,
                    f.target_host, f.target_port, fc.name, fc.tenant_label, fc.ultra_destination_zone
             FROM forwards f
             INNER JOIN services s ON s.id = f.service_id AND s.status = :active_status
             INNER JOIN forward_combinations fc ON fc.id = f.forward_combination_id AND fc.backend_mode = :backend_mode
             WHERE f.enabled = 1
             ORDER BY f.id ASC',
            ['active_status' => 'active', 'backend_mode' => UltraMode::BACKEND_MODE]
        );
        $result = [];
        foreach ($clients as $client) {
            $clientId = (int) ($client['id'] ?? 0);
            $groupId = (int) ($client['group_id'] ?? 0);
            foreach ($rows as $row) {
                $groups = is_string($row['line_client_group_ids_json'] ?? null)
                    ? json_decode((string) $row['line_client_group_ids_json'], true)
                    : [];
                $assigned = (int) ($row['line_client_id'] ?? 0) === $clientId
                    || ($groupId > 0 && (int) ($row['line_client_group_id'] ?? 0) === $groupId)
                    || ($groupId > 0 && is_array($groups) && in_array($groupId, array_map('intval', $groups), true));
                if ($assigned) {
                    $result[$clientId][(int) $row['id']] = $row;
                }
            }
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $status
     * @return array<string, mixed>|null
     */
    private function normalizeStatus(array $status, int $forwardId): ?array
    {
        $state = strtolower(trim((string) ($status['state'] ?? 'ready')));
        if (!in_array($state, ['ready', 'degraded', 'error', 'stopped'], true)) {
            $state = 'error';
        }
        return [
            'forward_id' => $forwardId,
            'state' => $state,
            'udp_transport' => in_array($status['udp_transport'] ?? '', ['disabled', 'native_udp', 'external_uot'], true)
                ? $status['udp_transport'] : 'unknown',
            'detected_ingress_zone' => self::zone($status['detected_ingress_zone'] ?? 'unknown'),
            'latest_tcp' => $this->normalizeSelection($status['latest_tcp'] ?? null),
            'active_udp' => $this->normalizeSelection($status['active_udp'] ?? null),
            'ha_failures' => max(0, min(4294967295, (int) ($status['ha_failures'] ?? 0))),
            'udp_queue_drops' => max(0, min(4294967295, (int) ($status['udp_queue_drops'] ?? 0))),
            'udp_setup_failures' => max(0, min(4294967295, (int) ($status['udp_setup_failures'] ?? 0))),
            'bytes_in' => max(0, min(1099511627776, (int) ($status['bytes_in'] ?? 0))),
            'bytes_out' => max(0, min(1099511627776, (int) ($status['bytes_out'] ?? 0))),
            'ha_events' => $this->normalizeHAEvents($status['ha_events'] ?? null),
            'updated_at' => self::text($status['updated_at'] ?? '', 40),
            'error_code' => self::text($status['error_code'] ?? '', 64),
        ];
    }

    /** @return array<int, array<string, string>> */
    private function normalizeHAEvents(mixed $events): array
    {
        if (!is_array($events)) {
            return [];
        }
        $result = [];
        foreach (array_slice(array_values($events), -8) as $event) {
            if (!is_array($event)) {
                continue;
            }
            $protocol = strtolower(trim((string) ($event['protocol'] ?? '')));
            $reason = strtolower(trim((string) ($event['reason'] ?? '')));
            if (!in_array($protocol, ['tcp', 'udp'], true) || $reason === '') {
                continue;
            }
            $normalized = [
                'protocol' => self::text($protocol, 16),
                'reason' => self::text($reason, 64),
                'generation' => self::text($event['generation'] ?? '', 64),
                'at' => self::text($event['at'] ?? '', 40),
            ];
            foreach (['candidate_key_hash', 'lease_hash'] as $key) {
                $hash = is_string($event[$key] ?? null) ? strtolower(trim($event[$key])) : '';
                if (preg_match('/^[a-f0-9]{64}$/', $hash) === 1) {
                    $normalized[$key] = $hash;
                }
            }
            foreach (['upstream_bytes', 'remote_read_bytes'] as $key) {
                if (is_int($event[$key] ?? null)) {
                    $normalized[$key] = max(0, min(107374182400, $event[$key]));
                }
            }
            foreach (['remote_stopped_first', 'local_path_avoided'] as $key) {
                if (is_bool($event[$key] ?? null)) {
                    $normalized[$key] = $event[$key];
                }
            }
            $readReason = $event['remote_read_reason'] ?? null;
            if (is_string($readReason) && preg_match('/^[a-z0-9_]{1,64}$/', $readReason) === 1) {
                $normalized['remote_read_reason'] = $readReason;
            }
            $result[] = $normalized;
        }
        return $result;
    }

    /** @return array<string, mixed>|null */
    private function normalizeSelection(mixed $selection): ?array
    {
        if (!is_array($selection)) {
            return null;
        }
        $reference = self::selectionRef($selection['selection_ref'] ?? $selection['entry_ref'] ?? '');
        if ($reference === '') {
            return null;
        }
        $normalized = [
            'selection_ref' => $reference,
            'rtt_ms' => max(0, min(60000, (int) ($selection['rtt_ms'] ?? 0))),
            'selected_at' => self::text($selection['selected_at'] ?? '', 40),
        ];
        $candidateHash = strtolower(trim((string) ($selection['candidate_key_hash'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/', $candidateHash) === 1) {
            $normalized['candidate_key_hash'] = $candidateHash;
        }
        foreach ([
            'p95_rtt_ms' => 60000,
            'jitter_ms' => 60000,
            'loss_percent' => 100,
            'probe_sent' => 4096,
            'probe_received' => 4096,
        ] as $key => $maximum) {
            if (array_key_exists($key, $selection)) {
                $normalized[$key] = max(0, min($maximum, (int) $selection[$key]));
            }
        }
        return $normalized;
    }

    private static function selectionRef(mixed $value): string
    {
        $reference = trim((string) $value);
        return preg_match('/^[A-Za-z0-9_-]{16,128}$/', $reference) === 1 ? $reference : '';
    }

    /**
     * Panel timestamps are written in UTC (gmdate), so they must be parsed as UTC.
     * strtotime() would read them in date.timezone instead, which on a panel with
     * a non-UTC timezone (the production boxes run PRC) shifts every session by
     * the UTC offset and reports healthy sessions as stale hours early.
     */
    private static function utcTimestamp(mixed $value): int
    {
        $text = trim((string) $value);
        if ($text === '') {
            return 0;
        }
        try {
            return (new \DateTimeImmutable($text, new \DateTimeZone('UTC')))->getTimestamp();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function sessionState(int $lastSeen): string
    {
        $age = max(0, $this->now() - $lastSeen);
        return $age < 180 ? 'Online' : ($age <= 900 ? 'Stale' : 'Offline');
    }

    private static function text(mixed $value, int $limit): string
    {
        $text = trim((string) $value);
        return function_exists('mb_substr') ? mb_substr($text, 0, $limit, 'UTF-8') : substr($text, 0, $limit);
    }

    private static function ip(mixed $value): string
    {
        $ip = trim((string) $value);
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '';
    }

    private static function zone(mixed $value): string
    {
        $zone = strtolower(trim((string) $value));
        return preg_match('/^[a-z0-9-]{1,32}$/', $zone) === 1 ? $zone : 'unknown';
    }

    /**
     * Which of these forwards currently hold an unexpired, unrevoked Ultra
     * lease. An enabled Ultra forward with none of these has been handed nothing
     * to run, so it cannot be serving.
     *
     * @param array<int, mixed> $forwardIds
     * @return array<int, string> forward_id => lease expiry
     */
    public function forwardsWithLiveLease(array $forwardIds): array
    {
        $ids = [];
        foreach ($forwardIds as $forwardId) {
            $forwardId = (int) $forwardId;
            if ($forwardId > 0) {
                $ids[$forwardId] = $forwardId;
            }
        }
        if ($ids === []) {
            return [];
        }
        try {
            $rows = $this->db->fetchAll(
                'SELECT forward_id, MAX(expires_at) AS live_until
                 FROM ultra_client_leases
                 WHERE forward_id IN (' . implode(', ', array_map('intval', array_values($ids))) . ')
                   AND revoked_at IS NULL
                   AND expires_at > :now
                 GROUP BY forward_id',
                ['now' => gmdate('Y-m-d H:i:s', $this->now())]
            );
        } catch (\Throwable) {
            // Health is observational: a lease lookup failure must not break a page.
            return [];
        }
        $live = [];
        foreach ($rows as $row) {
            $forwardId = (int) ($row['forward_id'] ?? 0);
            if ($forwardId > 0) {
                $live[$forwardId] = self::text($row['live_until'] ?? '', 40);
            }
        }
        return $live;
    }

    /**
     * Panel-side health verdict for an ENABLED Ultra forward.
     *
     * Ultra is provisioned by a short-lived lease, so "enabled" is never on its
     * own evidence of health. A forward with no live lease has no runtime at
     * all, and a runtime that reports degraded/error - notably awaiting_policy,
     * the state a target change used to wedge a forward into - is a health
     * failure. The panel used to render both as simply enabled.
     *
     * @return array{unhealthy: bool, health_reason: string}
     */
    public static function runtimeHealthVerdict(mixed $reportedState, string $errorCode, bool $hasLiveLease): array
    {
        $code = strtolower(trim($errorCode));
        if ($code !== '') {
            return ['unhealthy' => true, 'health_reason' => self::text($code, 64)];
        }
        $state = strtolower(trim((string) $reportedState));
        if (in_array($state, ['degraded', 'error', 'stopped'], true)) {
            return ['unhealthy' => true, 'health_reason' => 'runtime_' . $state];
        }
        if (!$hasLiveLease) {
            // Enabled, but the panel holds no unexpired lease for it: the client
            // agent has nothing to deliver, so the forward cannot be serving.
            return ['unhealthy' => true, 'health_reason' => 'no_live_lease'];
        }
        if ($state !== 'ready') {
            return ['unhealthy' => true, 'health_reason' => 'runtime_unreported'];
        }
        return ['unhealthy' => false, 'health_reason' => 'ready'];
    }

    /**
     * Runtime health for a set of forwards, derived from what the panel already
     * knows: the ingested per-forward runtime status (client-reported state and
     * error_code) and the Ultra lease row itself.
     *
     * @param array<int, mixed> $forwardIds
     * @return array<int, array<string, mixed>> forward_id => health
     */
    public function runtimeHealthForForwards(array $forwardIds): array
    {
        $ids = [];
        foreach ($forwardIds as $forwardId) {
            $forwardId = (int) $forwardId;
            if ($forwardId > 0) {
                $ids[$forwardId] = $forwardId;
            }
        }
        if ($ids === []) {
            return [];
        }
        $health = [];
        foreach ($ids as $forwardId) {
            $health[$forwardId] = [
                'forward_id' => $forwardId,
                'reported_state' => '',
                'error_code' => '',
                'reported_at' => '',
                'has_live_lease' => false,
                'lease_expires_at' => '',
            ];
        }

        foreach ($this->forwardsWithLiveLease(array_values($ids)) as $forwardId => $liveUntil) {
            if (isset($health[$forwardId])) {
                $health[$forwardId]['has_live_lease'] = true;
                $health[$forwardId]['lease_expires_at'] = self::text($liveUntil, 40);
            }
        }

        try {
            $sessions = $this->db->fetchAll(
                'SELECT last_seen_at, ultra_status_json, ultra_status_updated_at
                 FROM client_device_sessions
                 WHERE last_seen_at >= :since AND ultra_status_json IS NOT NULL
                 ORDER BY last_seen_at DESC, id DESC
                 LIMIT 200',
                ['since' => gmdate('Y-m-d H:i:s', $this->now() - 86400)]
            );
            foreach ($sessions as $session) {
                $decoded = is_string($session['ultra_status_json'] ?? null)
                    ? json_decode((string) $session['ultra_status_json'], true)
                    : [];
                foreach (array_slice(is_array($decoded) ? $decoded : [], 0, self::MAX_STATUSES) as $status) {
                    if (!is_array($status)) {
                        continue;
                    }
                    $forwardId = (int) ($status['forward_id'] ?? 0);
                    // Sessions arrive newest first, so the first report wins.
                    if (!isset($health[$forwardId]) || $health[$forwardId]['reported_state'] !== '') {
                        continue;
                    }
                    $health[$forwardId]['reported_state'] = self::text(strtolower(trim((string) ($status['state'] ?? ''))), 16);
                    $health[$forwardId]['error_code'] = self::text($status['error_code'] ?? '', 64);
                    $health[$forwardId]['reported_at'] = self::text(
                        (string) ($session['ultra_status_updated_at'] ?? $session['last_seen_at'] ?? ''),
                        40
                    );
                }
            }
        } catch (\Throwable) {
            // Health is observational: a status lookup failure must not break a page.
        }

        foreach ($health as $forwardId => $entry) {
            $health[$forwardId] += self::runtimeHealthVerdict(
                $entry['reported_state'],
                (string) $entry['error_code'],
                (bool) $entry['has_live_lease']
            );
        }
        return $health;
    }

    private function now(): int
    {
        return (int) ($this->clock)();
    }
}
