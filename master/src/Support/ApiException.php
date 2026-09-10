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

    /**
     * Return only fields that are safe for the distribution-neutral client
     * error envelope. Internal callers may still use details() for server
     * side decisions; public responders must use this whitelist.
     *
     * @return array<string, mixed>
     */
    public function safeDetails(): array
    {
        $safe = [];
        foreach ([
            'challenge_token', 'challenge_expires_at', 'attempt_id',
            'required_collection_mode', 'retry_after_seconds', 'blocked_until',
            'next_location_check_at', 'matched_entity_type', 'violation_count',
            'window_seconds', 'temporary_block_seconds',
        ] as $field) {
            if (array_key_exists($field, $this->details)) {
                $safe[$field] = $this->details[$field];
            }
        }

        $challenge = $this->details['challenge'] ?? null;
        if (is_array($challenge)) {
            $safe['challenge'] = [];
            foreach (['token', 'expires_at', 'attempt_id'] as $field) {
                if (array_key_exists($field, $challenge)) {
                    $safe['challenge'][$field] = $challenge[$field];
                }
            }
        }

        $command = $this->details['command'] ?? null;
        if (is_array($command)) {
            $safe['command'] = [];
            foreach (['version', 'action', 'reason'] as $field) {
                if (array_key_exists($field, $command)) {
                    $safe['command'][$field] = $command[$field];
                }
            }
        }

        return $safe;
    }
}
