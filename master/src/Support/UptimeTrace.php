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
    /**
     * WINDOW_SECONDS must stay an exact multiple of BLOCKS * 60. Neither
     * constant is derived from the other, and an uneven split fails only
     * silently: buckets() computes a negative index for the oldest heartbeat and
     * appends an extra element instead of filling a block, render() then finds no
     * key equal to count($buckets) - 1 and so emits no current-state marker at
     * all, and $blockMinutes truncates, telling a screen reader a block width the
     * blocks do not have. The arity guard in render() is what turns a violation
     * into a thrown error rather than a plausible-looking trace.
     */
    public const BLOCKS = 12;
    public const WINDOW_SECONDS = 3600;

    /**
     * Compose buckets() and render(): raw heartbeat timestamps in, markup out.
     *
     * This is the signature call sites use. Every one of them wants both halves
     * and none inspects the intermediate array; the halves stay public only so
     * the test can assert the bucketing without parsing HTML.
     *
     * @param array<int, int> $timestamps unix seconds
     */
    public static function html(array $timestamps, int $nowTs, string $currentState, string $label): string
    {
        return self::render(self::buckets($timestamps, $nowTs), $currentState, $label);
    }

    /**
     * Bucket heartbeat timestamps into fixed blocks, oldest first.
     *
     * Blocks are anchored on $nowTs and counted backwards over spans of
     * WINDOW_SECONDS / BLOCKS, so block k covers the ages
     * [span*(BLOCKS-1-k), span*(BLOCKS-k)) -- equivalently the half-open span of
     * timestamps (nowTs - span*(BLOCKS-k), nowTs - span*(BLOCKS-1-k)]. The newest
     * block therefore always contains a heartbeat landing exactly on $nowTs, and
     * a heartbeat exactly WINDOW_SECONDS old has already aged out.
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
     * @param array<int, string> $buckets oldest first, exactly BLOCKS of them, as
     *     returned by buckets(). The length is checked rather than tolerated: the
     *     block count comes from the array but the window and block widths in the
     *     label come from the constants, so a six-element array would render as
     *     "6 blocks of 5 minutes" inside "the last 60 minutes" -- half an hour
     *     described as an hour, with nothing to notice.
     * @param string $currentState 'ok' | 'warn' | 'down', a closed vocabulary:
     *     the stylesheet colours the newest block off [data-current] and has rules
     *     for those three values only. A raw nodes.status ('provisioning',
     *     'deleting', ...) matches no rule, so the block keeps its plain fill and
     *     a node in trouble reads as healthy.
     * @param string $label prefixed to the aria-label, e.g. 'Node uptime'.
     * @return string one <span> element; every interpolated value is escaped.
     * @throws \InvalidArgumentException when $buckets is not BLOCKS long.
     */
    public static function render(array $buckets, string $currentState, string $label): string
    {
        if (count($buckets) !== self::BLOCKS) {
            throw new \InvalidArgumentException(
                'UptimeTrace::render expects ' . self::BLOCKS . ' buckets, got ' . count($buckets)
            );
        }

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
