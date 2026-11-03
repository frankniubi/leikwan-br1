<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

/**
 * Per-combination allowlist of forward target networks.
 *
 * A combination that stores a non-empty allowlist only accepts forwards whose
 * target is an IP literal inside one of the listed CIDRs (for example an IX
 * route such as SHIXP, whose line can only reach member networks). An empty
 * allowlist means "no restriction", which keeps every existing combination
 * unchanged.
 *
 * Stored form: one canonical CIDR per line, deduplicated, network-aligned.
 */
final class ForwardTargetAllowlist
{
    public const MAX_ENTRIES = 4096;

    /**
     * Parse operator input (newline, comma or whitespace separated; `#` starts
     * a comment) into the canonical stored text. Returns null for "no list".
     *
     * @throws ApiException on an invalid entry or too many entries
     */
    public static function normalize(mixed $raw): ?string
    {
        if (is_array($raw)) {
            $raw = implode("\n", array_map(static fn(mixed $value): string => is_scalar($value) ? (string) $value : '', $raw));
        }
        $text = is_scalar($raw) ? (string) $raw : '';
        $entries = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));
            if ($line === '') {
                continue;
            }
            foreach (preg_split('/[\s,;]+/', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                $canonical = self::canonicalCidr($token);
                if ($canonical === null) {
                    throw new ApiException('invalid_forward_target_allowlist', 422, [
                        'entry' => substr($token, 0, 64),
                        'message' => 'Invalid CIDR in target allowlist: ' . substr($token, 0, 64),
                    ]);
                }
                $entries[$canonical] = true;
                if (count($entries) > self::MAX_ENTRIES) {
                    throw new ApiException('invalid_forward_target_allowlist', 422, [
                        'message' => 'Target allowlist exceeds ' . self::MAX_ENTRIES . ' entries.',
                    ]);
                }
            }
        }
        return $entries === [] ? null : implode("\n", array_keys($entries));
    }

    public static function isRestricted(mixed $stored): bool
    {
        return self::parse($stored) !== [];
    }

    public static function count(mixed $stored): int
    {
        return count(self::parse($stored));
    }

    /**
     * Whether $targetHost is allowed by the stored list. An unrestricted list
     * allows everything; a restricted list only allows IP literals it covers
     * (hostnames are refused because their address can change after the check).
     */
    public static function allows(mixed $stored, string $targetHost): bool
    {
        $networks = self::parse($stored);
        if ($networks === []) {
            return true;
        }
        $host = trim($targetHost);
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        $address = filter_var($host, FILTER_VALIDATE_IP) !== false ? @inet_pton($host) : false;
        if (!is_string($address)) {
            return false;
        }
        foreach ($networks as [$network, $prefix]) {
            if (strlen($network) === strlen($address) && self::prefixMatches($address, $network, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /** @return array<int, array{0:string,1:int}> */
    private static function parse(mixed $stored): array
    {
        $text = is_scalar($stored) ? trim((string) $stored) : '';
        if ($text === '') {
            return [];
        }
        $networks = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, '/')) {
                continue;
            }
            [$ip, $bits] = explode('/', $line, 2);
            $binary = @inet_pton($ip);
            if (!is_string($binary) || !ctype_digit($bits)) {
                continue;
            }
            $prefix = (int) $bits;
            if ($prefix > strlen($binary) * 8) {
                continue;
            }
            $networks[] = [$binary, $prefix];
        }
        return $networks;
    }

    private static function canonicalCidr(string $token): ?string
    {
        $token = trim($token);
        if (str_contains($token, '/')) {
            [$ip, $bits] = explode('/', $token, 2);
        } else {
            $ip = $token;
            $bits = '';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $binary = @inet_pton($ip);
        if (!is_string($binary)) {
            return null;
        }
        $maxBits = strlen($binary) * 8;
        if ($bits === '') {
            $prefix = $maxBits;
        } elseif (ctype_digit($bits) && (int) $bits <= $maxBits) {
            $prefix = (int) $bits;
        } else {
            return null;
        }
        $network = self::maskAddress($binary, $prefix);
        $text = inet_ntop($network);
        return is_string($text) ? $text . '/' . $prefix : null;
    }

    private static function maskAddress(string $binary, int $prefix): string
    {
        $out = '';
        $length = strlen($binary);
        for ($i = 0; $i < $length; $i++) {
            $bits = max(0, min(8, $prefix - $i * 8));
            $mask = $bits === 0 ? 0 : (0xFF << (8 - $bits)) & 0xFF;
            $out .= chr(ord($binary[$i]) & $mask);
        }
        return $out;
    }

    private static function prefixMatches(string $address, string $network, int $prefix): bool
    {
        return self::maskAddress($address, $prefix) === self::maskAddress($network, $prefix);
    }
}
