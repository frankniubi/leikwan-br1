<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Support/UptimeTrace.php';

use Leikwan\Master\Support\UptimeTrace;

$now = 1785000000; // fixed clock: the bucketing must not depend on wall time

// A heartbeat in every 5-minute slot of the last hour -> 12 present blocks.
$dense = [];
for ($i = 0; $i < 60; $i++) {
    $dense[] = $now - ($i * 60);
}
$buckets = UptimeTrace::buckets($dense, $now);
if (count($buckets) !== 12) {
    throw new RuntimeException('expected 12 blocks, got ' . count($buckets));
}
foreach ($buckets as $i => $b) {
    if ($b !== 'present') {
        throw new RuntimeException('dense heartbeats must be present at block ' . $i);
    }
}

// No heartbeats at all -> fully hatched, never silently "healthy".
$empty = UptimeTrace::buckets([], $now);
if (count(array_unique($empty)) !== 1 || $empty[0] !== 'gap') {
    throw new RuntimeException('an empty series must render as a gap, not as uptime');
}

// A real outage must land in the right blocks. Drop the window 30-40 min ago.
$holed = [];
for ($i = 0; $i < 60; $i++) {
    if ($i >= 30 && $i < 40) {
        continue;
    }
    $holed[] = $now - ($i * 60);
}
$hb = UptimeTrace::buckets($holed, $now);
// Blocks are oldest-first: block k covers [now-60min+5k, now-60min+5(k+1)).
// 30-40 minutes ago == blocks 4 and 5.
foreach ([4, 5] as $gapIndex) {
    if ($hb[$gapIndex] !== 'gap') {
        throw new RuntimeException('block ' . $gapIndex . ' should be a gap');
    }
}
foreach ([0, 3, 6, 11] as $okIndex) {
    if ($hb[$okIndex] !== 'present') {
        throw new RuntimeException('block ' . $okIndex . ' should be present');
    }
}

// Heartbeats older than the window must not leak in.
$stale = UptimeTrace::buckets([$now - 7200], $now);
if ($stale[0] !== 'gap' || $stale[11] !== 'gap') {
    throw new RuntimeException('heartbeats outside the window must be ignored');
}

// Accessibility: a screen reader must not meet an empty element.
$html = UptimeTrace::render($hb, 'ok', 'Node uptime');
if (!preg_match('/aria-label="[^"]+"/', $html)) {
    throw new RuntimeException('the trace must carry a non-empty aria-label');
}
// Blocks 4 and 5 are one contiguous ten-minute outage, not two. A bare digit
// check would be satisfied by the block count alone and would go vacuous the
// moment any other number entered the markup, so name both numbers.
if (!str_contains($html, '1 interruption in the last 60 minutes')) {
    throw new RuntimeException('a contiguous outage is one interruption: ' . $html);
}
if (!str_contains($html, '2 blocks of 5 minutes without heartbeats')) {
    throw new RuntimeException('the label must also state how long the node was silent: ' . $html);
}

// Two separate outages must not read identically to one long one -- that
// distinction is the reason to count runs rather than blocks.
$split = $hb;
$split[8] = 'gap';
$splitHtml = UptimeTrace::render($split, 'ok', 'Node uptime');
if (!str_contains($splitHtml, '2 interruptions in the last 60 minutes')) {
    throw new RuntimeException('separate outages must be counted separately: ' . $splitHtml);
}

// A clean hour must not be described with a count at all.
$healthy = UptimeTrace::render(array_fill(0, 12, 'present'), 'ok', 'Node uptime');
if (!str_contains($healthy, 'no interruptions in the last 60 minutes')) {
    throw new RuntimeException('a clean hour must read as no interruptions: ' . $healthy);
}
if (substr_count($html, 'pg-trace-block') !== 12) {
    throw new RuntimeException('the trace must render 12 blocks');
}
// The final block carries the current state.
if (!str_contains($html, 'data-current="ok"')) {
    throw new RuntimeException('the trace must mark the current state');
}

// No JS, no build step.
if (str_contains($html, '<script') || str_contains($html, 'onclick')) {
    throw new RuntimeException('the trace is server-rendered and must not ship JS');
}

echo "ok\n";
