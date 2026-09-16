<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$css = (string) file_get_contents($root . '/public/assets/bridge-home.css');

foreach ([
    '.bridge-home-step:hover',
    '.bridge-home-carrier:hover',
    '.bridge-home-primary:hover',
    '.bridge-home-carrier-mark',
    '.bridge-home-page :is(a, button, select):focus-visible',
    'transition: transform 180ms ease, background-color 180ms ease, box-shadow 180ms ease',
    '@media (prefers-reduced-motion: reduce)',
    '.bridge-home-title',
    '.bridge-home-title-zh',
    '.bridge-home-title-en',
    '.bridge-home-hero-stage',
    '.bridge-home-network',
    '.bridge-home-platforms',
    '.rk-node-label',
    '.bridge-home-ghost:hover',
] as $required) {
    if (!str_contains($css, $required)) {
        throw new RuntimeException('homepage visual polish marker missing ' . $required);
    }
}

foreach ([] as $forbidden) {
    if (str_contains($css, $forbidden)) {
        throw new RuntimeException('homepage visual polish uses forbidden visual primitive ' . $forbidden);
    }
}

echo "ok\n";
