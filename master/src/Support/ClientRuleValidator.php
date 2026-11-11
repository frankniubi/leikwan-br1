<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;

/**
 * Validates Client Rule writes (exact IPv4 destination + port -> Route Direction).
 * Error codes are the stable vocabulary the Android client maps to messages.
 */
final class ClientRuleValidator
{
    public function __construct(
        private Config $config,
        private Database $db,
    ) {
    }

    public function normalizeDestinationIp(mixed $value): string
    {
        $ip = trim((string) $value, " \t\n\r\0\x0B");
        if (str_contains($ip, ':')) {
            // A real IPv6 literal is a valid address of an unsupported family; anything
            // else with a colon (a URL, host:port) is simply not an address.
            throw new ApiException(
                filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
                    ? 'unsupported_destination_address_family'
                    : 'invalid_destination_ip',
                422
            );
        }
        $parts = explode('.', $ip);
        if (count($parts) !== 4) {
            throw new ApiException('invalid_destination_ip', 422);
        }
        $octets = [];
        foreach ($parts as $part) {
            if ($part === '' || !ctype_digit($part)) {
                throw new ApiException('invalid_destination_ip', 422);
            }
            $number = (int) $part;
            if ((string) $number !== $part || $number < 0 || $number > 255) {
                throw new ApiException('invalid_destination_ip', 422);
            }
            $octets[] = $number;
        }
        $canonical = implode('.', array_map('strval', $octets));
        if ($this->isBlockedDestination($octets)) {
            throw new ApiException('destination_blocked_by_policy', 422);
        }
        return $canonical;
    }

    public function normalizeDestinationPort(mixed $value): int
    {
        if (is_int($value)) {
            $port = $value;
        } else {
            $text = trim((string) $value);
            if ($text === '' || !ctype_digit($text)) {
                throw new ApiException('invalid_destination_port', 422);
            }
            $port = (int) $text;
        }
        if ($port < 1 || $port > 65535) {
            throw new ApiException('invalid_destination_port', 422);
        }
        return $port;
    }

    public function activeDuplicateKey(string $ip, int $port, bool $enabled): ?string
    {
        return $enabled ? $ip . ':' . $port . ':tcp_udp' : null;
    }

    public function validateWrite(array $context): array
    {
        if (empty($context['service_active'])) {
            throw new ApiException('service_not_active', 422);
        }
        if (array_key_exists('sync_version_matches', $context) && empty($context['sync_version_matches'])) {
            throw new ApiException('version_conflict', 409);
        }
        $ip = $this->normalizeDestinationIp($context['destination_ip'] ?? '');
        $port = $this->normalizeDestinationPort($context['destination_port'] ?? 0);
        if (empty($context['direction_exists']) || empty($context['direction_allowed'])) {
            throw new ApiException('direction_not_allowed', 422);
        }
        if ((string) ($context['direction_status'] ?? 'available') !== 'available') {
            throw new ApiException('direction_unavailable', 422);
        }
        if (!empty($context['enabled']) && !empty($context['duplicate_enabled_exists'])) {
            throw new ApiException('duplicate_destination', 409);
        }
        if ((int) ($context['saved_count_after_write'] ?? 0) > (int) ($context['max_rules'] ?? 0)) {
            throw new ApiException('rule_quota_exceeded', 422);
        }
        return [
            'destination_ip' => $ip,
            'destination_port' => $port,
            'protocol' => 'tcp_udp',
        ];
    }

    /** @param list<int> $octets */
    private function isBlockedDestination(array $octets): bool
    {
        [$a, $b] = [$octets[0], $octets[1]];
        if ($a === 0 || $a === 10 || $a === 127 || $a >= 224) {
            return true;
        }
        if ($a === 100 && $b >= 64 && $b <= 127) {
            return true;
        }
        if ($a === 169 && $b === 254) {
            return true;
        }
        if ($a === 172 && $b >= 16 && $b <= 31) {
            return true;
        }
        if ($a === 192 && ($b === 0 || $b === 168)) {
            return true;
        }
        if ($a === 198 && ($b === 18 || $b === 19 || $b === 51)) {
            return true;
        }
        if ($a === 203 && $b === 0) {
            return true;
        }
        return false;
    }
}
