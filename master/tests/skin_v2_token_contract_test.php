<?php
declare(strict_types=1);

/**
 * The v2 token layer must define every design token.
 *
 * What this asserts: that each token IS defined, never that it equals a
 * specific hex. Palette tuning must not turn the suite red -- that failure
 * mode is why the legacy tests were worthless (they locked exact values).
 *
 * What this does not assert: the visual appearance. Design decisions are in
 * the stylesheet; this test guards the contract, not the palette.
 */

$root = dirname(__DIR__);
$css = (string) file_get_contents($root . '/public/assets/leikwan-v2.css');
// Strip comments before compacting -- a comment like "/* no backdrop-filter */"
// would false-positive on the retirement check below.
$stripped = preg_replace('!/\*.*?\*/!s', '', $css) ?? $css;
$compact = preg_replace('/\s+/', '', $stripped) ?: '';

// Every token the component layer is allowed to reference must be defined
// here. Components that introduce an undeclared token break other themes.
foreach ([
    '--ink-0', '--ink-1', '--ink-2', '--ink-line',
    '--paper-0', '--paper-1', '--paper-line', '--paper-rule',
    '--text-hi', '--text-lo', '--paper-hi', '--paper-lo', '--micro',
    '--font-display', '--font-body', '--font-mono', '--font-cjk',
    '--radius', '--row-h', '--cjk-heading-scale',
] as $token) {
    if (!str_contains($compact, $token . ':')) {
        throw new RuntimeException('v2 token is not defined: ' . $token);
    }
}

// One radius spec -- the legacy skins had five (2 / 5 / 14 / 28 / 999px).
// Every component must use var(--radius); raw px values are forbidden.
// Exceptions: 0 (flat), 1px (thin rules and tracks), 50% (circles).
preg_match_all('/border-radius:\s*([^;}]+)/i', $css, $m);
$radii = [];
foreach ($m[1] as $value) {
    $value = trim($value);
    if (!str_contains($value, 'var(--radius)') && $value !== '0' && $value !== '1px' && $value !== '50%') {
        $radii[$value] = true;
    }
}
if ($radii !== []) {
    throw new RuntimeException('radius must come from var(--radius); found: ' . implode(', ', array_keys($radii)));
}

// Zero !important -- the entire point of the rewrite.
if (str_contains($css, '!important')) {
    throw new RuntimeException('leikwan-v2.css must contain zero !important');
}

// Glassmorphism is retired. 675 !important declarations accumulated because
// each new layer had to fight the backdrop-filter layer above it.
if (str_contains($compact, 'backdrop-filter')) {
    throw new RuntimeException('backdrop-filter is retired in v2');
}

// Hue is quarantined to status contexts only.
// No other component may introduce a saturated colour.
foreach (['--state-ok', '--state-warn', '--state-down'] as $token) {
    if (!str_contains($compact, $token . ':')) {
        throw new RuntimeException('status colour token missing: ' . $token);
    }
}

// The retired signal accent collided with the danger state -- it is gone.
if (str_contains($compact, '--signal:#ef4938')) {
    throw new RuntimeException('the retired --signal accent must not appear in v2');
}

echo "ok\n";
