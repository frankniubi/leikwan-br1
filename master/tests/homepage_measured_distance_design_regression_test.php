<?php
declare(strict_types=1);

/**
 * The homepage is a light East Asia mesh plate, not a textbook chart. This test
 * guards the decisions that make it one:
 *
 *   - the global box-sizing reset, without which padded bands overflow;
 *   - the bilingual lockup, because a missing English or Chinese face falls
 *     back to a mixed sentence and only shows up in production;
 *   - the solved chart frame, because the plate crop and the CSS have to
 *     agree or the coast silently letterboxes;
 *   - the ban on gradient primitives;
 *   - the text budget, because this page is a plate of pictograms and labels:
 *     a paragraph anywhere on it is the regression;
 *   - no latency numbers on the public door.
 */

function homepageFail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/src/Support/LocaleResolver.php';
require_once $root . '/src/Support/HomePageContent.php';

$contentPath = $root . '/src/Support/HomePageContent.php';
$cssPath = $root . '/public/assets/bridge-home.css';
$content = (string) file_get_contents($contentPath);
$css = (string) file_get_contents($cssPath);

if (substr_count($css, '{') !== substr_count($css, '}')) {
    homepageFail('bridge-home.css has unbalanced braces');
}

foreach ([
    '.bridge-home-page,',
    'box-sizing: border-box;',
] as $required) {
    if (!str_contains($css, $required)) {
        homepageFail('homepage lost its border-box reset: ' . $required);
    }
}

foreach ([
    'width: 100vw',
    '100vw',
    '-50vw',
] as $forbidden) {
    if (str_contains($css, $forbidden)) {
        homepageFail('homepage uses a viewport unit that overflows with a classic scrollbar: ' . $forbidden);
    }
}

if (preg_match('/--bridge-gutter-(left|right):\s*[^;]*%/', $css) === 1) {
    homepageFail('gutter token must not resolve against a percentage');
}

foreach ([
    'url("/assets/fonts/inter-var.ttf")',
    'url("/assets/fonts/space-grotesk-var.ttf")',
    '.bridge-home-title',
    '--bridge-orange: #ff8a2a',
    '--bridge-line: #1c2633',
    '.bridge-home-page .site-mark img',
    '.bridge-home-network',
    '.bridge-home-platforms',
    '.bridge-home-stats',
    '.rk-node-label',
    '@media (max-width: 900px)',
    '@media (max-width: 560px)',
    '@media (prefers-reduced-motion: reduce)',
] as $required) {
    if (!str_contains($css, $required)) {
        homepageFail('homepage composition contract missing: ' . $required);
    }
}

$code = (string) preg_replace('#/\*.*?\*/#s', '', $content);
$code = (string) preg_replace('#^\s*//.*$#m', '', $code);

foreach (['gateway', 'Gateway', 'departure', 'Departure',
    '出发地', '游戏区', '遊戲區', '入口城市'] as $forbidden) {
    if (str_contains($code, $forbidden)) {
        homepageFail('homepage reintroduces a direction on a symmetric mesh: ' . $forbidden);
    }
}

foreach (['ORIGINS', 'DESTINATIONS', 'rk-link-out', 'rk-dot-end', 'rk-halo-end'] as $forbidden) {
    if (str_contains($content, $forbidden)) {
        homepageFail('homepage still carries the retired origin/destination model: ' . $forbidden);
    }
}

foreach ([
    'greatCirclePath(',
    'greatCircleKm(',
    'shortestLonDelta(',
    'function frame(',
    'PLATE_RATIO',
    'rk-link-mesh',
] as $required) {
    if (!str_contains($content, $required)) {
        homepageFail('homepage chart contract missing: ' . $required);
    }
}

$render = static function (string $locale): string {
    return \Leikwan\Master\Support\HomePageContent::render('Leikwan', '/dashboard', $locale);
};

$reflection = new ReflectionClass(\Leikwan\Master\Support\HomePageContent::class);
$frame = $reflection->getMethod('frame');
$frame->setAccessible(true);
$box = $frame->invoke(null);

foreach (['x', 'y', 'w', 'h', 'lat_top', 'lat_span'] as $key) {
    if (!isset($box[$key]) || !is_float($box[$key])) {
        homepageFail('chart frame did not solve a numeric ' . $key);
    }
}

if ($box['y'] < 0.0 || $box['y'] + $box['h'] > 540.0) {
    homepageFail('chart frame escapes the plate vertically');
}

if ($box['x'] < 0.0 || $box['x'] + $box['w'] > 960.0) {
    homepageFail('chart frame escapes the plate horizontally');
}

$nodes = $reflection->getConstant('NODES');
$bottom = $box['lat_top'] - $box['lat_span'];
foreach ($nodes as $node) {
    if ($node['lat'] > $box['lat_top'] || $node['lat'] < $bottom) {
        homepageFail('chart frame crops ' . $node['code'] . ' out of the plate');
    }
}

$plateRatio = (float) $reflection->getConstant('PLATE_RATIO');
$frameRatio = $box['w'] / $box['h'];
if (abs($plateRatio - $frameRatio) > 0.06) {
    homepageFail(sprintf(
        'plate aspect %.3f does not match the solved frame aspect %.3f',
        $plateRatio,
        $frameRatio
    ));
}

if (count($nodes) < 8) {
    homepageFail('the mesh lists only ' . count($nodes) . ' nodes');
}

foreach (['en', 'zh-Hans', 'zh-TW'] as $locale) {
    $html = $render($locale);

    if (str_contains($html, '&amp;#') || str_contains($html, 'ï¿½')) {
        homepageFail($locale . ' homepage has double-encoded or corrupted text');
    }

    foreach (['bridge-home-hero', 'bridge-home-map', 'bridge-home-network', 'bridge-home-title', 'bridge-home-plate-key', 'bridge-home-carriers', 'bridge-home-closing', '/assets/carriers/ct.svg', '/assets/carriers/cu.svg', '/assets/carriers/cm.svg'] as $marker) {
        if (!str_contains($html, $marker)) {
            homepageFail($locale . ' homepage is missing ' . $marker);
        }
    }

    if (!str_contains($html, 'Your destination.') || !preg_match('/直达目的地|直達目的地/u', $html)) {
        homepageFail($locale . ' homepage lost its destination lockup');
    }

    if (str_contains($html, '127.0.0.1')) {
        homepageFail($locale . ' homepage still names 127.0.0.1 instead of the destination');
    }

    if (preg_match('/\b\d+\s*ms\b/i', $html) === 1) {
        homepageFail($locale . ' homepage published a latency number');
    }

    $nodeCount = count($nodes);
    if (substr_count($html, 'bridge-home-chip') > 0) {
        homepageFail($locale . ' homepage reintroduced a second code-to-city index');
    }
    if (substr_count($html, 'bridge-home-board-row') !== 0) {
        homepageFail($locale . ' homepage reintroduced the connection board');
    }

    if (substr_count($html, 'class="bridge-home-city"') !== 0) {
        homepageFail($locale . ' homepage reintroduced a city list beside the plate');
    }

    if (preg_match_all('/<g class="rk-node">/', $html) !== $nodeCount) {
        homepageFail($locale . ' plate must draw one node per peer');
    }

    // The plate is the only visible place a peer is named, so every peer has to
    // carry its own label and the label ladder must not drop one.
    preg_match_all('/class="rk-node-label"[^>]*>([^<]*)</', $html, $labels);
    if (count($labels[1]) !== $nodeCount) {
        homepageFail($locale . ' plate must name every peer in place');
    }
    foreach ($nodes as $node) {
        if (!in_array($node['code'], $labels[1], true)) {
            homepageFail($locale . ' plate never labels ' . $node['code']);
        }
    }

    preg_match_all('/bridge-home-plate-key-code">([^<]*)</', $html, $keys);
    if (count($keys[1]) !== $nodeCount) {
        homepageFail($locale . ' plate key must name every peer');
    }

    // The pictogram set is the page: four spec tiles, four platform glyphs and
    // three step glyphs. Losing one means prose crept back into a band.
    if (substr_count($html, 'class="bridge-home-icon"') < 7
        || substr_count($html, 'class="bridge-home-platform"') !== 4
        || substr_count($html, 'class="bridge-home-stat"') !== 4
        || substr_count($html, 'class="bridge-home-step"') !== 3) {
        homepageFail($locale . ' homepage lost part of its pictogram set');
    }

    // The text budget. This page is labels and glyphs, so what is left after the
    // screen-reader-only key is removed has to stay under a page of prose; the
    // paragraph-per-band design this replaced ran past three thousand characters.
    $visible = (string) preg_replace('#<ul class="bridge-home-plate-key">.*?</ul>#s', ' ', $html);
    $visible = html_entity_decode((string) preg_replace('#<[^>]+>#', ' ', $visible), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $visible = trim((string) preg_replace('/\s+/u', ' ', $visible));
    $characters = (int) preg_match_all('/./u', $visible);
    if ($characters > 900) {
        homepageFail($locale . ' homepage carries ' . $characters . ' characters of visible text');
    }
}

$structure = static function (string $html): string {
    $html = (string) preg_replace('/\s+[a-zA-Z-]+="[^"]*"/', '', $html);

    return (string) preg_replace('/>[^<]*</', '><', $html);
};
$spine = $structure($render('en'));
foreach (['zh-Hans', 'zh-TW'] as $locale) {
    if ($structure($render($locale)) !== $spine) {
        homepageFail('homepage structure diverges between en and ' . $locale);
    }
}

echo "ok\n";
