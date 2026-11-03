<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Config;

/**
 * Per-key fixed-window request limiter for the tenant forward APIs.
 *
 * Each structural forward edit revokes and reconciles endpoints, so an
 * unthrottled script could churn provisioning tasks the way the June task
 * storms did. Counters live in small flock()ed files: the panel is a single
 * host, and this needs no table or migration. Like the Ultra lease limits it
 * fails open, so a limiter that cannot read or write its counter never blocks
 * a paying tenant.
 */
final class ApiRateLimiter
{
    private const WINDOW_SECONDS = 60;

    public function __construct(private string $directory)
    {
    }

    public static function fromConfig(Config $config): self
    {
        $directory = trim((string) $config->get('FORWARD_API_RATE_LIMIT_DIR', ''));
        if ($directory === '') {
            $directory = rtrim(sys_get_temp_dir(), '/\\') . '/leikwan-api-rate';
        }
        return new self($directory);
    }

    /**
     * Count one request against $key and refuse it once the window holds
     * $limitPerMinute requests. A limit of 0 or less disables the key.
     *
     * @throws ApiException rate_limited (429) with retry_after seconds
     */
    public function hit(string $key, int $limitPerMinute, ?int $now = null): void
    {
        if ($limitPerMinute <= 0) {
            return;
        }
        $now ??= time();
        $window = intdiv($now, self::WINDOW_SECONDS);
        $count = $this->increment($key, $window);
        if ($count !== null && $count > $limitPerMinute) {
            throw new ApiException('rate_limited', 429, [
                'retry_after' => max(1, ($window + 1) * self::WINDOW_SECONDS - $now),
            ]);
        }
    }

    private function increment(string $key, int $window): ?int
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            return null;
        }
        $handle = @fopen($this->directory . '/' . hash('sha256', $key), 'c+');
        if ($handle === false) {
            return null;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return null;
            }
            $parts = explode(' ', trim((string) stream_get_contents($handle)));
            $count = (int) ($parts[0] ?? -1) === $window ? (int) ($parts[1] ?? 0) + 1 : 1;
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $window . ' ' . $count);
            fflush($handle);
            flock($handle, LOCK_UN);
            return $count;
        } finally {
            fclose($handle);
        }
    }
}
