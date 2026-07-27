<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use RuntimeException;

final class OidcSessionStore
{
    private const SESSION_KEY = 'oidc_flows';
    private const TTL_SECONDS = 600;
    private const MAX_FLOWS = 8;

    /** @var callable():int */
    private $clock;

    /** @param callable():int|null $clock */
    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock ?? static fn(): int => time();
    }

    /**
     * @return array{state:string, nonce:string, return_to:string, expires_at:int, code_verifier?:string, code_challenge?:string}
     */
    public function create(string $returnTo, bool $usePkce): array
    {
        $this->ensureSession();
        $now = $this->now();
        $flows = $this->unexpiredFlows($now);

        $flow = [
            'state' => $this->randomUrlSafe(32),
            'nonce' => $this->randomUrlSafe(32),
            'return_to' => $this->localReturnTo($returnTo),
            'expires_at' => $now + self::TTL_SECONDS,
        ];
        if ($usePkce) {
            $flow['code_verifier'] = $this->randomUrlSafe(48);
            $flow['code_challenge'] = $this->base64UrlEncode(hash('sha256', $flow['code_verifier'], true));
        }

        $flows[$flow['state']] = $flow;
        while (count($flows) > self::MAX_FLOWS) {
            array_shift($flows);
        }
        $_SESSION[self::SESSION_KEY] = $flows;

        return $flow;
    }

    /**
     * Consume the flow before any token exchange so a callback state can never be replayed.
     *
     * @return array{state:string, nonce:string, return_to:string, expires_at:int, code_verifier?:string, code_challenge?:string}
     */
    public function consume(string $state): array
    {
        $this->ensureSession();
        $flows = $this->unexpiredFlows($this->now());
        $flow = $flows[$state] ?? null;
        unset($flows[$state]);
        $_SESSION[self::SESSION_KEY] = $flows;

        if (!is_array($flow) || !hash_equals((string) ($flow['state'] ?? ''), $state)) {
            throw new RuntimeException('oidc_state_invalid');
        }

        return $flow;
    }

    private function ensureSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE && !session_start()) {
            throw new RuntimeException('oidc_session_unavailable');
        }
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            $_SESSION = [];
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function unexpiredFlows(int $now): array
    {
        $stored = $_SESSION[self::SESSION_KEY] ?? [];
        if (!is_array($stored)) {
            return [];
        }

        $flows = [];
        foreach ($stored as $state => $flow) {
            if (!is_string($state) || !is_array($flow) || !isset($flow['expires_at']) || (int) $flow['expires_at'] <= $now) {
                continue;
            }
            $flows[$state] = $flow;
        }
        return $flows;
    }

    private function localReturnTo(string $returnTo): string
    {
        $decoded = rawurldecode($returnTo);
        if ($returnTo === '' || !str_starts_with($returnTo, '/') || str_starts_with($decoded, '//') || str_contains($decoded, '\\')) {
            return '/';
        }
        return $returnTo;
    }

    private function randomUrlSafe(int $bytes): string
    {
        return $this->base64UrlEncode(random_bytes($bytes));
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function now(): int
    {
        return (int) ($this->clock)();
    }
}
