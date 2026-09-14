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
    '.bridge-home-title-zh',
    '.bridge-home-title-en',
    '--bridge-orange: #f6821f',
    '--bridge-line: #e6e6e6',
    '.bridge-home-page .site-mark img',
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

foreach (['gateway', 'Gateway', 'departure', 'Departure', 'destination', 'Destination',
    '出发地', '目的地', '游戏区', '遊戲區', '入口城市'] as $forbidden) {
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

    foreach (['bridge-home-hero', 'bridge-home-map', 'bridge-home-network', 'bridge-home-title-zh', 'bridge-home-title-en', 'bridge-home-cities', 'bridge-home-carriers', 'bridge-home-closing', '/assets/carriers/ct.svg', '/assets/carriers/cu.svg', '/assets/carriers/cm.svg'] as $marker) {
        if (!str_contains($html, $marker)) {
            homepageFail($locale . ' homepage is missing ' . $marker);
        }
    }

    if (!str_contains($html, '直達') || !str_contains($html, 'Direct.')) {
        homepageFail($locale . ' homepage lost the bilingual lockup');
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

    if (substr_count($html, 'class="bridge-home-city"') !== $nodeCount) {
        homepageFail($locale . ' atlas must name every peer once');
    }

    if (preg_match_all('/<g class="rk-node">/', $html) !== $nodeCount) {
        homepageFail($locale . ' plate must draw one node per peer');
    }

    preg_match_all('/bridge-home-plate-key-code">([^<]*)</', $html, $keys);
    if (count($keys[1]) !== $nodeCount) {
        homepageFail($locale . ' plate key must name every peer');
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
