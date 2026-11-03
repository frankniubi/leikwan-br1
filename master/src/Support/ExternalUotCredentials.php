<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
use Throwable;

/**
 * Per-forward external UoT sidecar credentials (contract C2/C3 of
 * docs/plans/2026-10-21-external-uot-sidecar-enforcement.md).
 *
 * The sidecar is given the combination's external_uot_hmac_secret per forward;
 * the client is given hmac-sha256(secret, "<forward_id>\n<target>"), so a
 * token only ever opens its own forward and target, and removing the forward
 * from the sidecar config revokes it.
 */
final class ExternalUotCredentials
{
    public const TOKEN_PREFIX = 'hmac-sha256:';
    public const ACCEPT_STATIC_TOKEN_KEY = 'EXTERNAL_UOT_ACCEPT_STATIC_TOKEN';
    public const ISSUE_HMAC_TOKENS_KEY = 'EXTERNAL_UOT_ISSUE_HMAC_TOKENS';
    private const ASCII_SPACE = " \t\n\x0B\f\r";

    /** While true, sidecar entries still carry the static combination token. */
    public static function acceptStaticToken(Config $config): bool
    {
        return self::flag($config, self::ACCEPT_STATIC_TOKEN_KEY, 'true');
    }

    /**
     * Whether clients are handed per-forward HMAC tokens. Off by default: a
     * sidecar only accepts them once it holds the secrets, so this is switched
     * on after every sidecar serving a UoT combination runs the new merge
     * script or pool agent.
     */
    public static function issueHmacTokens(Config $config): bool
    {
        return self::flag($config, self::ISSUE_HMAC_TOKENS_KEY, 'false');
    }

    private static function flag(Config $config, string $key, string $default): bool
    {
        $raw = strtolower(trim((string) ($config->get($key, $default) ?? $default)));

        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Returns the combination's HMAC secret, generating and storing it once if
     * it has none. Concurrent first renders agree on whichever write landed
     * first. Returns '' when the secret cannot be stored (for example before
     * the migration ran); callers then fall back to the static token.
     *
     * @param array<string, mixed> $combination needs id and external_uot_hmac_secret
     */
    public static function ensureSecret(Database $db, array $combination): string
    {
        $current = trim((string) ($combination['external_uot_hmac_secret'] ?? ''));
        if ($current !== '') {
            return $current;
        }
        $combinationId = (int) ($combination['id'] ?? 0);
        if ($combinationId <= 0) {
            return '';
        }
        try {
            $db->execute(
                'UPDATE forward_combinations SET external_uot_hmac_secret = :secret
                 WHERE id = :id AND (external_uot_hmac_secret IS NULL OR external_uot_hmac_secret = :empty)',
                ['secret' => bin2hex(random_bytes(32)), 'id' => $combinationId, 'empty' => '']
            );
            $row = $db->fetchOne(
                'SELECT external_uot_hmac_secret FROM forward_combinations WHERE id = :id LIMIT 1',
                ['id' => $combinationId]
            );
        } catch (Throwable $exception) {
            error_log('external-uot: cannot store the HMAC secret of combination ' . $combinationId . ': ' . $exception->getMessage());

            return '';
        }

        return trim((string) ($row['external_uot_hmac_secret'] ?? ''));
    }

    /**
     * "hmac-sha256:" + hex(HMAC_SHA256(secret, "<forward_id>\n<normalized target>")),
     * or null when the target cannot be normalized exactly like the sidecar does.
     */
    public static function token(string $secret, int $forwardId, string $target): ?string
    {
        $normalized = self::normalizeTarget($target);
        if ($secret === '' || $forwardId <= 0 || $normalized === null) {
            return null;
        }

        return self::TOKEN_PREFIX . hash_hmac('sha256', $forwardId . "\n" . $normalized, $secret);
    }

    /**
     * Byte-for-byte port of tools/uot-sidecar/protocol.go::normalizeTarget:
     * "host:port" with a lower-cased host, Go netip.Addr.String() for IPs and
     * no brackets around IPv6. Non-ASCII input returns null because Go's
     * Unicode-aware trimming and lower-casing cannot be reproduced without
     * mbstring.
     */
    public static function normalizeTarget(string $value): ?string
    {
        if (preg_match('/[\x80-\xFF]/', $value) === 1) {
            return null;
        }
        $text = trim($value, self::ASCII_SPACE);
        if (str_starts_with($text, '[')) {
            $end = strpos($text, ']');
            if ($end === false || $end + 1 >= strlen($text) || $text[$end + 1] !== ':') {
                return null;
            }
            $host = substr($text, 1, $end - 1);
            $portText = substr($text, $end + 2);
        } else {
            $separator = strrpos($text, ':');
            if ($separator === false) {
                return null;
            }
            $host = substr($text, 0, $separator);
            $portText = substr($text, $separator + 1);
        }
        $host = strtolower(trim($host, self::ASCII_SPACE));
        if ($host === '' || strpbrk($host, self::ASCII_SPACE) !== false) {
            return null;
        }
        $port = self::atoiPort(trim($portText, self::ASCII_SPACE));
        if ($port === null) {
            return null;
        }
        $canonical = self::canonicalIp($host);

        return ($canonical ?? $host) . ':' . $port;
    }

    /** strconv.Atoi limited to 1..65535. */
    private static function atoiPort(string $text): ?int
    {
        if (preg_match('/^([+-]?)([0-9]+)$/', $text, $match) !== 1) {
            return null;
        }
        $digits = ltrim($match[2], '0');
        if ($digits === '' || $match[1] === '-' || strlen($digits) > 5) {
            return null;
        }
        $port = (int) $digits;

        return $port >= 1 && $port <= 65535 ? $port : null;
    }

    /** netip.ParseAddr(host).String(), or null when host is not an IP. */
    private static function canonicalIp(string $host): ?string
    {
        $special = strcspn($host, '.:%');
        if ($special >= strlen($host)) {
            return null;
        }
        if ($host[$special] === '.') {
            return self::parseIpv4($host) === null ? null : $host;
        }
        if ($host[$special] !== ':') {
            return null;
        }
        $zone = '';
        $address = $host;
        $percent = strpos($host, '%');
        if ($percent !== false) {
            $zone = substr($host, $percent + 1);
            $address = substr($host, 0, $percent);
            if ($zone === '') {
                return null;
            }
        }
        if (preg_match('/^[0-9a-f:.]+$/', $address) !== 1) {
            return null;
        }
        $lastColon = strrpos($address, ':');
        if (str_contains(substr($address, (int) $lastColon), '.')
            && self::parseIpv4(substr($address, (int) $lastColon + 1)) === null) {
            return null;
        }
        $packed = @inet_pton($address);
        if (!is_string($packed) || strlen($packed) !== 16) {
            return null;
        }
        $zoneSuffix = $zone !== '' ? '%' . $zone : '';
        if (str_starts_with($packed, str_repeat("\0", 10) . "\xFF\xFF")) {
            return '::ffff:' . implode('.', array_map('ord', str_split(substr($packed, 12)))) . $zoneSuffix;
        }
        $words = array_values(unpack('n8', $packed));
        $zeroStart = 255;
        $zeroEnd = 255;
        for ($i = 0; $i < 8; $i++) {
            $j = $i;
            while ($j < 8 && $words[$j] === 0) {
                $j++;
            }
            if ($j - $i >= 2 && $j - $i > $zeroEnd - $zeroStart) {
                $zeroStart = $i;
                $zeroEnd = $j;
            }
        }
        $text = '';
        for ($i = 0; $i < 8; $i++) {
            if ($i === $zeroStart) {
                $text .= '::';
                $i = $zeroEnd;
                if ($i >= 8) {
                    break;
                }
            } elseif ($i > 0) {
                $text .= ':';
            }
            $text .= dechex($words[$i]);
        }

        return $text . $zoneSuffix;
    }

    /** Go's strict dotted-quad parser: four fields, no leading zeros. */
    private static function parseIpv4(string $text): ?string
    {
        if (preg_match('/^(0|[1-9][0-9]{0,2})\.(0|[1-9][0-9]{0,2})\.(0|[1-9][0-9]{0,2})\.(0|[1-9][0-9]{0,2})$/', $text, $match) !== 1) {
            return null;
        }
        for ($i = 1; $i <= 4; $i++) {
            if ((int) $match[$i] > 255) {
                return null;
            }
        }

        return $text;
    }
}
