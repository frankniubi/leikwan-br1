<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Http\Request;
use PDO;

/**
 * Brute-force limiter for the credential endpoints (password login, desktop
 * client login, Buy station login, password reset, Buy station binding).
 *
 * Unlike ApiRateLimiter this one is keyed by attacker-chosen identifiers
 * (emails, client IPs), so the counters live in the auth_rate_limits table
 * where expired rows are pruned, and the window length is per bucket. A
 * bucket is a fixed window that starts at its first hit and holds `limit`
 * attempts. Keys are stored as sha256 so the table holds no emails or IPs.
 *
 * hit() counts first and checks afterwards, so concurrent requests cannot all
 * slip past a check-then-count race. Callers refund() or reset() a bucket when
 * the attempt turns out to be legitimate (for example a successful login), so
 * the budgets effectively count failures.
 *
 * Storage errors fail open (logged): a broken counter must not lock every
 * tenant out of the panel.
 */
final class AuthRateLimiter
{
    // Password login (panel form, desktop/mobile client JSON, Buy station form).
    public const LOGIN_ACCOUNT_LIMIT = 10;
    public const LOGIN_IP_LIMIT = 50;
    public const LOGIN_WINDOW_SECONDS = 900;

    // Password reset mail.
    public const RESET_EMAIL_LIMIT = 3;
    public const RESET_IP_LIMIT = 20;
    public const RESET_WINDOW_SECONDS = 3600;

    // Buy station binding verification code submissions (per client IP; the
    // per-challenge cap lives on whmcs_login_challenges.failed_attempts).
    public const BINDING_VERIFY_IP_LIMIT = 30;
    public const BINDING_VERIFY_WINDOW_SECONDS = 900;

    // Buy station binding challenge creation.
    public const BINDING_REQUEST_USER_LIMIT = 5;
    public const BINDING_REQUEST_EMAIL_LIMIT = 5;
    public const BINDING_REQUEST_WINDOW_SECONDS = 3600;

    private const PRUNE_ONE_IN = 50;

    // bcrypt of a random string at the PASSWORD_DEFAULT cost of PHP < 8.4 (10)
    // and PHP >= 8.4 (12). verifyPassword() burns one verification against it
    // when an account has no usable hash.
    private const TIMING_HASH_COST_10 = '$2y$10$stlMaXbkxvKHEcvqdRxBo.yXsjHhcpKNe5tEHPDvJQ.83f26oKMCC';
    private const TIMING_HASH_COST_12 = '$2y$12$9aa339eVepcWNwSa1n4G3OmBX.nynZwnGqETn4SGYHeAAdVpojOye';

    private int $pruneOneIn;

    public function __construct(private Database $db, ?int $pruneOneIn = null)
    {
        $this->pruneOneIn = max(1, $pruneOneIn ?? self::PRUNE_ONE_IN);
    }

    /**
     * The client address used for the per-IP buckets. Reuses the panel's
     * trusted-proxy resolution (TRUSTED_PROXY_CIDRS), so forwarded headers
     * only count when the peer is a configured proxy. IPv6 clients are
     * bucketed by /64 because one subscriber usually owns the whole prefix.
     */
    public static function clientIp(Config $config, Request $request): string
    {
        $resolver = new TrustedClientIpResolver((string) ($config->get('TRUSTED_PROXY_CIDRS', '') ?? ''));
        $address = $resolver->resolve($request);
        if ($address === null) {
            return 'unknown';
        }

        $packed = @inet_pton($address);
        if ($packed !== false && strlen($packed) === 16) {
            return inet_ntop(substr($packed, 0, 8) . str_repeat("\x00", 8)) . '/64';
        }

        return $address;
    }

    /**
     * password_verify() that costs the same whether or not there is a stored
     * hash, so an unknown email or an account without a local password is
     * not distinguishable by response time.
     */
    public static function verifyPassword(string $password, mixed $storedHash): bool
    {
        if (is_string($storedHash) && $storedHash !== '') {
            return password_verify($password, $storedHash);
        }

        password_verify($password, PHP_VERSION_ID >= 80400 ? self::TIMING_HASH_COST_12 : self::TIMING_HASH_COST_10);
        return false;
    }

    /**
     * @return array<int, array{scope: string, id: string, limit: int, window: int}>
     */
    public static function loginBuckets(string $scope, string $ip, string $email): array
    {
        return [
            self::bucket($scope . ':account', $ip . '|' . strtolower(trim($email)), self::LOGIN_ACCOUNT_LIMIT, self::LOGIN_WINDOW_SECONDS),
            self::bucket($scope . ':ip', $ip, self::LOGIN_IP_LIMIT, self::LOGIN_WINDOW_SECONDS),
        ];
    }

    /**
     * @return array{scope: string, id: string, limit: int, window: int}
     */
    public static function bucket(string $scope, string $id, int $limit, int $windowSeconds): array
    {
        return ['scope' => $scope, 'id' => $id, 'limit' => $limit, 'window' => $windowSeconds];
    }

    /**
     * Count one attempt in every bucket and refuse it when any bucket now
     * holds more than its limit. Refused attempts are counted too, but they
     * never extend a window.
     *
     * @param array<int, array{scope: string, id: string, limit: int, window: int}> $buckets
     * @throws ApiException rate_limited (429) with retry_after seconds
     */
    public function hit(array $buckets, ?int $now = null): void
    {
        $now ??= time();
        $retryAfter = 0;
        try {
            $this->maybePrune($now);
            foreach ($buckets as $bucket) {
                if ((int) $bucket['limit'] <= 0) {
                    continue;
                }
                $row = $this->increment($this->key($bucket), max(1, (int) $bucket['window']), $now);
                if ($row !== null && (int) $row['hits'] > (int) $bucket['limit']) {
                    $retryAfter = max($retryAfter, (int) $row['expires_at'] - $now);
                }
            }
        } catch (\Throwable $throwable) {
            error_log('auth rate limiter unavailable: ' . $throwable::class);
            return;
        }

        if ($retryAfter > 0) {
            throw new ApiException('rate_limited', 429, ['retry_after' => $retryAfter]);
        }
    }

    /**
     * Give back the attempt hit() counted, e.g. after a successful login, so
     * a shared address is not throttled by its legitimate traffic.
     *
     * @param array<int, array{scope: string, id: string, limit: int, window: int}> $buckets
     */
    public function refund(array $buckets): void
    {
        try {
            foreach ($buckets as $bucket) {
                $this->db->execute(
                    'UPDATE auth_rate_limits SET hits = hits - 1 WHERE bucket_key = :bucket_key AND hits > 0',
                    ['bucket_key' => $this->key($bucket)]
                );
            }
        } catch (\Throwable $throwable) {
            error_log('auth rate limiter refund failed: ' . $throwable::class);
        }
    }

    /**
     * Forget the buckets entirely (successful login for this account).
     *
     * @param array<int, array{scope: string, id: string, limit: int, window: int}> $buckets
     */
    public function reset(array $buckets): void
    {
        try {
            foreach ($buckets as $bucket) {
                $this->db->execute(
                    'DELETE FROM auth_rate_limits WHERE bucket_key = :bucket_key',
                    ['bucket_key' => $this->key($bucket)]
                );
            }
        } catch (\Throwable $throwable) {
            error_log('auth rate limiter reset failed: ' . $throwable::class);
        }
    }

    /**
     * @param array{scope: string, id: string, limit: int, window: int} $bucket
     */
    private function key(array $bucket): string
    {
        return hash('sha256', (string) $bucket['scope'] . "\0" . (string) $bucket['id']);
    }

    /**
     * Atomic upsert: a missing or expired row restarts the window at 1 hit,
     * otherwise the hit count grows. Every SET expression reads the old row in
     * SQLite; MySQL reads assignments left to right, so expires_at goes last
     * and the two earlier CASEs still see the old value there too.
     *
     * @return array<string, mixed>|null
     */
    private function increment(string $key, int $windowSeconds, int $now): ?array
    {
        $conflict = $this->driver() === 'sqlite'
            ? 'ON CONFLICT(bucket_key) DO UPDATE SET'
            : 'ON DUPLICATE KEY UPDATE';
        $this->db->execute(
            'INSERT INTO auth_rate_limits (bucket_key, window_started_at, hits, expires_at)
             VALUES (:bucket_key, :started, 1, :expires)
             ' . $conflict . '
                 hits = CASE WHEN expires_at <= :now_hits THEN 1 ELSE hits + 1 END,
                 window_started_at = CASE WHEN expires_at <= :now_start THEN :restarted ELSE window_started_at END,
                 expires_at = CASE WHEN expires_at <= :now_expiry THEN :new_expiry ELSE expires_at END',
            [
                'bucket_key' => $key,
                'started' => $now,
                'expires' => $now + $windowSeconds,
                'now_hits' => $now,
                'now_start' => $now,
                'restarted' => $now,
                'now_expiry' => $now,
                'new_expiry' => $now + $windowSeconds,
            ]
        );

        return $this->db->fetchOne(
            'SELECT hits, expires_at FROM auth_rate_limits WHERE bucket_key = :bucket_key LIMIT 1',
            ['bucket_key' => $key]
        );
    }

    private function maybePrune(int $now): void
    {
        if ($this->pruneOneIn > 1 && random_int(1, $this->pruneOneIn) !== 1) {
            return;
        }
        $this->db->execute('DELETE FROM auth_rate_limits WHERE expires_at <= :now', ['now' => $now]);
    }

    private function driver(): string
    {
        return strtolower((string) $this->db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME));
    }
}
