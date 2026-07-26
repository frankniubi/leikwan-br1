<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

/**
 * Server-rendered uptime trace.
 *
 * Backed by node_heartbeats, which is dense, indexed on (node_id, created_at),
 * retained 24h, and independent of backend_mode -- unlike
 * bond_leg_reputation_events, which only the bond binary populates and which is
 * therefore empty for leigod_mode and true-MPTCP forwards.
 *
 * Granularity is node-level, not leg-level. The UI says so rather than implying
 * more precision than the data has.
 *
 * Pure functions: no DB, no clock, no I/O. The caller supplies both.
 */
final class UptimeTrace
{
    public const BLOCKS = 12;
    public const WINDOW_SECONDS = 3600;

    /**
     * Bucket heartbeat timestamps into fixed blocks, oldest first.
     *
     * Blocks are anchored on $nowTs and counted backwards, so block k covers the
     * ages [5*(BLOCKS-1-k), 5*(BLOCKS-k)) minutes -- equivalently the half-open
     * span of timestamps (nowTs - 5*(BLOCKS-k)min, nowTs - 5*(BLOCKS-1-k)min].
     * The newest block therefore always contains a heartbeat landing exactly on
     * $nowTs, and a heartbeat exactly WINDOW_SECONDS old has already aged out.
     *
     * @param array<int, int> $timestamps unix seconds
     * @return array<int, string> 'present' | 'gap'
     */
    public static function buckets(array $timestamps, int $nowTs): array
    {
        $span = intdiv(self::WINDOW_SECONDS, self::BLOCKS);

        $buckets = array_fill(0, self::BLOCKS, 'gap');
        foreach ($timestamps as $ts) {
            $age = $nowTs - (int) $ts;
            if ($age < 0 || $age >= self::WINDOW_SECONDS) {
                continue;
            }
            $buckets[self::BLOCKS - 1 - intdiv($age, $span)] = 'present';
        }

        return $buckets;
    }

    /**
     * @param array<int, string> $buckets oldest first, as returned by buckets()
     */
    public static function render(array $buckets, string $currentState, string $label): string
    {
        // An interruption is a run of consecutive gap blocks: one ten-minute
        // outage is one interruption, not two. The number of gap blocks is
        // reported alongside it so the label carries the extent as well.
        $interruptions = 0;
        $gapBlocks = 0;
        $inGap = false;
        foreach ($buckets as $b) {
            if ($b === 'gap') {
                $gapBlocks++;
                if (!$inGap) {
                    $interruptions++;
                    $inGap = true;
                }
                continue;
            }
            $inGap = false;
        }

        $windowMinutes = intdiv(self::WINDOW_SECONDS, 60);
        $blockMinutes = intdiv(self::WINDOW_SECONDS, self::BLOCKS * 60);

        $summary = $interruptions === 0
            ? sprintf('%s: no interruptions in the last %d minutes', $label, $windowMinutes)
            : sprintf(
                '%s: %d interruption%s in the last %d minutes, %d block%s of %d minutes without heartbeats',
                $label,
                $interruptions,
                $interruptions === 1 ? '' : 's',
                $windowMinutes,
                $gapBlocks,
                $gapBlocks === 1 ? '' : 's',
                $blockMinutes
            );

        $html = '<span class="pg-trace" role="img" aria-label="' . self::escape($summary)
            . '" data-current="' . self::escape($currentState) . '">';

        $last = count($buckets) - 1;
        foreach ($buckets as $i => $b) {
            $html .= '<i class="pg-trace-block" data-fill="' . self::escape($b) . '"'
                . ($i === $last ? ' data-tip="current"' : '') . '></i>';
        }

        return $html . '</span>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
