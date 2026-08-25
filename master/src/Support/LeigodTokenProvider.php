<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

interface LeigodTokenProvider
{
    /**
     * Issue or load short-lived Ultra capability grants backed by the LeiGod
     * account source for eligible forwards.
     *
     * @param array<int, array<string, mixed>> $forwards
     * @return array<string, array<string, mixed>> keyed by forward ID
     */
    public function grantsForClientForwards(int $clientId, string $deviceId, array $forwards, bool $policyV2 = false): array;
}
