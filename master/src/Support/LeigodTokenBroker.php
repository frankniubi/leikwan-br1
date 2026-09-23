<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use DateTimeImmutable;
use DateTimeZone;
use Leikwan\Master\Config;
use Throwable;

/** Compatibility class name; all behavior and persisted state are Ultra-only. */
final class LeigodTokenBroker implements UltraOfflineValidityTokenProvider
{
    /** @var callable(): int */
    private $clock;

    private UltraMaterialService $materials;

    public function __construct(
        private Config $config,
        private ?AppSettings $settings = null,
        ?UltraMaterialService $materials = null,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
        $this->materials = $materials ?? new UltraMaterialService($config, null, $settings, $this->clock);
    }

    /**
     * @param array<int, array<string, mixed>> $forwards
     * @return array<string, array<string, mixed>>
     */
    public function grantsForClientForwards(int $clientId, string $deviceId, array $forwards, bool $policyV2 = false): array
    {
        return $this->grantsForClientForwardsWithOfflineValidity($clientId, $deviceId, $forwards, $policyV2, 0);
    }

    /**
     * Same as grantsForClientForwards(), plus the client's announced offline
     * validity (UltraMode::OFFLINE_VALIDITY_HEADER, already parsed; 0 = absent).
     *
     * Header absent (or long grants switched off): exactly the short path.
     * Header present on policy v2: expires_at = now + V where
     * V = min(header, ULTRA_OFFLINE_VALIDITY_SECONDS, material expiry - now,
     * service expiry - now); renew_after = now + ULTRA_RENEW_INTERVAL_SECONDS;
     * quota_remaining_bytes = min(remaining, slice). The capability-v1 path
     * ignores the header.
     *
     * policy_generation is deliberately the same for both paths: it describes
     * the scope (target, transport, rate, remaining quota, material), not the
     * lifetime. A runtime refuses a generation it has seen before once it has
     * moved on (ErrPolicyRollback), so a client that alternates between a long
     * and a short grant must never see the generation flip back and forth.
     *
     * @param array<int, array<string, mixed>> $forwards
     * @return array<string, array<string, mixed>>
     */
    public function grantsForClientForwardsWithOfflineValidity(
        int $clientId,
        string $deviceId,
        array $forwards,
        bool $policyV2,
        int $offlineValiditySeconds,
    ): array {
        if ($clientId <= 0) {
            return [];
        }
        $requested = $this->eligibleForwards(
            (new ExternalUoTPool($this->config))->assign($clientId, $deviceId,
                ExternalUoTDefaults::withClientDeliveryMode($this->config, $clientId, $forwards, $deviceId))
        );
        if ($requested === []) {
            return [];
        }
        if (!$policyV2) {
            return $this->legacyGrantsForClientForwards($clientId, $deviceId, $requested);
        }
        $now = $this->now();
        // 0 = short legacy grants. Otherwise the ceiling for this request; it
        // also names the grant cache, so short and long grants never mix.
        $validityCap = UltraMode::offlineValidityCapSeconds($this->config, $offlineValiditySeconds);
        $validityClass = $validityCap > 0 ? 'ov' . $validityCap : '';
        $cached = $this->materials->loadGrantCache($clientId, $deviceId, $validityClass);
        // One query for the whole batch: which generations the lease plane has
        // already refused, and how far each forward's revocation history has run.
        $revocations = $this->materials->revocationIndex(array_keys($requested));
        $result = [];
        foreach ($requested as $forwardId => $request) {
            $grant = $cached[$forwardId] ?? null;
            $snapshot = $this->materials->lineSnapshot($request['lines']);
            if ($snapshot === null) {
                $serviceExpiresAt = $this->parseServiceExpiry((string) ($request['service_expire_at'] ?? ''));
                $currentQuota = $this->quotaBytes($request);
                $currentRate = max(0, min(100000, (int) ($request['speed_limit_mbps'] ?? 0)));
                $grantRate = max(0, (int) ($grant['rate_limit_mbps'] ?? 0));
                if ($this->grantUsable($grant, $now)
                    && !isset($revocations['revoked'][((int) $forwardId) . ':' . strtolower(trim((string) ($grant['policy_generation'] ?? '')))])
                    && ($serviceExpiresAt === null || $serviceExpiresAt > $now)
                    && (int) ($grant['quota_remaining_bytes'] ?? 0) > 0
                    && $currentQuota >= (int) ($grant['quota_remaining_bytes'] ?? 0)
                    && ($currentRate <= 0 || $grantRate <= 0 || $currentRate >= $grantRate)) {
                    $result[$forwardId] = $this->attachExternalUoT($grant, $request['external_uot'] ?? null, (string) ($request['target'] ?? ''));
                }
                continue;
            }
            $quotaBytes = $this->quotaBytes($request);
            $serviceExpiresAt = $this->parseServiceExpiry((string) ($request['service_expire_at'] ?? ''));
            $currentRate = max(0, min(100000, (int) ($request['speed_limit_mbps'] ?? 0)));
            $grantRate = max(0, (int) ($grant['rate_limit_mbps'] ?? 0));
            $policyGeneration = $this->policyGeneration(
                $request,
                (string) $snapshot['fingerprint'],
                $quotaBytes,
                [
                    'epoch' => (int) ($revocations['epochs'][(int) $forwardId] ?? 0),
                    'revoked' => $revocations['revoked'],
                ]
            );
            if ($this->grantUsable($grant, $now)
                && hash_equals($policyGeneration, (string) ($grant['policy_generation'] ?? ''))
                && $this->parseTimestamp((string) ($grant['renew_after'] ?? '')) > $now
                && $quotaBytes > 0
                && $quotaBytes >= (int) ($grant['quota_remaining_bytes'] ?? 0)
                && ($serviceExpiresAt === null || $serviceExpiresAt > $now)
                && ($currentRate <= 0 || $grantRate <= 0 || $currentRate >= $grantRate)) {
                $result[$forwardId] = $this->attachExternalUoT(
                    $grant,
                    $request['external_uot'] ?? null,
                    (string) ($request['target'] ?? '')
                );
                continue;
            }
            if ($quotaBytes <= 0 || ($serviceExpiresAt !== null && $serviceExpiresAt <= $now)) {
                continue;
            }
            $expiryCandidates = [
                $now + ($validityCap > 0 ? $validityCap : $this->maxTtlSeconds()),
                (int) $snapshot['expires_at'],
            ];
            if ($serviceExpiresAt !== null) {
                $expiryCandidates[] = $serviceExpiresAt;
            }
            $expiresAt = min(...$expiryCandidates);
            if ($expiresAt <= $now + 30) {
                continue;
            }
            $grantQuotaBytes = $quotaBytes;
            if ($validityCap > 0) {
                // Long grant: renew on the same cadence as the short path so
                // revocation, suspension and quota changes still land on the
                // next pull, while an unreachable panel leaves the client
                // serving until expires_at.
                $renewAfter = $now + $this->renewIntervalSeconds($expiresAt - $now);
                $grantQuotaBytes = $this->grantQuotaSliceBytes(
                    $quotaBytes,
                    (int) $request['speed_limit_mbps'],
                    $expiresAt - $now
                );
            } else {
                $renewAfter = max($now + 30, $expiresAt - $this->refreshSkewSeconds());
            }
            if ($renewAfter >= $expiresAt) {
                $renewAfter = max($now, $expiresAt - 1);
            }
            $token = $this->materials->issueClientToken([
                'client_id' => $clientId,
                'device_hash' => hash('sha256', $deviceId !== '' ? $deviceId : 'none'),
                'forward_id' => $request['forward_id'],
                'service_id' => $request['service_id'],
                'target' => $request['target'],
                'transport' => $request['transport'],
                'rate_limit_mbps' => $request['speed_limit_mbps'],
                'line_ids' => $request['line_ids'],
                'lines' => $request['lines'],
                'line_direction' => $request['line_direction'],
                'destination_zone' => $request['destination_zone'],
                'allowed_ingress_zones' => $request['allowed_ingress_zones'],
                'fallback_policy' => $request['fallback_policy'],
                'material_fingerprint' => $snapshot['fingerprint'],
                'policy_generation' => $policyGeneration,
                'target_digest' => hash('sha256', (string) $request['target']),
            ], $expiresAt);
            if ($token === '') {
                continue;
            }
            $result[$forwardId] = [
                'protocol' => UltraMode::POLICY_PROTOCOL,
                'session_ticket' => $token,
                'forward_id' => $request['forward_id'],
                'policy_generation' => $policyGeneration,
                'issued_at' => $this->formatTimestamp($now),
                'renew_after' => $this->formatTimestamp($renewAfter),
                'expires_at' => $this->formatTimestamp($expiresAt),
                'service_state' => 'active',
                'transport' => $request['transport'],
                'rate_limit_mbps' => $request['speed_limit_mbps'],
                'quota_remaining_bytes' => $grantQuotaBytes,
                'target_digest' => hash('sha256', (string) $request['target']),
            ];
            $result[$forwardId] = $this->attachExternalUoT($result[$forwardId], $request['external_uot'] ?? null, (string) ($request['target'] ?? ''));
        }
        try {
            $this->materials->saveGrantCache($clientId, $deviceId, $result, $validityClass);
        } catch (Throwable) {
            // A cache write failure must not invalidate a freshly minted grant.
        }
        return $result;
    }

    /**
     * Preserve the v1 material flow for Linux agents until they migrate to
     * the lease protocol. Windows explicitly requests policy v2.
     *
     * @param array<string, array<string, mixed>> $requested
     * @return array<string, array<string, mixed>>
     */
    private function legacyGrantsForClientForwards(int $clientId, string $deviceId, array $requested): array
    {
        $now = $this->now();
        $cached = $this->materials->loadGrantCache($clientId, $deviceId);
        $result = [];
        foreach ($requested as $forwardId => $request) {
            $grant = $cached[$forwardId] ?? null;
            if ($this->legacyGrantUsable($grant, $now)
                && $this->parseTimestamp((string) ($grant['renew_after'] ?? '')) > $now) {
                $result[$forwardId] = $this->attachExternalUoT(
                    $grant,
                    $request['external_uot'] ?? null,
                    (string) ($request['target'] ?? '')
                );
                continue;
            }
            $snapshot = $this->materials->lineSnapshot($request['lines']);
            if ($snapshot === null) {
                if ($this->legacyGrantUsable($grant, $now)) {
                    $result[$forwardId] = $this->attachExternalUoT($grant, $request['external_uot'] ?? null, (string) ($request['target'] ?? ''));
                }
                continue;
            }
            $expiresAt = min($now + $this->legacyMaxTtlSeconds(), (int) $snapshot['expires_at']);
            if ($expiresAt <= $now + 30) {
                continue;
            }
            $renewAfter = max($now + 30, $expiresAt - $this->legacyRefreshSkewSeconds());
            if ($renewAfter >= $expiresAt) {
                $renewAfter = max($now, $expiresAt - 1);
            }
            $token = $this->materials->issueClientToken([
                'client_id' => $clientId,
                'device_hash' => hash('sha256', $deviceId !== '' ? $deviceId : 'none'),
                'forward_id' => $request['forward_id'],
                'service_id' => $request['service_id'],
                'target' => $request['target'],
                'transport' => $request['transport'],
                'rate_limit_mbps' => $request['speed_limit_mbps'],
                'line_ids' => $request['line_ids'],
                'lines' => $request['lines'],
                'line_direction' => $request['line_direction'],
                'destination_zone' => $request['destination_zone'],
                'allowed_ingress_zones' => $request['allowed_ingress_zones'],
                'fallback_policy' => $request['fallback_policy'],
                'material_fingerprint' => $snapshot['fingerprint'],
            ], $expiresAt);
            if ($token === '') {
                continue;
            }
            $result[$forwardId] = [
                'protocol' => UltraMode::CAPABILITY_PROTOCOL,
                'token' => $token,
                'issued_at' => $this->formatTimestamp($now),
                'renew_after' => $this->formatTimestamp($renewAfter),
                'expires_at' => $this->formatTimestamp($expiresAt),
                'generation' => substr((string) $snapshot['fingerprint'], 0, 32),
                'scope' => ['client_id' => $clientId, 'forward_id' => $request['forward_id']],
                'line_ips' => $snapshot['line_ips'],
                'transport' => $request['transport'],
                'rate_limit_mbps' => $request['speed_limit_mbps'],
                'destination_zone' => $request['destination_zone'],
                'allowed_ingress_zones' => $request['allowed_ingress_zones'],
                'fallback_policy' => $request['fallback_policy'],
            ];
            $result[$forwardId] = $this->attachExternalUoT($result[$forwardId], $request['external_uot'] ?? null, (string) ($request['target'] ?? ''));
        }
        try {
            $this->materials->saveGrantCache($clientId, $deviceId, $result);
        } catch (Throwable) {
            // A cache write failure must not invalidate a fresh grant.
        }
        return $result;
    }

    /** @param array<int, array<string, mixed>> $forwards @return array<string, array<string, mixed>> */
    private function eligibleForwards(array $forwards): array
    {
        $result = [];
        foreach ($forwards as $forward) {
            if (!is_array($forward)
                || !UltraMode::isBackendMode($forward['backend_mode'] ?? '')
                || (string) ($forward['service_status'] ?? '') !== 'active') {
                continue;
            }
            $forwardId = (int) ($forward['forward_id'] ?? 0);
            $target = trim((string) ($forward['target'] ?? ''));
            $lines = [];
            $lineIds = [];
            foreach (is_array($forward['_ultra_lines'] ?? null) ? $forward['_ultra_lines'] : [] as $rawLine) {
                if (!is_array($rawLine)) {
                    continue;
                }
                $lineId = (int) ($rawLine['line_id'] ?? 0);
                $sourceId = trim((string) ($rawLine['source_id'] ?? ''));
                $lineKey = ($sourceId !== '' ? $sourceId : 'legacy') . ':' . $lineId;
                if ($lineId <= 0 || $lineId > 45535 || isset($lineIds[$lineKey])) {
                    continue;
                }
                $lineIds[$lineKey] = $lineId;
                $lines[] = [
                    'line_id' => $lineId,
                    'source_id' => $sourceId,
                    'ingress_zone' => $this->zone((string) ($rawLine['ingress_zone'] ?? 'unknown')),
                    'destination_zone' => $this->zone((string) ($rawLine['destination_zone'] ?? 'unknown')),
                    'access_class' => $this->classification($rawLine['access_class'] ?? 'unknown'),
                    'native_udp_state' => $this->classification($rawLine['native_udp_state'] ?? 'unknown'),
                    'native_socks5_state' => $this->nativeSocks5State($rawLine['native_socks5_state'] ?? 'unknown'),
                    'pricing_class' => $this->pricingClass($rawLine['pricing_class'] ?? 'unknown'),
                    'pricing_evidence' => substr(trim((string) ($rawLine['pricing_evidence'] ?? '')), 0, 255),
                ];
                if (count($lines) >= 8) {
                    break;
                }
            }
            if ($lines === []) {
                foreach (is_array($forward['_ultra_line_ids'] ?? null) ? $forward['_ultra_line_ids'] : [] as $rawLineId) {
                    $lineId = (int) $rawLineId;
                    $lineKey = 'legacy:' . $lineId;
                    if ($lineId > 0 && $lineId <= 45535 && !isset($lineIds[$lineKey])) {
                        $lineIds[$lineKey] = $lineId;
                        $lines[] = ['line_id' => $lineId, 'source_id' => '', 'ingress_zone' => 'unknown', 'destination_zone' => 'unknown'];
                    }
                    if (count($lines) >= 8) {
                        break;
                    }
                }
            }
            $transport = strtolower(trim((string) ($forward['transport'] ?? 'tcp+udp')));
            if ($forwardId <= 0 || $target === '' || $lineIds === [] || !in_array($transport, ['tcp', 'udp', 'tcp+udp'], true)) {
                continue;
            }
            $result[(string) $forwardId] = [
                'forward_id' => $forwardId,
                'service_id' => max(0, (int) ($forward['service_id'] ?? 0)),
                'target' => $target,
                'transport' => $transport,
                'speed_limit_mbps' => max(0, min(100000, (int) ($forward['speed_limit_mbps'] ?? 0))),
                'service_expire_at' => trim((string) ($forward['service_expire_at'] ?? '')),
                'service_traffic_limit_gb' => max(0, (int) ($forward['service_traffic_limit_gb'] ?? 0)),
                'service_traffic_used_bytes' => max(0, (int) ($forward['service_traffic_used_bytes'] ?? 0)),
                'line_ids' => array_slice(array_values($lineIds), 0, 8),
                'lines' => $lines,
                'line_direction' => substr(trim((string) ($forward['_ultra_line_direction'] ?? '')), 0, 191),
                'destination_zone' => $this->zone((string) ($forward['_ultra_destination_zone'] ?? 'unknown')),
                'allowed_ingress_zones' => $this->zones((array) ($forward['_ultra_allowed_ingress_zones'] ?? [])),
                'fallback_policy' => $this->fallbackPolicy((string) ($forward['_ultra_fallback_policy'] ?? 'detected_then_any')),
            ];
            $externalUoT = $this->normalizeExternalUoT($forward['external_uot'] ?? null, $target);
            if ($externalUoT !== null) {
                $result[(string) $forwardId]['external_uot'] = $externalUoT;
            }
            foreach (['quota_remaining_bytes', 'quota_bytes', 'traffic_limit_bytes'] as $quotaKey) {
                if (array_key_exists($quotaKey, $forward) && $forward[$quotaKey] !== null && $forward[$quotaKey] !== '') {
                    $result[(string) $forwardId][$quotaKey] = max(0, min(UltraMode::MAX_QUOTA_LEASE_BYTES, (int) $forward[$quotaKey]));
                    break;
                }
            }
        }
        ksort($result, SORT_NUMERIC);
        return $result;
    }

    /** @param mixed $grant */
    private function grantUsable(mixed $grant, int $now): bool
    {
        if (!is_array($grant)
            || ($grant['protocol'] ?? '') !== UltraMode::POLICY_PROTOCOL
            || trim((string) ($grant['session_ticket'] ?? '')) === ''
            || trim((string) ($grant['policy_generation'] ?? '')) === ''
            || trim((string) ($grant['target_digest'] ?? '')) === ''
            || !array_key_exists('quota_remaining_bytes', $grant)
            || !in_array((string) ($grant['transport'] ?? ''), ['tcp', 'udp', 'tcp+udp'], true)) {
            return false;
        }
        return ($this->parseTimestamp((string) ($grant['expires_at'] ?? '')) ?? 0) > $now;
    }

    /** @param mixed $grant */
    private function legacyGrantUsable(mixed $grant, int $now): bool
    {
        if (!is_array($grant)
            || ($grant['protocol'] ?? '') !== UltraMode::CAPABILITY_PROTOCOL
            || trim((string) ($grant['token'] ?? '')) === ''
            || !is_array($grant['line_ips'] ?? null)
            || $grant['line_ips'] === []
            || !in_array((string) ($grant['transport'] ?? ''), ['tcp', 'udp', 'tcp+udp'], true)) {
            return false;
        }
        return ($this->parseTimestamp((string) ($grant['expires_at'] ?? '')) ?? 0) > $now;
    }

    private function zone(string $value): string
    {
        $zone = strtolower(trim($value));
        return preg_match('/^[a-z0-9-]{1,32}$/', $zone) === 1 ? $zone : 'unknown';
    }

    /** @param array<int, mixed> $values @return array<int, string> */
    private function zones(array $values): array
    {
        $zones = [];
        foreach (array_slice($values, 0, 16) as $value) {
            $zone = $this->zone((string) $value);
            if ($zone !== 'unknown' && !in_array($zone, $zones, true)) {
                $zones[] = $zone;
            }
        }
        return $zones;
    }

    private function fallbackPolicy(string $value): string
    {
        $policy = strtolower(trim($value));
        return in_array($policy, ['detected_only', 'detected_then_any', 'any'], true)
            ? $policy
            : 'detected_then_any';
    }

    /** @param mixed $value @return array<string,mixed>|null */
    private function normalizeExternalUoT(mixed $value, string $target): ?array
    {
        if (!is_array($value) || ($value['enabled'] ?? false) !== true) {
            return null;
        }
        $sidecar = trim((string) ($value['sidecar_target'] ?? ''));
        $token = trim((string) ($value['token'] ?? ''));
        if (preg_match('/^\[[0-9A-Fa-f:]+\]:[0-9]{1,5}$|^[A-Za-z0-9._-]+:[0-9]{1,5}$/', $sidecar) !== 1
            || $token === '' || strlen($token) > 8192 || preg_match('/\s/', $token) === 1) {
            return null;
        }
        $port = (int) substr($sidecar, strrpos($sidecar, ':') + 1);
        if ($port < 1 || $port > 65535) {
            return null;
        }
        $normalized = [
            'enabled' => true,
            'sidecar_target' => $sidecar,
            'token' => $token,
            'queue_packets' => max(1, min(4096, (int) ($value['queue_packets'] ?? 256))),
            'keepalive_seconds' => max(5, min(30, (int) ($value['keepalive_seconds'] ?? 10))),
            'reconnect_grace_seconds' => max(5, min(300, (int) ($value['reconnect_grace_seconds'] ?? 30))),
            'frame_payload_max' => max(256, min(65507, (int) ($value['frame_payload_max'] ?? 65507))),
            'target_digest' => hash('sha256', trim($target)),
        ];
        $deliveryMode = strtolower(trim((string) ($value['delivery_mode'] ?? 'raw')));
        if ($deliveryMode !== '' && $deliveryMode !== 'raw') {
            // Preserve unsupported modes so upgraded clients fail closed.
            $normalized['delivery_mode'] = $deliveryMode;
        }
        return $normalized;
    }

    /** @param mixed $value @return array<string,mixed> */
    private function attachExternalUoT(array $grant, mixed $value, string $target = ''): array
    {
        if (is_array($value) && ($value['enabled'] ?? false) === true) {
            $normalized = $this->normalizeExternalUoT($value, $target !== '' ? $target : (string) ($grant['target'] ?? ''));
            if ($normalized !== null) {
                $grant['external_uot'] = $normalized;
                return $grant;
            }
        }
        unset($grant['external_uot']);
        return $grant;
    }

    private function classification(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['native_udp', 'wireguard_only', 'wireguard', 'dynamic', 'dynamic_ingress', 'relay_unreachable', 'unknown', 'verified', 'failed', 'unverified'], true)
            ? $normalized
            : 'unknown';
    }

    private function nativeSocks5State(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, UltraInventoryService::NATIVE_SOCKS5_STATES, true)
            ? $normalized
            : 'unknown';
    }

    private function pricingClass(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['paid', 'mixed', 'free', 'unknown'], true) ? $normalized : 'unknown';
    }

    private function maxTtlSeconds(): int
    {
        return max(
            UltraMode::MIN_POLICY_TTL_SECONDS,
            min(UltraMode::MAX_POLICY_TTL_SECONDS, $this->config->getInt(UltraMode::MAX_TTL_KEY, UltraMode::MAX_POLICY_TTL_SECONDS))
        );
    }

    private function refreshSkewSeconds(): int
    {
        return max(30, min($this->maxTtlSeconds() - 1, $this->config->getInt(UltraMode::REFRESH_SKEW_KEY, 60)));
    }

    /**
     * Renewal cadence of a long grant: ULTRA_RENEW_INTERVAL_SECONDS (default
     * 240, the short path's effective cadence), clamped to [30, V - 60] so a
     * renewal always has a minute of slack before the grant lapses.
     */
    private function renewIntervalSeconds(int $validitySeconds): int
    {
        $raw = trim((string) $this->config->get(UltraMode::RENEW_INTERVAL_KEY, ''));
        $configured = ($raw !== '' && preg_match('/^[0-9]+$/', $raw) === 1)
            ? (int) $raw
            : UltraMode::DEFAULT_RENEW_INTERVAL_SECONDS;
        $ceiling = $validitySeconds - UltraMode::RENEW_EXPIRY_MARGIN_SECONDS;
        return max(UltraMode::MIN_RENEW_INTERVAL_SECONDS, min($configured, $ceiling));
    }

    /**
     * Quota carried by a long grant: min(remaining, slice), so a device that
     * loses the panel can overspend by at most one slice. Online, every lease
     * the runtime receives resets its local limiter to this value and every
     * renewal re-slices from the reconciled remaining quota, so the slice only
     * has to outlast one renewal interval at the forward's top speed: it is
     * never smaller than two renewal intervals at that speed (an unlimited
     * forward is assumed to run at ULTRA_GRANT_QUOTA_UNLIMITED_RATE_MBPS,
     * default 1000). Default slice: max(1 GiB, rate * V) with a rate limit,
     * 10 GiB without one (raised to the floor: ~56 GiB at 1000 Mbps).
     * ULTRA_GRANT_QUOTA_SLICE_ENABLED=0 hands out the full remaining quota.
     */
    private function grantQuotaSliceBytes(int $quotaBytes, int $rateLimitMbps, int $validitySeconds): int
    {
        if ($quotaBytes <= 0 || !$this->config->getBool(UltraMode::GRANT_QUOTA_SLICE_ENABLED_KEY, true)) {
            return $quotaBytes;
        }
        $validitySeconds = max(1, $validitySeconds);
        $rateLimitMbps = max(0, $rateLimitMbps);
        $assumedMbps = $rateLimitMbps;
        if ($assumedMbps <= 0) {
            $rawRate = trim((string) $this->config->get(UltraMode::GRANT_QUOTA_UNLIMITED_RATE_KEY, ''));
            $assumedMbps = ($rawRate !== '' && preg_match('/^[0-9]+$/', $rawRate) === 1)
                ? max(100, min(100000, (int) $rawRate))
                : UltraMode::DEFAULT_GRANT_QUOTA_UNLIMITED_RATE_MBPS;
        }
        $bytesPerSecond = $assumedMbps * 125000;
        $floor = $bytesPerSecond * 2 * $this->renewIntervalSeconds($validitySeconds);

        $rawSlice = trim((string) $this->config->get(UltraMode::GRANT_QUOTA_SLICE_KEY, ''));
        if ($rawSlice !== '' && preg_match('/^[0-9]+$/', $rawSlice) === 1 && (int) $rawSlice > 0) {
            $slice = (int) $rawSlice;
        } elseif ($rateLimitMbps > 0) {
            $slice = $bytesPerSecond * $validitySeconds;
        } else {
            $slice = UltraMode::DEFAULT_UNLIMITED_RATE_QUOTA_SLICE_BYTES;
        }
        $slice = max(UltraMode::MIN_GRANT_QUOTA_SLICE_BYTES, $floor, $slice);
        $slice = min(UltraMode::MAX_QUOTA_LEASE_BYTES, $slice);
        return min($quotaBytes, $slice);
    }

    private function legacyMaxTtlSeconds(): int
    {
        return max(60, min(3600, $this->config->getInt(UltraMode::MAX_TTL_KEY, 1800)));
    }

    private function legacyRefreshSkewSeconds(): int
    {
        return max(30, min($this->legacyMaxTtlSeconds() - 1, $this->config->getInt(UltraMode::REFRESH_SKEW_KEY, 600)));
    }

    /** @param array<string, mixed> $request */
    private function quotaBytes(array $request): int
    {
        $keys = ['quota_remaining_bytes', 'quota_bytes', 'traffic_limit_bytes'];
        $provided = false;
        $value = UltraMode::MAX_QUOTA_LEASE_BYTES;
        foreach ($keys as $key) {
            if (!array_key_exists($key, $request) || $request[$key] === null || $request[$key] === '') {
                continue;
            }
            $provided = true;
            $value = max(0, (int) $request[$key]);
            break;
        }
        if (!$provided) {
            $limitGb = max(0, (int) ($request['service_traffic_limit_gb'] ?? 0));
            $usedBytes = max(0, (int) ($request['service_traffic_used_bytes'] ?? 0));
            if ($limitGb > 0) {
                return min(UltraMode::MAX_QUOTA_LEASE_BYTES, max(0, ($limitGb * 1024 * 1024 * 1024) - $usedBytes));
            }
            return UltraMode::MAX_QUOTA_LEASE_BYTES;
        }
        return min(UltraMode::MAX_QUOTA_LEASE_BYTES, $value);
    }

    /**
     * @param array<string, mixed> $request
     * @param array{epoch?: int, revoked?: array<string, true>} $revocation
     */
    private function policyGeneration(
        array $request,
        string $materialFingerprint,
        int $quotaBytes,
        array $revocation = []
    ): string {
        $parts = [
            (string) ($request['forward_id'] ?? 0),
            'active',
            hash('sha256', (string) ($request['target'] ?? '')),
            (string) ($request['transport'] ?? ''),
            (string) ($request['speed_limit_mbps'] ?? 0),
            (string) $quotaBytes,
            (string) ($request['service_expire_at'] ?? ''),
            $materialFingerprint,
        ];
        $reputationFingerprint = $this->materials->candidateReputationFingerprint(
            is_array($request['lines'] ?? null) ? $request['lines'] : [],
            (string) ($request['transport'] ?? 'tcp+udp')
        );
        if ($reputationFingerprint !== '') {
            $parts[] = $reputationFingerprint;
        }

        $generation = hash('sha256', implode("\n", $parts));
        $epoch = (int) ($revocation['epoch'] ?? 0);
        $revoked = $revocation['revoked'] ?? [];
        if ($epoch <= 0 || $revoked === []) {
            // No revocation history for this forward: the plain hash is the
            // generation every client already holds, so nothing rotates.
            return $generation;
        }

        // A revoked generation must never be handed out again, and this hash is
        // a pure function of the request, so it would be re-minted on every pull
        // while the lease plane kept refusing it - an enabled forward with no
        // lease and no way back. Fold the forward's revocation epoch in so the
        // client always receives a generation the lease plane will honour. The
        // epoch is the highest revoked row id, so every further revocation
        // raises it again and can never reproduce an earlier value.
        $forwardKey = (int) ($request['forward_id'] ?? 0);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            if (!isset($revoked[$forwardKey . ':' . strtolower($generation)])) {
                break;
            }
            $generation = hash('sha256', implode("\n", [...$parts, 'revocation-epoch:' . $epoch . ':' . $attempt]));
        }

        return $generation;
    }

    private function parseTimestamp(string $value): ?int
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', trim($value)) !== 1) {
            return null;
        }
        try {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s\\Z', trim($value), new DateTimeZone('UTC'));
            return $date instanceof DateTimeImmutable ? $date->getTimestamp() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function parseServiceExpiry(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? null : (int) $timestamp;
    }

    private function formatTimestamp(int $timestamp): string
    {
        return gmdate('Y-m-d\\TH:i:s\\Z', $timestamp);
    }

    private function now(): int
    {
        return (int) ($this->clock)();
    }
}
