<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use RuntimeException;

final class ApiException extends RuntimeException
{
    private int $statusCode;
    /** @var array<string, mixed> */
    private array $details;

    /**
     * @param array<string, mixed> $details
     */
    public function __construct(string $message, int $statusCode = 400, array $details = [])
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
        $this->details = $details;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }
}
