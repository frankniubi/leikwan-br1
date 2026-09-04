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

        $candidate = ($this->candidateResolver)($normalized, $clientId, trim($deviceId));
        if (!is_array($candidate) || !$this->candidateIsValid($candidate)
            || ($isNative && !UltraMode::isNativeCandidateApproved($candidate))) {
            return ['ok' => false, 'error' => 'no_approved_candidate'];
        }
        if (isset($candidate['policy_generation'])
            && !hash_equals($policyGeneration, (string) $candidate['policy_generation'])) {
            return ['ok' => false, 'error' => 'policy_generation_invalid'];
        }

        $deviceHash = hash('sha256', trim($deviceId));
        $nonceHash = hash('sha256', $normalized['nonce']);
        if (!$this->consumeNonce($clientId, $deviceHash, $forwardId, $nonceHash, $policyGeneration, $quotaBytes, $expiresAt)) {
            return ['ok' => false, 'error' => 'lease_replayed'];
        }

        $leaseId = 'ul2_' . $this->base64UrlEncode(random_bytes(24));
        $leaseExpiresAt = min($expiresAt, $this->now() + UltraMode::MAX_POLICY_TTL_SECONDS);
        $this->markConsumed($clientId, $deviceHash, $forwardId, $nonceHash, hash('sha256', $leaseId), $leaseExpiresAt);
        $lease = $this->scrubLease([
            'protocol' => $isNative ? UltraMode::NATIVE_SOCKS5_PROTOCOL : UltraMode::POLICY_PROTOCOL,
            'purpose' => $normalized['purpose'],
            'session_ticket' => $normalized['session_ticket'],
            'forward_id' => $forwardId,
            'lease_id' => $leaseId,
            'policy_generation' => $policyGeneration,
            'expires_at' => $this->formatTimestamp($leaseExpiresAt),
            'transport' => $policyTransport,
            'rate_limit_mbps' => max(0, min(100000, (int) ($policy['rate_limit_mbps'] ?? 0))),
            'quota_remaining_bytes' => $quotaBytes,
            'selection_ref' => (string) ($candidate['selection_ref'] ?? ''),
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

    private function consumeNonce(int $clientId, string $deviceHash, int $forwardId, string $nonceHash, string $generation, int $quotaBytes, int $expiresAt): bool
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
                'quota_bytes' => $quotaBytes,
                'issued_at' => $now,
                'expires_at' => gmdate('Y-m-d H:i:s', $expiresAt),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
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

    private function markConsumed(int $clientId, string $deviceHash, int $forwardId, string $nonceHash, string $leaseHash, int $expiresAt): void
    {
        if ($this->db === null) {
            return;
        }
        $this->db->execute(
            'UPDATE ultra_client_leases
             SET lease_hash = :lease_hash, consumed_at = :consumed_at, expires_at = :expires_at, updated_at = :updated_at
             WHERE client_id = :client_id AND device_hash = :device_hash AND forward_id = :forward_id AND nonce_hash = :nonce_hash',
            [
                'client_id' => $clientId,
                'device_hash' => $deviceHash,
                'forward_id' => $forwardId,
                'nonce_hash' => $nonceHash,
                'lease_hash' => $leaseHash,
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
            'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', strtotime((string) ($row['expire_at'] ?? '')) ?: $this->now() + UltraMode::MAX_POLICY_TTL_SECONDS),
            'policy_generation' => hash('sha256', implode("\n", [$forwardId, $state, hash('sha256', $target), $transport, '0', (string) UltraMode::MAX_QUOTA_LEASE_BYTES])),
        ];
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
