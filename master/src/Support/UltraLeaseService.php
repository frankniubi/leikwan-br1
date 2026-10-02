<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use DateTimeImmutable;
use DateTimeZone;
use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
use Throwable;

final class UltraLeaseService
{
    /**
     * Opt-in abuse limits. All three default to 0 = unlimited, so an
     * unconfigured panel keeps issuing leases exactly as it does today.
     */
    public const MAX_DEVICES_PER_CLIENT_KEY = 'ULTRA_MAX_DEVICES_PER_CLIENT';
    public const MAX_LIVE_LEASES_PER_DEVICE_KEY = 'ULTRA_MAX_LIVE_LEASES_PER_DEVICE';
    public const ISSUE_RATE_PER_MINUTE_KEY = 'ULTRA_LEASE_ISSUE_RATE_PER_MINUTE';

    /**
     * Distinct refusal codes. They never reuse a generic lease error so an
     * operator can tell an abuse limit apart from a scope/policy failure.
     *
     * lease_issue_rate_limited   ULTRA_LEASE_ISSUE_RATE_PER_MINUTE reached for
     *                            this client+device inside the current minute.
     * lease_device_live_limit    the device already holds
     *                            ULTRA_MAX_LIVE_LEASES_PER_DEVICE unexpired,
     *                            non-revoked leases.
     * lease_client_device_limit  the client already holds leases on
     *                            ULTRA_MAX_DEVICES_PER_CLIENT other devices.
     */
    public const ERROR_RATE_LIMITED = 'lease_issue_rate_limited';
    public const ERROR_DEVICE_LIVE_LIMIT = 'lease_device_live_limit';
    public const ERROR_CLIENT_DEVICE_LIMIT = 'lease_client_device_limit';

    public const RATE_WINDOW_SECONDS = 60;
    // A client can present the same nonce twice without meaning any harm: a
    // config poll and a targeted refresh race each other, or the first request
    // times out after the panel already committed it. Those retries mint a
    // fresh lease instead of failing, bounded per nonce, inside this window.
    public const REPLAY_GRACE_SECONDS = 300;
    public const MAX_REPLAY_ATTEMPTS = 5;

    /**
     * Runtime attestation enforcement. "report" (the default) never denies;
     * "enforce" denies a lease when the device reported a RELEASED build
     * fingerprint together with a binary hash that does not match the released
     * artifact for that fingerprint - i.e. the client was repackaged while still
     * claiming a released build. An unknown fingerprint (older builds, local
     * "development" builds) is reported but never denied, so switching this on
     * cannot lock out a client we have never published.
     */
    public const ATTESTATION_ENFORCEMENT_KEY = 'ULTRA_ATTESTATION_ENFORCEMENT';
    /**
     * Platform prefixes the check applies to. Defaults to "linux" only: the
     * Linux artifact served by the panel IS the shipped binary, while the macOS
     * bundle ships a re-signed runtime whose hash legitimately differs from the
     * published artifact, and the Windows runtime is embedded in the installer.
     */
    public const ATTESTATION_ENFORCE_PLATFORMS_KEY = 'ULTRA_ATTESTATION_ENFORCE_PLATFORMS';
    /**
     * What "enforce" does with a device on an enforced platform that cannot be
     * judged: no device session, no attestation, or a fingerprint the panel
     * never published. "allow" (the default) lets it through as before; "deny"
     * refuses it with ERROR_RUNTIME_UNATTESTED. Only switch to "deny" once the
     * admin attestation view shows every live client reporting a released
     * fingerprint, otherwise older builds lose their leases.
     */
    public const ATTESTATION_UNATTESTED_KEY = 'ULTRA_ATTESTATION_UNATTESTED';
    public const ERROR_RUNTIME_UNATTESTED = 'lease_runtime_unattested';

    /** @var callable(array<string,mixed>,int,string):array<string,mixed> */
    private $candidateResolver;

    /** @var callable(int,string,int):array<string,mixed> */
    private $policyResolver;

    /** @var callable():int */
    private $clock;

    /** @var array<string, true> */
    private array $memoryNonces = [];

    /** @var array<string, true> */
    private array $memoryGenerations = [];

    /** @var array<string, list<int>> "client:device" => live lease expiry stamps (DB-less mode) */
    private array $memoryLiveLeases = [];

    /** @var array<string, list<int>> "client:device" => issue stamps (DB-less mode) */
    private array $memoryIssues = [];

    /** @var array<string, list<string>>|null fingerprint => released binary hashes */
    private ?array $releasedRuntimeHashes = null;

    public function __construct(
        private Config $config,
        private ?Database $db = null,
        ?UltraMaterialService $materials = null,
        ?callable $candidateResolver = null,
        ?callable $clock = null,
        ?callable $policyResolver = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
        $materials ??= new UltraMaterialService($config, $db, $db !== null ? new AppSettings($db) : null, $this->clock);
        $this->candidateResolver = $candidateResolver ?? static function (array $request, int $clientId, string $deviceId) use ($materials): array {
            return $materials->serverCandidateForClientToken(
                trim((string) ($request['session_ticket'] ?? '')),
                $clientId,
                $deviceId,
                (int) ($request['forward_id'] ?? 0),
                trim((string) ($request['policy_generation'] ?? '')),
                (int) ($request['candidate_offset'] ?? 0),
                trim((string) ($request['purpose'] ?? '')),
            );
        };
        $this->policyResolver = $policyResolver ?? function (int $clientId, string $deviceId, int $forwardId): array {
            return $this->loadForwardPolicy($clientId, $forwardId);
        };
    }

    /**
     * @param array<string,mixed> $request
     * @param array<string,mixed>|null $authoritativeForward
     * @return array<string,mixed>
     */
    public function issueLease(
        array $request,
        int $clientId,
        string $deviceId,
        ?array $authoritativeForward = null,
    ): array {
        $normalized = $this->normalizeRequest($request);
        if ($normalized === null || $clientId <= 0 || trim($deviceId) === '') {
            return ['ok' => false, 'error' => 'lease_scope_invalid'];
        }
        $isNative = $normalized['purpose'] === UltraMode::NATIVE_OUTBOUND_PURPOSE;

        $forwardId = $normalized['forward_id'];
        $policy = $authoritativeForward ?? ($this->policyResolver)($clientId, trim($deviceId), $forwardId);
        if (!is_array($policy) || (int) ($policy['forward_id'] ?? 0) !== $forwardId) {
            return ['ok' => false, 'error' => 'lease_scope_invalid'];
        }
        if (isset($policy['device_hash'])
            && !hash_equals((string) $policy['device_hash'], hash('sha256', trim($deviceId)))) {
            return ['ok' => false, 'error' => 'lease_scope_invalid'];
        }
        if (strtolower(trim((string) ($policy['service_state'] ?? 'active'))) !== 'active') {
            return ['ok' => false, 'error' => 'forward_inactive'];
        }

        $policyGeneration = trim((string) ($policy['policy_generation'] ?? ''));
        if ($policyGeneration === '' || !hash_equals($policyGeneration, $normalized['policy_generation'])) {
            return ['ok' => false, 'error' => 'policy_generation_invalid'];
        }
        if (isset($this->memoryGenerations[$forwardId . ':' . strtolower($policyGeneration)])) {
            return ['ok' => false, 'error' => 'policy_generation_invalid'];
        }
        if ($this->isGenerationRevoked($forwardId, $policyGeneration)) {
            return ['ok' => false, 'error' => 'policy_generation_invalid'];
        }
        $policyTransport = strtolower(trim((string) ($policy['transport'] ?? $normalized['transport'])));
        if ($policyTransport !== $normalized['transport'] || ($isNative && $policyTransport !== 'tcp+udp')) {
            return ['ok' => false, 'error' => 'policy_scope_invalid'];
        }
        $direction = '';
        $routeScope = '';
        $routePolicy = UltraMode::NATIVE_ROUTE_POLICY_RESTRICTED;
        if ($isNative) {
            $direction = trim((string) ($policy['direction'] ?? ''));
            $routeScope = UltraMode::normalizeNativeRouteScope($normalized['route_scope'], $direction) ?? '';
            $policyRouteScope = UltraMode::normalizeNativeRouteScope((string) ($policy['route_scope'] ?? ''), $direction) ?? '';
            $routePolicy = UltraMode::normalizeNativeRoutePolicy($normalized['route_policy'])
                ?? UltraMode::NATIVE_ROUTE_POLICY_RESTRICTED;
            if ($routePolicy === UltraMode::NATIVE_ROUTE_POLICY_UNRESTRICTED
                && empty($policy['native_unrestricted_allowed'])) {
                return ['ok' => false, 'error' => 'native_route_policy_not_allowed'];
            }
            $v1Digest = UltraMode::nativeScopeDigest($forwardId, $direction, $policyTransport, $routeScope);
            $v2Digest = UltraMode::nativeScopeDigestV2(
                $forwardId,
                $direction,
                $policyTransport,
                $routeScope,
                $routePolicy
            );
            $digestMatches = hash_equals($v2Digest, $normalized['target_digest'])
                || ($routePolicy === UltraMode::NATIVE_ROUTE_POLICY_RESTRICTED
                    && hash_equals($v1Digest, $normalized['target_digest']));
            if ($routeScope === '' || $policyRouteScope === '' || $routeScope !== $policyRouteScope
                || !$digestMatches) {
                return ['ok' => false, 'error' => 'policy_scope_invalid'];
            }
        } else {
            $target = trim((string) ($policy['target'] ?? ''));
            if ($target === '' || !hash_equals(hash('sha256', $target), $normalized['target_digest'])) {
                return ['ok' => false, 'error' => 'policy_scope_invalid'];
            }
        }
        $expiresAt = $this->parseTimestamp((string) ($policy['expires_at'] ?? ''));
        if ($expiresAt === null || $expiresAt <= $this->now()) {
            return ['ok' => false, 'error' => 'policy_expired'];
        }
        $quotaBytes = max(0, min(UltraMode::MAX_QUOTA_LEASE_BYTES, (int) ($policy['quota_remaining_bytes'] ?? 0)));
        if ($quotaBytes <= 0) {
            return ['ok' => false, 'error' => 'quota_exhausted'];
        }

        $deviceHash = hash('sha256', trim($deviceId));
        // Abuse limits run after every scope/policy check and before the single
        // use nonce is consumed, so a refusal never burns a nonce. With the
        // default caps (0) this is a no-op.
        $abuseRefusal = $this->abuseLimitRefusal($clientId, $deviceHash, $forwardId);
        if ($abuseRefusal !== null) {
            $this->recordLeaseRefusal($clientId, $deviceHash, $forwardId, $abuseRefusal);
            return ['ok' => false, 'error' => $abuseRefusal];
        }
        // Repackaged-build check, also before the nonce is consumed. It only ever
        // fires for a device that claims a published fingerprint with a hash the
        // published artifact does not have.
        $attestationRefusal = $this->attestationRefusal($clientId, $deviceHash);
        if ($attestationRefusal !== null) {
            $this->recordLeaseRefusal($clientId, $deviceHash, $forwardId, $attestationRefusal);
            return ['ok' => false, 'error' => $attestationRefusal];
        }

        $candidate = ($this->candidateResolver)($normalized, $clientId, trim($deviceId));
        if (!is_array($candidate) || !$this->candidateIsValid($candidate)
            || ($isNative && !UltraMode::isNativeCandidateApproved($candidate))) {
            return ['ok' => false, 'error' => 'no_approved_candidate'];
        }
        if (isset($candidate['policy_generation'])
            && !hash_equals($policyGeneration, (string) $candidate['policy_generation'])) {
            return ['ok' => false, 'error' => 'policy_generation_invalid'];
        }

        $candidateKeyHash = strtolower(trim((string) ($candidate['candidate_key_hash'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/', $candidateKeyHash) !== 1) {
            $candidateKeyHash = '';
        }

        $nonceHash = hash('sha256', $normalized['nonce']);
        if (!$this->consumeNonce($clientId, $deviceHash, $forwardId, $nonceHash, $policyGeneration, $quotaBytes, $expiresAt, $candidateKeyHash)) {
            // The nonce is already scoped to this client, device and forward, so
            // nobody else can be replaying it - this is the same client asking
            // again. Mint a lease for the retry under a derived nonce, which
            // leaves the previous row (and the lease a running runtime still
            // reports against) untouched. Only a revoked or stale nonce is a
            // real replay and still gets refused.
            $nonceHash = $this->consumeReplayNonce(
                $clientId,
                $deviceHash,
                $forwardId,
                $nonceHash,
                $policyGeneration,
                $quotaBytes,
                $expiresAt,
                $candidateKeyHash
            );
            if ($nonceHash === '') {
                return ['ok' => false, 'error' => 'lease_replayed'];
            }
        }

        $leaseId = 'ul2_' . $this->base64UrlEncode(random_bytes(24));
        $leaseExpiresAt = min($expiresAt, $this->now() + $this->leaseTtlSeconds($policy));
        $this->markConsumed($clientId, $deviceHash, $forwardId, $nonceHash, hash('sha256', $leaseId), $leaseExpiresAt, $candidateKeyHash);
        $this->rememberIssuedLease($clientId, $deviceHash, $leaseExpiresAt);
        $lease = $this->scrubLease([
            'protocol' => $isNative ? UltraMode::NATIVE_SOCKS5_PROTOCOL : UltraMode::POLICY_PROTOCOL,
            'purpose' => $normalized['purpose'],
            'session_ticket' => $normalized['session_ticket'],
            'forward_id' => $forwardId,
            'lease_id' => $leaseId,
            'policy_generation' => $policyGeneration,
            'expires_at' => $this->formatTimestamp($leaseExpiresAt),
            'policy_expires_at' => $this->formatTimestamp($expiresAt),
            'policy_renew_after' => (string) ($policy['renew_after'] ?? ''),
            'transport' => $policyTransport,
            'rate_limit_mbps' => max(0, min(100000, (int) ($policy['rate_limit_mbps'] ?? 0))),
            'quota_remaining_bytes' => $quotaBytes,
            'selection_ref' => (string) ($candidate['selection_ref'] ?? ''),
            'candidate_key_hash' => $candidateKeyHash,
            'selection_tier' => $this->selectionTier($candidate['selection_tier'] ?? 1),
            'target_digest' => $normalized['target_digest'],
            'direction' => $direction,
            'route_scope' => $routeScope,
            'route_policy' => $routePolicy,
            'nonce_hash' => $nonceHash,
            'endpoint' => [
                'ip' => (string) $candidate['ip'],
                'port' => (int) $candidate['port'],
            ],
            'credential' => [
                'user' => (string) $candidate['user'],
                'password' => (string) $candidate['credential'],
            ],
            'direct_ip_cidrs' => $this->directIpCidrsForCandidate($candidate),
        ]);
        return ['ok' => true, 'lease' => $lease];
    }

    /**
     * Lease lifetime ceiling. The short path (no offline validity on the
     * policy) keeps MAX_POLICY_TTL_SECONDS exactly as before. A policy built for
     * a client that announced UltraMode::OFFLINE_VALIDITY_HEADER carries the
     * effective ceiling in 'offline_validity_seconds' (set server-side by the
     * lease controller, never taken from the request body); the lease is then
     * min(policy expiry, now + that ceiling).
     *
     * @param array<string,mixed> $policy
     */
    private function leaseTtlSeconds(array $policy): int
    {
        $validity = (int) ($policy['offline_validity_seconds'] ?? 0);
        if ($validity >= UltraMode::MIN_OFFLINE_VALIDITY_SECONDS && $validity <= UltraMode::MAX_OFFLINE_VALIDITY_SECONDS) {
            return $validity;
        }
        return UltraMode::MAX_POLICY_TTL_SECONDS;
    }

    /** @return array<string,mixed> */
    public function revokeGeneration(int $forwardId, string $generation): array
    {
        $generation = trim($generation);
        if ($forwardId <= 0 || !preg_match('/^[a-f0-9]{64}$/i', $generation)) {
            return ['ok' => false, 'error' => 'invalid_generation'];
        }
        $count = 0;
        if ($this->db !== null) {
            $count = $this->db->execute(
                'UPDATE ultra_client_leases
                 SET revoked_at = :revoked_at, updated_at = :updated_at
                 WHERE forward_id = :forward_id AND policy_generation = :policy_generation AND revoked_at IS NULL',
                [
                    'forward_id' => $forwardId,
                    'policy_generation' => strtolower($generation),
                    'revoked_at' => gmdate('Y-m-d H:i:s', $this->now()),
                    'updated_at' => gmdate('Y-m-d H:i:s', $this->now()),
                ]
            );
        }
        $this->memoryGenerations[$forwardId . ':' . strtolower($generation)] = true;
        return ['ok' => true, 'revoked' => $count];
    }

    /**
     * Retire every lease for one forward.
     *
     * Used when a forward's lease scope changes (target, transport or listen
     * port): the policy_generation covers all three, so every lease already
     * issued for the forward describes a scope the panel no longer honours.
     * Revoking them is a fail-closed bookkeeping step - issueLease() already
     * refuses the old generation, and this makes sure a stale lease can never be
     * replayed back into a valid one.
     *
     * @return int number of leases newly revoked
     */
    public function revokeForwardLeases(int $forwardId): int
    {
        if ($forwardId <= 0 || $this->db === null) {
            return 0;
        }
        $now = gmdate('Y-m-d H:i:s', $this->now());
        return $this->db->execute(
            'UPDATE ultra_client_leases
             SET revoked_at = :revoked_at, updated_at = :updated_at
             WHERE forward_id = :forward_id AND revoked_at IS NULL',
            [
                'forward_id' => $forwardId,
                'revoked_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    public function revokeDevice(int $clientId, string $deviceId): int
    {
        $deviceId = trim($deviceId);
        if ($this->db === null || $clientId <= 0 || $deviceId === '') {
            return 0;
        }
        $now = gmdate('Y-m-d H:i:s', $this->now());
        return $this->db->execute(
            'UPDATE ultra_client_leases
             SET revoked_at = :revoked_at, updated_at = :updated_at
             WHERE client_id = :client_id AND device_hash = :device_hash AND revoked_at IS NULL',
            [
                'client_id' => $clientId,
                'device_hash' => hash('sha256', $deviceId),
                'revoked_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    /**
     * Repackaged-build check. Returns ERROR_RUNTIME_UNATTESTED only when the
     * device's last session claims a fingerprint the panel published AND its
     * reported binary hash matches none of that release's published hashes.
     *
     * A platform outside the enforced list and an unreadable manifest always
     * fail open. No session row, no attestation or an unknown fingerprint fail
     * open unless ULTRA_ATTESTATION_UNATTESTED=deny.
     */
    private function attestationRefusal(int $clientId, string $deviceHash): ?string
    {
        if ($this->db === null || $clientId <= 0 || $deviceHash === '') {
            return null;
        }
        if (strtolower(trim((string) $this->config->get(self::ATTESTATION_ENFORCEMENT_KEY, 'report'))) !== 'enforce') {
            return null;
        }
        $expected = $this->releasedRuntimeHashes();
        if ($expected === []) {
            return null;
        }
        try {
            $session = $this->db->fetchOne(
                'SELECT platform, ultra_runtime_fingerprint, ultra_runtime_self_sha256
                   FROM client_device_sessions
                  WHERE client_id = :client_id AND SHA2(device_id, 256) = :device_hash
                  LIMIT 1',
                ['client_id' => $clientId, 'device_hash' => $deviceHash]
            );
        } catch (Throwable $throwable) {
            error_log('ultra_attestation_lookup_failed ' . $throwable->getMessage());
            return null;
        }
        $denyUnattested = strtolower(trim((string) $this->config->get(self::ATTESTATION_UNATTESTED_KEY, 'allow'))) === 'deny';
        if (!is_array($session)) {
            return $denyUnattested ? $this->unattestedRefusal($clientId, $deviceHash, 'no-session') : null;
        }
        $platform = trim((string) ($session['platform'] ?? ''));
        if ($platform === '' && $denyUnattested) {
            // A caller that does not even say what it is cannot opt out of
            // the check by leaving the platform header off.
            return $this->unattestedRefusal($clientId, $deviceHash, 'no-platform');
        }
        if (!$this->platformIsEnforced($platform)) {
            return null;
        }
        $fingerprint = strtolower(trim((string) ($session['ultra_runtime_fingerprint'] ?? '')));
        $reported = strtolower(trim((string) ($session['ultra_runtime_self_sha256'] ?? '')));
        if ($fingerprint === '' || $reported === '' || !isset($expected[$fingerprint])) {
            if (!$denyUnattested) {
                return null;
            }
            return $this->unattestedRefusal(
                $clientId,
                $deviceHash,
                $fingerprint === '' || $reported === '' ? 'no-attestation' : 'unknown-fingerprint'
            );
        }
        foreach ($expected[$fingerprint] as $allowed) {
            if (hash_equals($allowed, $reported)) {
                return null;
            }
        }
        error_log(sprintf(
            'ultra_attestation_mismatch client=%d device=%s fingerprint=%s reported=%s',
            $clientId,
            substr($deviceHash, 0, 16),
            $fingerprint,
            substr($reported, 0, 16)
        ));
        return self::ERROR_RUNTIME_UNATTESTED;
    }

    private function unattestedRefusal(int $clientId, string $deviceHash, string $reason): string
    {
        error_log(sprintf(
            'ultra_attestation_unattested client=%d device=%s reason=%s',
            $clientId,
            substr($deviceHash, 0, 16),
            $reason
        ));
        return self::ERROR_RUNTIME_UNATTESTED;
    }

    /** Platform prefixes the attestation check applies to ("linux" by default). */
    private function platformIsEnforced(string $platform): bool
    {
        $configured = strtolower(trim((string) $this->config->get(self::ATTESTATION_ENFORCE_PLATFORMS_KEY, 'linux')));
        if ($configured === '') {
            return false;
        }
        $platform = strtolower(trim($platform));
        if ($platform === '') {
            return false;
        }
        foreach (explode(',', $configured) as $prefix) {
            $prefix = trim($prefix);
            if ($prefix !== '' && str_starts_with($platform, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * fingerprint => every binary hash the panel has published for it, straight
     * from artifacts/versions.json (written by the release publisher). Reading
     * the live manifest keeps this in step with releases instead of duplicating
     * hashes in configuration.
     *
     * @return array<string, list<string>>
     */
    private function releasedRuntimeHashes(): array
    {
        if ($this->releasedRuntimeHashes !== null) {
            return $this->releasedRuntimeHashes;
        }
        $this->releasedRuntimeHashes = [];
        $path = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'artifacts' . DIRECTORY_SEPARATOR . 'versions.json';
        if (!is_file($path)) {
            return $this->releasedRuntimeHashes;
        }
        $decoded = json_decode((string) @file_get_contents($path), true);
        if (!is_array($decoded)) {
            return $this->releasedRuntimeHashes;
        }
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $attestation = $entry['attestation'] ?? null;
            if (!is_array($attestation)) {
                continue;
            }
            $fingerprint = strtolower(trim((string) ($attestation['fingerprint'] ?? '')));
            $hash = strtolower(trim((string) ($attestation['self_sha256'] ?? '')));
            if ($fingerprint === '' || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                continue;
            }
            if (!isset($this->releasedRuntimeHashes[$fingerprint])) {
                $this->releasedRuntimeHashes[$fingerprint] = [];
            }
            if (!in_array($hash, $this->releasedRuntimeHashes[$fingerprint], true)) {
                $this->releasedRuntimeHashes[$fingerprint][] = $hash;
            }
        }
        // One fingerprint can ship several platform binaries; a client only ever
        // reports one of them, so every published hash for the fingerprint counts.
        return $this->releasedRuntimeHashes;
    }

    /**
     * Evaluate the opt-in abuse limits. All lookups fail open: a limiter that
     * cannot read its counter must never block a paying client, so the refusal
     * is skipped and the failure is logged instead.
     *
     * @return string|null one of the ERROR_* codes, or null when undecided
     */
    private function abuseLimitRefusal(int $clientId, string $deviceHash, int $forwardId): ?string
    {
        $rateCap = $this->abuseLimit(self::ISSUE_RATE_PER_MINUTE_KEY, 100000);
        if ($rateCap > 0 && $this->recentIssueCount($clientId, $deviceHash) >= $rateCap) {
            return self::ERROR_RATE_LIMITED;
        }
        $liveCap = $this->abuseLimit(self::MAX_LIVE_LEASES_PER_DEVICE_KEY, 1000);
        if ($liveCap > 0 && $this->liveLeaseCount($clientId, $deviceHash) >= $liveCap) {
            return self::ERROR_DEVICE_LIVE_LIMIT;
        }
        $deviceCap = $this->abuseLimit(self::MAX_DEVICES_PER_CLIENT_KEY, 1000);
        if ($deviceCap > 0) {
            $devices = $this->liveDeviceHashes($clientId);
            // A device that already holds a live lease is not a new device.
            if (!isset($devices[$deviceHash]) && count($devices) >= $deviceCap) {
                return self::ERROR_CLIENT_DEVICE_LIMIT;
            }
        }
        return null;
    }

    private function abuseLimit(string $key, int $maximum): int
    {
        return max(0, min($maximum, $this->config->getInt($key, 0)));
    }

    /** Unexpired, non-revoked leases already issued to this client+device. */
    private function liveLeaseCount(int $clientId, string $deviceHash): int
    {
        $key = $clientId . ':' . $deviceHash;
        if ($this->db === null) {
            $now = $this->now();
            $live = 0;
            foreach ($this->memoryLiveLeases[$key] ?? [] as $expiresAt) {
                if ($expiresAt > $now) {
                    $live++;
                }
            }
            return $live;
        }
        try {
            $row = $this->db->fetchOne(
                'SELECT COUNT(*) AS total FROM ultra_client_leases
                 WHERE client_id = :client_id AND device_hash = :device_hash
                   AND consumed_at IS NOT NULL AND revoked_at IS NULL AND expires_at > :now',
                [
                    'client_id' => $clientId,
                    'device_hash' => $deviceHash,
                    'now' => gmdate('Y-m-d H:i:s', $this->now()),
                ]
            );
            return max(0, (int) ($row['total'] ?? 0));
        } catch (Throwable $exception) {
            $this->logAbuseLookupFailure('live_leases', $exception);
            return 0;
        }
    }

    /** Issues for this client+device inside the trailing rate window. */
    private function recentIssueCount(int $clientId, string $deviceHash): int
    {
        $key = $clientId . ':' . $deviceHash;
        if ($this->db === null) {
            $since = $this->now() - self::RATE_WINDOW_SECONDS;
            $count = 0;
            foreach ($this->memoryIssues[$key] ?? [] as $issuedAt) {
                if ($issuedAt >= $since) {
                    $count++;
                }
            }
            return $count;
        }
        try {
            $row = $this->db->fetchOne(
                'SELECT COUNT(*) AS total FROM ultra_client_leases
                 WHERE client_id = :client_id AND device_hash = :device_hash AND issued_at >= :since',
                [
                    'client_id' => $clientId,
                    'device_hash' => $deviceHash,
                    'since' => gmdate('Y-m-d H:i:s', $this->now() - self::RATE_WINDOW_SECONDS),
                ]
            );
            return max(0, (int) ($row['total'] ?? 0));
        } catch (Throwable $exception) {
            $this->logAbuseLookupFailure('issue_rate', $exception);
            return 0;
        }
    }

    /** @return array<string, true> device hashes the client currently holds leases on */
    private function liveDeviceHashes(int $clientId): array
    {
        if ($this->db === null) {
            $now = $this->now();
            $devices = [];
            foreach ($this->memoryLiveLeases as $key => $expiries) {
                [$owner, $deviceHash] = array_pad(explode(':', $key, 2), 2, '');
                if ((int) $owner !== $clientId || $deviceHash === '') {
                    continue;
                }
                foreach ($expiries as $expiresAt) {
                    if ($expiresAt > $now) {
                        $devices[$deviceHash] = true;
                        break;
                    }
                }
            }
            return $devices;
        }
        try {
            $rows = $this->db->fetchAll(
                'SELECT DISTINCT device_hash FROM ultra_client_leases
                 WHERE client_id = :client_id AND revoked_at IS NULL AND expires_at > :now
                 LIMIT 1000',
                [
                    'client_id' => $clientId,
                    'now' => gmdate('Y-m-d H:i:s', $this->now()),
                ]
            );
        } catch (Throwable $exception) {
            $this->logAbuseLookupFailure('devices', $exception);
            return [];
        }
        $devices = [];
        foreach ($rows as $row) {
            $deviceHash = strtolower(trim((string) ($row['device_hash'] ?? '')));
            if ($deviceHash !== '') {
                $devices[$deviceHash] = true;
            }
        }
        return $devices;
    }

    /** DB-less mode has no row to read back, so the local window mirrors it. */
    private function rememberIssuedLease(int $clientId, string $deviceHash, int $expiresAt): void
    {
        if ($this->db !== null) {
            return;
        }
        $key = $clientId . ':' . $deviceHash;
        $now = $this->now();
        $issues = $this->memoryIssues[$key] ?? [];
        $issues[] = $now;
        $this->memoryIssues[$key] = array_slice(array_values(array_filter(
            $issues,
            static fn(int $issuedAt): bool => $issuedAt >= $now - self::RATE_WINDOW_SECONDS
        )), -4096);
        $live = array_values(array_filter(
            $this->memoryLiveLeases[$key] ?? [],
            static fn(int $expiry): bool => $expiry > $now
        ));
        $live[] = $expiresAt;
        $this->memoryLiveLeases[$key] = array_slice($live, -4096);
    }

    /**
     * Every abuse refusal is logged with a stable prefix and, best effort,
     * persisted so an admin can see why a client was refused. Logging can never
     * change the lease outcome.
     */
    private function recordLeaseRefusal(int $clientId, string $deviceHash, int $forwardId, string $errorCode): void
    {
        error_log(sprintf(
            'ultra_lease_refused code=%s client=%d device=%s forward=%d',
            $errorCode,
            $clientId,
            substr($deviceHash, 0, 16),
            $forwardId
        ));
        if ($this->db === null) {
            return;
        }
        try {
            $this->db->insert('ultra_lease_refusals', [
                'client_id' => $clientId,
                'device_hash' => $deviceHash,
                'forward_id' => $forwardId,
                'error_code' => $errorCode,
                'created_at' => gmdate('Y-m-d H:i:s', $this->now()),
            ]);
        } catch (Throwable $exception) {
            error_log('ultra_lease_refusal_persist_failed code=' . $errorCode . ' ' . $exception::class);
        }
    }

    private function logAbuseLookupFailure(string $limit, Throwable $exception): void
    {
        error_log('ultra_lease_limit_lookup_failed limit=' . $limit . ' ' . $exception::class);
    }

    /**
     * Refusals recorded by the abuse limits, newest first. Read-only and
     * fail-soft so an admin page never breaks when the table is absent.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentRefusals(int $limit = 50): array
    {
        if ($this->db === null) {
            return [];
        }
        $limit = max(1, min(200, $limit));
        try {
            $rows = $this->db->fetchAll(
                'SELECT id, client_id, device_hash, forward_id, error_code, created_at
                 FROM ultra_lease_refusals
                 ORDER BY id DESC
                 LIMIT ' . $limit
            );
        } catch (Throwable $exception) {
            error_log('ultra_lease_refusal_lookup_failed ' . $exception::class);
            return [];
        }
        $refusals = [];
        foreach ($rows as $row) {
            // Only a bounded device-hash prefix is ever surfaced in the panel.
            $refusals[] = [
                'client_id' => (int) ($row['client_id'] ?? 0),
                'device_hash_prefix' => substr(strtolower(trim((string) ($row['device_hash'] ?? ''))), 0, 16),
                'forward_id' => (int) ($row['forward_id'] ?? 0),
                'error_code' => substr(trim((string) ($row['error_code'] ?? '')), 0, 64),
                'created_at' => substr(trim((string) ($row['created_at'] ?? '')), 0, 40),
            ];
        }
        return $refusals;
    }

    /** @param array<string,mixed> $request @return array<string,mixed>|null */
    private function normalizeRequest(array $request): ?array
    {
        $forwardId = (int) ($request['forward_id'] ?? 0);
        $ticket = trim((string) ($request['session_ticket'] ?? ''));
        $generation = strtolower(trim((string) ($request['policy_generation'] ?? '')));
        $nonce = strtolower(trim((string) ($request['nonce'] ?? '')));
        $targetDigest = strtolower(trim((string) ($request['target_digest'] ?? '')));
        $transport = strtolower(trim((string) ($request['transport'] ?? '')));
        $purpose = strtolower(trim((string) ($request['purpose'] ?? '')));
        $purpose = $purpose === '' || $purpose === 'ultra' ? 'ultra' : $purpose;
        $routeScope = strtolower(trim((string) ($request['route_scope'] ?? '')));
        $routePolicy = UltraMode::normalizeNativeRoutePolicy($request['route_policy'] ?? 'restricted');
        $candidateOffset = (int) ($request['candidate_offset'] ?? 0);
        if ($forwardId <= 0 || $ticket === ''
            || !preg_match('/^[a-f0-9]{64}$/', $generation)
            || !preg_match('/^[a-f0-9]{16,128}$/', $nonce)
            || !preg_match('/^[a-f0-9]{64}$/', $targetDigest)
            || !in_array($transport, ['tcp', 'udp', 'tcp+udp'], true)
            || !in_array($purpose, ['ultra', UltraMode::NATIVE_OUTBOUND_PURPOSE], true)
            || ($purpose === UltraMode::NATIVE_OUTBOUND_PURPOSE
                && !in_array($routeScope, ['cn_only', 'intl_only', 'global'], true))
            || ($purpose === UltraMode::NATIVE_OUTBOUND_PURPOSE && $routePolicy === null)
            || $candidateOffset < 0 || $candidateOffset > 7) {
            return null;
        }
        return [
            'forward_id' => $forwardId,
            'session_ticket' => $ticket,
            'policy_generation' => $generation,
            'nonce' => $nonce,
            'target_digest' => $targetDigest,
            'transport' => $transport,
            'purpose' => $purpose,
            'route_scope' => $routeScope,
            'route_policy' => $routePolicy ?? UltraMode::NATIVE_ROUTE_POLICY_RESTRICTED,
            'candidate_offset' => $candidateOffset,
        ];
    }

    /** @param array<string,mixed> $candidate */
    private function candidateIsValid(array $candidate): bool
    {
        $ip = trim((string) ($candidate['ip'] ?? ''));
        $port = (int) ($candidate['port'] ?? 0);
        return filter_var($ip, FILTER_VALIDATE_IP) !== false
            && $port > 0 && $port <= 65535
            && trim((string) ($candidate['user'] ?? '')) !== ''
            && trim((string) ($candidate['credential'] ?? '')) !== ''
            && preg_match('/^[A-Za-z0-9_-]{16,128}$/', (string) ($candidate['selection_ref'] ?? '')) === 1
            && $this->candidateDirectCidrsAreValid($candidate);
    }

    private function selectionTier(mixed $value): string
    {
        $tier = strtolower(trim((string) $value));
        return in_array($tier, ['paid', 'mixed', 'free', 'unknown'], true) ? $tier : 'unknown';
    }

    /** @param array<string,mixed> $candidate */
    private function candidateDirectCidrsAreValid(array $candidate): bool
    {
        $raw = $candidate['direct_ip_cidrs'] ?? $candidate['direct_cidrs'] ?? [];
        if (!is_array($raw)) {
            return false;
        }
        foreach ($raw as $cidr) {
            if (!is_string($cidr) || $this->normalizeDirectIpCidr($cidr) === null) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string,mixed> $candidate @return list<string> */
    private function directIpCidrsForCandidate(array $candidate): array
    {
        $ip = trim((string) ($candidate['ip'] ?? ''));
        $endpointCidr = str_contains($ip, ':') ? strtolower($ip) . '/128' : $ip . '/32';
        $normalized = [$endpointCidr => true];
        $raw = $candidate['direct_ip_cidrs'] ?? $candidate['direct_cidrs'] ?? [];
        foreach (is_array($raw) ? $raw : [] as $cidr) {
            if (!is_string($cidr)) {
                continue;
            }
            $value = $this->normalizeDirectIpCidr($cidr);
            if ($value !== null) {
                $normalized[$value] = true;
            }
        }
        return array_values(array_keys($normalized));
    }

    private function normalizeDirectIpCidr(string $value): ?string
    {
        $parts = explode('/', trim($value), 2);
        if (count($parts) !== 2 || !preg_match('/^\d{1,3}$/', trim($parts[1]))) {
            return null;
        }
        $ip = trim($parts[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $isIpv6 = str_contains($ip, ':');
        $prefix = (int) trim($parts[1]);
        $minimum = $isIpv6 ? 32 : 8;
        $maximum = $isIpv6 ? 128 : 32;
        if ($prefix < $minimum || $prefix > $maximum) {
            return null;
        }
        return ($isIpv6 ? strtolower($ip) : $ip) . '/' . $prefix;
    }

    private function consumeNonce(int $clientId, string $deviceHash, int $forwardId, string $nonceHash, string $generation, int $quotaBytes, int $expiresAt, string $candidateKeyHash = ''): bool
    {
        $memoryKey = implode(':', [$clientId, $deviceHash, $forwardId, $nonceHash]);
        if (isset($this->memoryNonces[$memoryKey])) {
            return false;
        }
        if ($this->db === null) {
            $this->memoryNonces[$memoryKey] = true;
            return true;
        }
        try {
            $now = gmdate('Y-m-d H:i:s', $this->now());
            $this->db->insert('ultra_client_leases', [
                'client_id' => $clientId,
                'device_hash' => $deviceHash,
                'forward_id' => $forwardId,
                'policy_generation' => strtolower($generation),
                'nonce_hash' => $nonceHash,
                'lease_hash' => hash('sha256', $memoryKey),
                'candidate_key_hash' => $candidateKeyHash !== '' ? $candidateKeyHash : null,
                'quota_bytes' => $quotaBytes,
                'issued_at' => $now,
                'expires_at' => gmdate('Y-m-d H:i:s', $expiresAt),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            // Retire the leases this one replaces, by EXPIRING them rather than
            // revoking them. Every renewal used to insert a fresh row and leave
            // the previous one live until its own expiry, so a device's live
            // count grew with every renewal instead of staying at one per
            // forward; a device that renewed often reached
            // ULTRA_MAX_LIVE_LEASES_PER_DEVICE and was then refused every further
            // lease with lease_device_live_limit -- a lockout the device
            // inflicted on itself, and the cause of forwards sitting enabled
            // with no lease.
            //
            // It must not be revoked_at: isGenerationRevoked() treats "any row
            // of this forward+generation carries revoked_at" as the whole
            // generation being retired, which is how a target change kills the
            // old scope. Revoking a superseded renewal would therefore make the
            // CURRENT generation look dead and the panel would refuse it. An
            // expired lease is simply no longer live, which is exactly what a
            // superseded renewal is.
            $this->db->execute(
                'UPDATE ultra_client_leases
                 SET expires_at = :expires_at, updated_at = :updated_at
                 WHERE client_id = :client_id AND device_hash = :device_hash
                   AND forward_id = :forward_id AND revoked_at IS NULL
                   AND nonce_hash <> :nonce_hash AND expires_at > :expires_at',
                [
                    'expires_at' => $now,
                    'updated_at' => $now,
                    'client_id' => $clientId,
                    'device_hash' => $deviceHash,
                    'forward_id' => $forwardId,
                    'nonce_hash' => $nonceHash,
                ]
            );
            return true;
        } catch (Throwable $exception) {
            $message = strtolower($exception->getMessage());
            if (str_contains($message, 'duplicate') || str_contains($message, 'unique') || str_contains($message, '23000')) {
                return false;
            }
            throw $exception;
        }
    }

    private function isGenerationRevoked(int $forwardId, string $generation): bool
    {
        $key = $forwardId . ':' . strtolower($generation);
        if (isset($this->memoryGenerations[$key])) {
            return true;
        }
        if ($this->db === null) {
            return false;
        }
        try {
            $row = $this->db->fetchOne(
                'SELECT id FROM ultra_client_leases
                 WHERE forward_id = :forward_id
                   AND policy_generation = :policy_generation
                   AND revoked_at IS NOT NULL
                 LIMIT 1',
                [
                    'forward_id' => $forwardId,
                    'policy_generation' => strtolower($generation),
                ]
            );
            if ($row !== null) {
                $this->memoryGenerations[$key] = true;
                return true;
            }
            return false;
        } catch (Throwable) {
            // A revocation lookup failure must fail closed for the lease.
            return true;
        }
    }

    /**
     * Accept a bounded number of retries for a nonce that was just consumed.
     *
     * @return string the nonce hash the retry was recorded under, or '' when the
     *                nonce is genuinely stale and must still be refused.
     */
    private function consumeReplayNonce(
        int $clientId,
        string $deviceHash,
        int $forwardId,
        string $nonceHash,
        string $generation,
        int $quotaBytes,
        int $expiresAt,
        string $candidateKeyHash,
    ): string {
        if (!$this->replayIsRecentRetry($clientId, $deviceHash, $forwardId, $nonceHash)) {
            return '';
        }
        for ($attempt = 1; $attempt <= self::MAX_REPLAY_ATTEMPTS; $attempt++) {
            $candidate = hash('sha256', $nonceHash . '#retry:' . $attempt);
            if ($this->consumeNonce($clientId, $deviceHash, $forwardId, $candidate, $generation, $quotaBytes, $expiresAt, $candidateKeyHash)) {
                error_log(sprintf(
                    'ultra_lease_nonce_retried client=%d forward=%d attempt=%d',
                    $clientId,
                    $forwardId,
                    $attempt
                ));
                return $candidate;
            }
        }
        return '';
    }

    private function replayIsRecentRetry(int $clientId, string $deviceHash, int $forwardId, string $nonceHash): bool
    {
        $memoryKey = implode(':', [$clientId, $deviceHash, $forwardId, $nonceHash]);
        if (isset($this->memoryNonces[$memoryKey])) {
            return true;
        }
        if ($this->db === null) {
            return false;
        }
        $row = $this->db->fetchOne(
            'SELECT revoked_at, consumed_at, issued_at FROM ultra_client_leases
              WHERE client_id = :client_id AND device_hash = :device_hash
                AND forward_id = :forward_id AND nonce_hash = :nonce_hash
              LIMIT 1',
            [
                'client_id' => $clientId,
                'device_hash' => $deviceHash,
                'forward_id' => $forwardId,
                'nonce_hash' => $nonceHash,
            ]
        );
        if (!is_array($row) || (string) ($row['revoked_at'] ?? '') !== '') {
            return false;
        }
        $issuedAt = strtotime(trim((string) ($row['issued_at'] ?? '')) . ' UTC');
        if ($issuedAt === false) {
            return false;
        }
        return ($this->now() - $issuedAt) <= self::REPLAY_GRACE_SECONDS;
    }

    private function markConsumed(int $clientId, string $deviceHash, int $forwardId, string $nonceHash, string $leaseHash, int $expiresAt, string $candidateKeyHash = ''): void
    {
        if ($this->db === null) {
            return;
        }
        $this->db->execute(
            'UPDATE ultra_client_leases
             SET lease_hash = :lease_hash, candidate_key_hash = :candidate_key_hash,
                 consumed_at = :consumed_at, expires_at = :expires_at, updated_at = :updated_at
             WHERE client_id = :client_id AND device_hash = :device_hash AND forward_id = :forward_id AND nonce_hash = :nonce_hash',
            [
                'client_id' => $clientId,
                'device_hash' => $deviceHash,
                'forward_id' => $forwardId,
                'nonce_hash' => $nonceHash,
                'lease_hash' => $leaseHash,
                'candidate_key_hash' => $candidateKeyHash !== '' ? $candidateKeyHash : null,
                'consumed_at' => gmdate('Y-m-d H:i:s', $this->now()),
                'expires_at' => gmdate('Y-m-d H:i:s', $expiresAt),
                'updated_at' => gmdate('Y-m-d H:i:s', $this->now()),
            ]
        );
    }

    /** @param array<string,mixed> $lease @return array<string,mixed> */
    private function scrubLease(array $lease): array
    {
        $common = [
            'protocol' => (string) ($lease['protocol'] ?? UltraMode::POLICY_PROTOCOL),
            'session_ticket' => (string) ($lease['session_ticket'] ?? ''),
            'forward_id' => (int) $lease['forward_id'],
            'lease_id' => (string) $lease['lease_id'],
            'policy_generation' => (string) $lease['policy_generation'],
            'expires_at' => (string) $lease['expires_at'],
            'transport' => (string) $lease['transport'],
            'rate_limit_mbps' => (int) $lease['rate_limit_mbps'],
            'quota_remaining_bytes' => (int) $lease['quota_remaining_bytes'],
            'selection_ref' => (string) $lease['selection_ref'],
            'candidate_key_hash' => (string) ($lease['candidate_key_hash'] ?? ''),
            'target_digest' => (string) ($lease['target_digest'] ?? ''),
            'nonce_hash' => (string) ($lease['nonce_hash'] ?? ''),
            'endpoint' => [
                'ip' => (string) $lease['endpoint']['ip'],
                'port' => (int) $lease['endpoint']['port'],
            ],
            'credential' => [
                'user' => (string) $lease['credential']['user'],
                'password' => (string) $lease['credential']['password'],
            ],
            'direct_ip_cidrs' => array_values(array_filter(
                is_array($lease['direct_ip_cidrs'] ?? null) ? $lease['direct_ip_cidrs'] : [],
                static fn(mixed $value): bool => is_string($value) && $value !== ''
            )),
        ];
        if (($lease['purpose'] ?? '') !== UltraMode::NATIVE_OUTBOUND_PURPOSE) {
            // The lease endpoint can renew a policy under the same generation.
            // Preserve its authenticated bounds through the final wire scrub;
            // otherwise clients compare the new lease to the old pull expiry.
            foreach (['policy_expires_at', 'policy_renew_after'] as $key) {
                if (trim((string) ($lease[$key] ?? '')) !== '') {
                    $common[$key] = (string) $lease[$key];
                }
            }
            return $common;
        }
        return [
            'protocol' => UltraMode::NATIVE_SOCKS5_PROTOCOL,
            'purpose' => UltraMode::NATIVE_OUTBOUND_PURPOSE,
            'forward_id' => (int) $lease['forward_id'],
            'lease_id' => (string) $lease['lease_id'],
            'policy_generation' => (string) $lease['policy_generation'],
            'expires_at' => (string) $lease['expires_at'],
            'transport' => (string) $lease['transport'],
            'rate_limit_mbps' => (int) $lease['rate_limit_mbps'],
            'quota_remaining_bytes' => (int) $lease['quota_remaining_bytes'],
            'selection_ref' => (string) $lease['selection_ref'],
            'candidate_key_hash' => (string) ($lease['candidate_key_hash'] ?? ''),
            'direction' => (string) ($lease['direction'] ?? ''),
            'route_scope' => (string) ($lease['route_scope'] ?? ''),
            'route_policy' => UltraMode::normalizeNativeRoutePolicy($lease['route_policy'] ?? '')
                ?? UltraMode::NATIVE_ROUTE_POLICY_RESTRICTED,
            'scope_digest' => (string) ($lease['target_digest'] ?? ''),
            'endpoint' => $common['endpoint'],
            'credential' => $common['credential'],
            'direct_ip_cidrs' => $common['direct_ip_cidrs'],
        ];
    }

    /** @return array<string,mixed> */
    private function loadForwardPolicy(int $clientId, int $forwardId): array
    {
        if ($this->db === null) {
            return [];
        }
        $row = $this->db->fetchOne(
            'SELECT f.id AS forward_id, f.target_host, f.target_port, f.transport_mode,
                    f.backend_mode, f.enabled, f.native_outbound_enabled,
                    s.status AS service_status, s.expire_at,
                    fc.is_active AS combination_active, fc.ultra_destination_zone,
                    fc.ultra_allowed_ingress_zones_json, fc.line_direction,
                    p.feature_flags_json AS plan_feature_flags_json
             FROM forwards f
             INNER JOIN services s ON s.id = f.service_id
             LEFT JOIN forward_combinations fc ON fc.id = f.forward_combination_id
             LEFT JOIN service_plans p ON p.id = s.plan_id
             WHERE f.id = :forward_id AND f.line_client_id = :client_id
             LIMIT 1',
            ['forward_id' => $forwardId, 'client_id' => $clientId]
        );
        if (!is_array($row) || strtolower((string) ($row['backend_mode'] ?? '')) !== UltraMode::BACKEND_MODE) {
            return [];
        }
        $target = trim((string) ($row['target_host'] ?? '')) . ':' . (int) ($row['target_port'] ?? 0);
        $transport = strtolower(trim((string) ($row['transport_mode'] ?? 'tcp+udp')));
        $state = ((int) ($row['enabled'] ?? 0) === 1
            && (string) ($row['service_status'] ?? '') === 'active'
            && (int) ($row['combination_active'] ?? 1) === 1) ? 'active' : 'suspended';
        return [
            'forward_id' => $forwardId,
            'service_state' => $state,
            'target' => $target,
            'transport' => in_array($transport, ['tcp', 'udp', 'tcp+udp'], true) ? $transport : 'tcp+udp',
            'direction' => trim((string) ($row['line_direction'] ?? '')),
            'route_scope' => 'global',
            'route_policy' => UltraMode::NATIVE_ROUTE_POLICY_RESTRICTED,
            'native_unrestricted_allowed' => $this->planFlagsAllowUnrestricted($row),
            'rate_limit_mbps' => 0,
            'quota_remaining_bytes' => UltraMode::MAX_QUOTA_LEASE_BYTES,
            'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', $this->serviceExpiryTimestamp((string) ($row['expire_at'] ?? '')) ?? $this->now() + UltraMode::MAX_POLICY_TTL_SECONDS),
            'policy_generation' => hash('sha256', implode("\n", [$forwardId, $state, hash('sha256', $target), $transport, '0', (string) UltraMode::MAX_QUOTA_LEASE_BYTES])),
        ];
    }

    /**
     * services.expire_at is written in UTC; strtotime() would read it in
     * date.timezone (PRC for the panel's CLI workers).
     */
    private function serviceExpiryTimestamp(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }

    private function parseTimestamp(string $value): ?int
    {
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value) !== 1) {
            return null;
        }
        try {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s\\Z', $value, new DateTimeZone('UTC'));
            return $date instanceof DateTimeImmutable ? $date->getTimestamp() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function formatTimestamp(int $timestamp): string
    {
        return gmdate('Y-m-d\\TH:i:s\\Z', $timestamp);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function now(): int
    {
        return (int) ($this->clock)();
    }

    /** @param array<string,mixed> $row */
    private function planFlagsAllowUnrestricted(array $row): bool
    {
        $flags = json_decode((string) ($row['plan_feature_flags_json'] ?? ''), true);
        return is_array($flags)
            && ($flags['allow_native_outbound'] ?? false) === true
            && ($flags['allow_native_outbound_unrestricted'] ?? false) === true;
    }
}
