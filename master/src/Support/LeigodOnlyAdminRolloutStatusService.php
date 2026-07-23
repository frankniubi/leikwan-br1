<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

final class LeigodOnlyAdminRolloutStatusService
{
    /** @param list<string> $wiredServices */
    public function __construct(private array $wiredServices = [])
    {
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    public function rolloutStatus(array $request): array
    {
        return [
            'ok' => true,
            'scope' => (string) ($request['scope'] ?? 'global'),
            'artifact_consistency' => ['ok' => true, 'blockers' => []],
            'canary_cohorts' => [],
            'production_services' => $this->wiredServices !== [],
            'wired_services' => $this->wiredServices,
            'mutating_services_enabled' => false,
        ];
    }
}
