<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

interface DeviceAttestationVerifier
{
    /**
     * @param array<string, mixed> $proof
     */
    public function verify(array $proof, string $nonce, int $clientId, string $deviceId): AttestationVerificationResult;
}
