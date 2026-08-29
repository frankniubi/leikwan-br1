<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$css = (string) file_get_contents($root . '/public/assets/bridge-home.css');

foreach ([
    '.bridge-home-page .bridge-home-status::before',
    '.bridge-home-page .bridge-home-status::after',
    '.bridge-home-page .bridge-home-route-card:hover',
    '.bridge-home-page :is(a, button, select):focus-visible',
    'transition: transform 180ms ease, background-color 180ms ease, box-shadow 180ms ease',
    '@media (prefers-reduced-motion: reduce)',
] as $required) {
    if (!str_contains($css, $required)) {
        throw new RuntimeException('homepage visual polish marker missing ' . $required);
    }
}

foreach (['linear-gradient(', 'radial-gradient('] as $forbidden) {
    if (str_contains($css, $forbidden)) {
        throw new RuntimeException('homepage visual polish uses forbidden visual primitive ' . $forbidden);
    }
}

echo "ok\n";
