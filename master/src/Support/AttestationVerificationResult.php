<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

final class AttestationVerificationResult
{
    private function __construct(
        public readonly bool $trusted,
        public readonly string $reason,
        public readonly ?string $identityKeyHash = null,
        public readonly ?string $attestationKeyHash = null,
    ) {
    }

    public static function trusted(string $identityKeyHash, string $attestationKeyHash): self
    {
        return new self(true, 'trusted', $identityKeyHash, $attestationKeyHash);
    }

    public static function rejected(string $reason): self
    {
        return new self(false, $reason);
    }
}
