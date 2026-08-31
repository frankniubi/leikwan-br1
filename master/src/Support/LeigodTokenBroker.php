<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use DateTimeImmutable;
use DateTimeZone;
use Leikwan\Master\Config;
use Throwable;

/** Compatibility class name; all behavior and persisted state are Ultra-only. */
final class LeigodTokenBroker implements LeigodTokenProvider
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
        if ($clientId <= 0) {
            return [];
        }
        $requested = $this->eligibleForwards($forwards);
        if ($requested === []) {
            return [];
        }
        if (!$policyV2) {
            return $this->legacyGrantsForClientForwards($clientId, $deviceId, $requested);
        }
        $now = $this->now();
        $cached = $this->materials->loadGrantCache($clientId, $deviceId);
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
                    && ($serviceExpiresAt === null || $serviceExpiresAt > $now)
                    && (int) ($grant['quota_remaining_bytes'] ?? 0) > 0
                    && $currentQuota >= (int) ($grant['quota_remaining_bytes'] ?? 0)
                    && ($currentRate <= 0 || $grantRate <= 0 || $currentRate >= $grantRate)) {
                    $result[$forwardId] = $grant;
                }
                continue;
            }
            $quotaBytes = $this->quotaBytes($request);
            $serviceExpiresAt = $this->parseServiceExpiry((string) ($request['service_expire_at'] ?? ''));
            $currentRate = max(0, min(100000, (int) ($request['speed_limit_mbps'] ?? 0)));
            $grantRate = max(0, (int) ($grant['rate_limit_mbps'] ?? 0));
            $policyGeneration = $this->policyGeneration($request, (string) $snapshot['fingerprint'], $quotaBytes);
            if ($this->grantUsable($grant, $now)
                && hash_equals($policyGeneration, (string) ($grant['policy_generation'] ?? ''))
                && $this->parseTimestamp((string) ($grant['renew_after'] ?? '')) > $now
                && $quotaBytes > 0
                && $quotaBytes >= (int) ($grant['quota_remaining_bytes'] ?? 0)
                && ($serviceExpiresAt === null || $serviceExpiresAt > $now)
                && ($currentRate <= 0 || $grantRate <= 0 || $currentRate >= $grantRate)) {
                $result[$forwardId] = $grant;
                continue;
            }
            if ($quotaBytes <= 0 || ($serviceExpiresAt !== null && $serviceExpiresAt <= $now)) {
                continue;
            }
            $expiryCandidates = [$now + $this->maxTtlSeconds(), (int) $snapshot['expires_at']];
            if ($serviceExpiresAt !== null) {
                $expiryCandidates[] = $serviceExpiresAt;
            }
            $expiresAt = min(...$expiryCandidates);
            if ($expiresAt <= $now + 30) {
                continue;
            }
            $renewAfter = max($now + 30, $expiresAt - $this->refreshSkewSeconds());
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
                'quota_remaining_bytes' => $quotaBytes,
                'target_digest' => hash('sha256', (string) $request['target']),
            ];
        }
        try {
            $this->materials->saveGrantCache($clientId, $deviceId, $result);
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
                $result[$forwardId] = $grant;
                continue;
            }
            $snapshot = $this->materials->lineSnapshot($request['lines']);
            if ($snapshot === null) {
                if ($this->legacyGrantUsable($grant, $now)) {
                    $result[$forwardId] = $grant;
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

    /** @param array<string, mixed> $request */
    private function policyGeneration(array $request, string $materialFingerprint, int $quotaBytes): string
    {
        return hash('sha256', implode("\n", [
            (string) ($request['forward_id'] ?? 0),
            'active',
            hash('sha256', (string) ($request['target'] ?? '')),
            (string) ($request['transport'] ?? ''),
            (string) ($request['speed_limit_mbps'] ?? 0),
            (string) $quotaBytes,
            (string) ($request['service_expire_at'] ?? ''),
            $materialFingerprint,
        ]));
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
