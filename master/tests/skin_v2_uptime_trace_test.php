<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Support/UptimeTrace.php';

use Leikwan\Master\Support\UptimeTrace;

$now = 1785000000; // fixed clock: the bucketing must not depend on wall time

// The two constants are coupled, and an uneven split has no honest failure mode:
// the oldest heartbeat indexes outside the array, the current-state marker
// disappears, and the label's block width truncates. Assert the constraint here,
// first, because every one of those symptoms reads as an unrelated bug.
if (UptimeTrace::WINDOW_SECONDS % (UptimeTrace::BLOCKS * 60) !== 0) {
    throw new RuntimeException(
        'WINDOW_SECONDS must be an exact multiple of BLOCKS * 60, got '
        . UptimeTrace::WINDOW_SECONDS . ' over ' . UptimeTrace::BLOCKS . ' blocks'
    );
}

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
    throw new RuntimeException('an empty series must render as a gap, not as uptime, got ' . implode(',', $empty));
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
// Blocks are oldest-first but anchored on now, not on the start of the window:
// block k holds heartbeats aged [5(11-k), 5(12-k)) minutes, so block 11 is the
// most recent five minutes and block 0 the oldest. The newest edge is inclusive
// and the oldest exclusive, which is why the heartbeat at exactly 40 minutes old
// lands in block 3 and leaves 30-40 minutes ago as blocks 4 and 5.
//
// Anchoring the other way -- counting forward from now-60min -- puts that same
// heartbeat on block 4's left edge and marks it present, so only block 5 comes
// out a gap. That was the original bug here.
foreach ([4, 5] as $gapIndex) {
    if ($hb[$gapIndex] !== 'gap') {
        throw new RuntimeException('block ' . $gapIndex . ' should be a gap in ' . implode(',', $hb));
    }
}
foreach ([0, 3, 6, 11] as $okIndex) {
    if ($hb[$okIndex] !== 'present') {
        throw new RuntimeException('block ' . $okIndex . ' should be present in ' . implode(',', $hb));
    }
}

// Heartbeats older than the window must not leak in. The assertion is on the
// arity, not on the two endpoints: a 7200s-old heartbeat computes index -13,
// which PHP appends as a new key instead of overwriting block 0 or 11, so an
// endpoint check stays green while the array grows to 13 elements.
$stale = UptimeTrace::buckets([$now - 7200], $now);
if (count($stale) !== 12 || count(array_unique($stale)) !== 1 || $stale[0] !== 'gap') {
    throw new RuntimeException('heartbeats outside the window must be ignored, got ' . implode(',', $stale));
}
// Timestamps ahead of the clock take the same guard and fail the same way, off
// the other end of the array. Node/panel skew makes them reachable.
$future = UptimeTrace::buckets([$now + 600], $now);
if (count($future) !== 12 || count(array_unique($future)) !== 1 || $future[0] !== 'gap') {
    throw new RuntimeException('heartbeats ahead of the clock must be ignored, got ' . implode(',', $future));
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
    throw new RuntimeException('the trace must render 12 blocks, got ' . substr_count($html, 'pg-trace-block'));
}
// The final block carries the current state.
if (!str_contains($html, 'data-current="ok"')) {
    throw new RuntimeException('the trace must mark the current state: ' . $html);
}

// html() is what call sites use, so the composition is what has to hold.
$composed = UptimeTrace::html($holed, $now, 'ok', 'Node uptime');
if ($composed !== $html) {
    throw new RuntimeException('html() must compose buckets() and render(): ' . $composed);
}

// A bucket array of the wrong length must be refused rather than labelled: the
// block count comes from the array while the window and block widths come from
// the constants, so six blocks would read as half an hour described as an hour.
$refused = false;
try {
    UptimeTrace::render(array_fill(0, 6, 'present'), 'ok', 'Node uptime');
} catch (InvalidArgumentException $e) {
    $refused = true;
}
if (!$refused) {
    throw new RuntimeException('render() must reject a bucket array that is not 12 blocks long');
}

// No JS, no build step -- asserted against hostile input, because $currentState
// and $label are operator-supplied and nothing else here exercises the escaping.
$hostile = UptimeTrace::render($hb, 'ok"><script>alert(1)</script>', 'Node "uptime" <b>');
if (str_contains($hostile, '<script') || str_contains($hostile, 'onclick')) {
    throw new RuntimeException('the trace is server-rendered and must not ship JS: ' . $hostile);
}

echo "ok\n";
