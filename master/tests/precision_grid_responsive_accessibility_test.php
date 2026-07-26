<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function requireSource(string $source, string $needle, string $message): void
{
    if (!str_contains($source, $needle)) {
        fail($message . ': ' . $needle);
    }
}

$root = dirname(__DIR__);

$css    = (file_get_contents($root . '/public/assets/leikwan-v2.css') ?: '');
$cssCmp = preg_replace('/\s+/', '', $css) ?: ''; // whitespace-stripped for compact-needle checks
$base      = file_get_contents($root . '/src/Controllers/BaseController.php') ?: '';
$install   = file_get_contents($root . '/public/install.php') ?: '';
$bootstrap = file_get_contents($root . '/public/index.php') ?: '';

foreach ([$base, $install, $bootstrap] as $source) {
    requireSource(
        $source,
        'name="viewport" content="width=device-width, initial-scale=1"',
        'responsive viewport metadata missing'
    );
}

foreach ([
    ':focus-visible',
    '.table-wrap{max-width:100%;overflow-x:auto',
    '@media(max-width:900px)',
    '@media(max-width:780px)',
    '.sidebar[data-open="true"]{display:block;}',
    '.content-card,.pg-console{flex:1',
    'overflow-wrap:anywhere',
    '.pg-row-menu>summary{display:grid;place-items:center;width:38px;height:38px',
] as $needle) {
    requireSource($cssCmp, $needle, 'responsive or keyboard guard missing');
}

foreach ([
    'class="mobile-nav-toggle"',
    'aria-controls="workspace-navigation"',
    'aria-expanded="false"',
    "setAttribute('aria-expanded', open ? 'true' : 'false')",
    'id="workspace-navigation"',
] as $needle) {
    requireSource($base, $needle, 'mobile navigation accessibility contract missing');
}

// Negative letter-spacing is legitimate for headings and display type (h1-h6,
// .hero, .eyebrow, brand marks) where tight tracking is an intentional
// typographic choice. It is forbidden in prose and table text because it
// reduces legibility at body sizes.
//
// Strategy: strip CSS comments, extract rule blocks, skip any block whose
// selector identifies a heading or display-type context, then assert the
// remaining (prose/table) CSS contains no negative letter-spacing.
$noComments = preg_replace('!/\*.*?\*/!s', '', $css) ?? $css;

// Selectors that are explicitly allowed to carry negative letter-spacing.
// Use ~ as delimiter so the selector patterns do not need to escape anything.
$displayPattern = '~h[1-6]|\.hero|\.eyebrow|\.signal-(?:primary|secondary|eyebrow|hero-copy)|\.kb-hero|\.auth-hero|\.brand|\.site-brand|\.site-copy~i';

// Extract all rule blocks: [full_match, raw_selector_region, declarations]
preg_match_all('/([^{}]+)\{([^{}]+)\}/s', $noComments, $m, PREG_SET_ORDER);
$proseRules = '';
foreach ($m as $rule) {
    // When a rule is inside an @media block the selector region includes the
    // at-rule prefix. Strip that prefix to get the actual selector.
    $selector = preg_replace('/^.*\}\s*/s', '', trim($rule[1])) ?? trim($rule[1]);
    if (preg_match($displayPattern, $selector)) {
        continue; // heading / display context — negative tracking is allowed
    }
    $proseRules .= $rule[0] . "\n";
}

if (preg_match('/letter-spacing\s*:\s*-/i', $proseRules) === 1) {
    fail('negative letter-spacing is forbidden in prose and table text contexts');
}

echo "ok\n";

