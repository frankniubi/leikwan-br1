<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

require_once __DIR__ . '/LeigodOnlyRuntimeContract.php';

final class LeigodOnlyRuntimeContractService
{
    /** @param array<string,mixed> $row @return array<string,mixed> */
    public function assertActiveContract(array $row, string $context): array
    {
        LeigodOnlyRuntimeContract::assertActiveContract($row, $context);
        return ['ok' => true, 'blockers' => []];
    }
}
