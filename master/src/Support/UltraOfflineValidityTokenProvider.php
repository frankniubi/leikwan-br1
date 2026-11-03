<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

/**
 * A token provider that can mint long ("offline-capable") policy-v2 grants
 * for clients that announced UltraMode::OFFLINE_VALIDITY_HEADER.
 *
 * Kept as a separate interface so LeigodTokenProvider (and every existing
 * implementation of it) is unchanged; a provider that does not implement this
 * simply keeps serving short grants.
 */
interface UltraOfflineValidityTokenProvider extends LeigodTokenProvider
{
    /**
     * @param array<int, array<string, mixed>> $forwards
     * @param int $offlineValiditySeconds the client's parsed header value
     *        (UltraMode::parseOfflineValidityHeader); 0 = header absent, which
     *        must behave exactly like grantsForClientForwards().
     * @return array<string, array<string, mixed>> keyed by forward ID
     */
    public function grantsForClientForwardsWithOfflineValidity(
        int $clientId,
        string $deviceId,
        array $forwards,
        bool $policyV2,
        int $offlineValiditySeconds,
    ): array;
}
