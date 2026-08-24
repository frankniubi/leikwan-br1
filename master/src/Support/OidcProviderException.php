<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use RuntimeException;
use Throwable;

final class OidcProviderException extends RuntimeException
{
    /** @var array<string, int|string> */
    private array $providerContext;

    /** @param array<string, int|string> $providerContext */
    public function __construct(string $stage, array $providerContext = [], ?Throwable $previous = null)
    {
        parent::__construct($stage, 0, $previous);
        $this->providerContext = $providerContext;
    }

    /** @return array<string, int|string> */
    public function context(): array
    {
        return $this->providerContext;
    }
}
