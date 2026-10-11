<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
use Throwable;

/**
 * Alerts when an Ultra source stops refreshing line material.
 *
 * Stolen LeiGod credentials die only because the source agent re-logs in and
 * re-acquires material (measured 2026-10-02/03: an old credential is refused
 * ~8 min after rotation, and day-old ones are dead). If the source host stops
 * uploading, nothing rotates and the last credentials stay valid for as long
 * as it is down, so a stalled source must be noticed quickly.
 *
 * Watched: active sources that uploaded material within the last 24 h (an
 * idle secondary source is not watched), plus any source named in
 * ULTRA_SOURCE_ALERT_SOURCES. A source is stale once its last upload is older
 * than ULTRA_SOURCE_STALE_MINUTES (default 10). One alert on the transition,
 * a reminder every ULTRA_SOURCE_ALERT_REPEAT_MINUTES (default 60) while it
 * stays stale, and one recovery notice. State lives in a small JSON file so
 * the cron job stays stateless.
 */
final class UltraSourceWatchdog
{
    public const STALE_MINUTES_KEY = 'ULTRA_SOURCE_STALE_MINUTES';
    public const REPEAT_MINUTES_KEY = 'ULTRA_SOURCE_ALERT_REPEAT_MINUTES';
    public const RECIPIENTS_KEY = 'ULTRA_SOURCE_ALERT_EMAILS';
    public const EXTRA_SOURCES_KEY = 'ULTRA_SOURCE_ALERT_SOURCES';

    /** @var callable(string,string,string):void (to, subject, text) */
    private $send;

    /** @var callable():int */
    private $clock;

    public function __construct(
        private Config $config,
        private Database $db,
        private string $statePath,
        callable $send,
        ?callable $clock = null,
    ) {
        $this->send = $send;
        $this->clock = $clock ?? static fn(): int => time();
    }

    /**
     * One check. Returns a summary for the cron log.
     *
     * @return array{stale: list<string>, alerted: list<string>, recovered: list<string>, watched: list<string>}
     */
    public function run(): array
    {
        $now = ($this->clock)();
        $staleSeconds = 60 * max(2, min(1440, $this->config->getInt(self::STALE_MINUTES_KEY, 10)));
        $repeatSeconds = 60 * max(10, min(1440, $this->config->getInt(self::REPEAT_MINUTES_KEY, 60)));
        $extra = array_filter(array_map('trim', explode(',', (string) $this->config->get(self::EXTRA_SOURCES_KEY, ''))));

        $rows = $this->db->fetchAll(
            'SELECT source_id, is_active, last_seen_at, last_material_upload_at, last_error_code FROM ultra_sources'
        );
        $freshLines = (int) (($this->db->fetchOne(
            'SELECT COUNT(*) AS total FROM ultra_lines WHERE last_material_expires_at > :now',
            ['now' => gmdate('Y-m-d H:i:s', $now)]
        ) ?? [])['total'] ?? 0);

        $state = $this->loadState();
        $summary = ['stale' => [], 'alerted' => [], 'recovered' => [], 'watched' => []];
        foreach ($rows as $row) {
            $sourceId = (string) ($row['source_id'] ?? '');
            $uploadAt = $this->timestamp($row['last_material_upload_at'] ?? null);
            $seenAt = $this->timestamp($row['last_seen_at'] ?? null);
            $watched = (int) ($row['is_active'] ?? 0) === 1
                && ($uploadAt !== null && $uploadAt > $now - 86400 || in_array($sourceId, $extra, true));
            if (!$watched) {
                continue;
            }
            $summary['watched'][] = $sourceId;
            $entry = is_array($state[$sourceId] ?? null) ? $state[$sourceId] : [];
            $stale = $uploadAt === null || $now - $uploadAt > $staleSeconds;
            if ($stale) {
                $summary['stale'][] = $sourceId;
                $lastAlert = (int) ($entry['alerted_at'] ?? 0);
                if (!($entry['stale'] ?? false) || $now - $lastAlert >= $repeatSeconds) {
                    $this->notify(
                        '[LeiKwan] Ultra 源停止刷新凭据: ' . $sourceId,
                        $this->staleText($sourceId, $now, $uploadAt, $seenAt, (string) ($row['last_error_code'] ?? ''), $freshLines, (bool) ($entry['stale'] ?? false))
                    );
                    $entry['alerted_at'] = $now;
                    $summary['alerted'][] = $sourceId;
                }
                $entry['stale'] = true;
                $entry['since'] = (int) ($entry['since'] ?? ($uploadAt ?? $now));
            } elseif ($entry['stale'] ?? false) {
                $this->notify(
                    '[LeiKwan] Ultra 源已恢复刷新: ' . $sourceId,
                    sprintf(
                        "源 %s 已恢复上传凭据（最近一次 %s UTC）。\n停止刷新约 %d 分钟。可用线路 %d 条。\n",
                        $sourceId,
                        gmdate('Y-m-d H:i:s', (int) $uploadAt),
                        intdiv(max(0, (int) $uploadAt - (int) ($entry['since'] ?? $uploadAt)), 60),
                        $freshLines
                    )
                );
                $entry = [];
                $summary['recovered'][] = $sourceId;
            }
            $state[$sourceId] = $entry;
        }
        $this->saveState($state);

        return $summary;
    }

    /** Send a test mail to the configured recipients; returns them. @return list<string> */
    public function sendTest(): array
    {
        $this->notify(
            '[LeiKwan] Ultra 源告警测试',
            "这是一封测试邮件：Ultra 源停止刷新告警已开启。\n源超过 10 分钟没有上传新凭据时会发这样的邮件。\n"
        );
        return $this->recipients();
    }

    private function staleText(string $sourceId, int $now, ?int $uploadAt, ?int $seenAt, string $error, int $freshLines, bool $reminder): string
    {
        $age = static fn(?int $at): string => $at === null ? '从未' : intdiv($now - $at, 60) . ' 分钟前';
        $hostAlive = $seenAt !== null && $now - $seenAt <= 300;
        return ($reminder ? "（提醒：仍未恢复）\n" : '')
            . sprintf("源 %s 已 %s 没有上传新的线路凭据。\n", $sourceId, $uploadAt === null ? '从未' : intdiv($now - $uploadAt, 60) . ' 分钟')
            . sprintf("最近上传：%s；最近心跳：%s。\n", $age($uploadAt), $age($seenAt))
            . ($hostAlive
                ? "源主机还在线，但拿不到新凭据：多半是雷神登录/会话出了问题。\n"
                : "源主机已失联：可能宕机、断网或源 agent 停止。\n")
            . ($error !== '' ? '最近错误码：' . $error . "\n" : '')
            . sprintf("当前仍有有效凭据的线路：%d 条。\n\n", $freshLines)
            . "为什么要紧：旧凭据只有在源主机重新取凭据时才会作废。源停止刷新期间，任何人手里的旧凭据都可能一直能用。\n"
            . "处理：检查源主机 10.10.10.171 上的 ultra-source-agent 服务和雷神登录状态。\n";
    }

    private function notify(string $subject, string $text): void
    {
        error_log('ultra_source_watchdog ' . $subject);
        foreach ($this->recipients() as $to) {
            try {
                ($this->send)($to, $subject, $text);
            } catch (Throwable $exception) {
                error_log('ultra_source_watchdog_mail_failed to=' . $to . ' ' . $exception->getMessage());
            }
        }
    }

    /** @return list<string> */
    private function recipients(): array
    {
        $configured = array_values(array_filter(
            array_map('trim', explode(',', (string) $this->config->get(self::RECIPIENTS_KEY, ''))),
            static fn(string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false
        ));
        if ($configured !== []) {
            return $configured;
        }
        try {
            $rows = $this->db->fetchAll("SELECT email FROM users WHERE role = 'admin' AND status = 'active'");
        } catch (Throwable) {
            return [];
        }
        return array_values(array_filter(
            array_map(static fn(array $row): string => trim((string) ($row['email'] ?? '')), $rows),
            static fn(string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false
        ));
    }

    private function timestamp(mixed $value): ?int
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        $at = strtotime($text . ' UTC');
        return $at === false ? null : $at;
    }

    /** @return array<string, mixed> */
    private function loadState(): array
    {
        $decoded = is_file($this->statePath) ? json_decode((string) @file_get_contents($this->statePath), true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $state */
    private function saveState(array $state): void
    {
        $dir = dirname($this->statePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $temp = $this->statePath . '.tmp';
        if (@file_put_contents($temp, json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false) {
            @rename($temp, $this->statePath);
        }
    }
}
