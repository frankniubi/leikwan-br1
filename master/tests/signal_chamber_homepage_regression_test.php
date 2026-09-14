<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$auth = (string) file_get_contents($root . '/src/Controllers/AuthController.php');
$home = (string) file_get_contents($root . '/src/Support/HomePageContent.php');
$base = (string) file_get_contents($root . '/src/Controllers/BaseController.php');
$bridgeCss = (string) file_get_contents($root . '/public/assets/bridge-home.css');
$signalCss = (string) file_get_contents($root . '/public/assets/signal-chamber.css');
$jsPath = $root . '/public/assets/signal-chamber.js';
$js = is_file($jsPath) ? (string) file_get_contents($jsPath) : '';

foreach ([
    'class="bridge-home-hero"',
    'class="bridge-home-map"',
    'class="bridge-home-network"',
    'class="bridge-home-plate-key"',
    'class="bridge-home-cities"',
    'class="bridge-home-closing"',
] as $required) {
    if (!str_contains($home, $required)) {
        throw new RuntimeException('bridge homepage markup missing ' . $required);
    }
}
if (!str_contains($auth, 'return $this->publicPage($this->displayBrandName(), $body, $user, \'bridge-home\');')) {
    throw new RuntimeException('bridge homepage shell variant contract missing');
}

foreach (['pg-home-proof-list', 'pg-home-entry-list', 'client_strategy', 'Latency Best', 'Speed max', 'Random'] as $forbidden) {
    if (str_contains($auth . $home, $forbidden)) {
        throw new RuntimeException('bridge homepage exposes forbidden content ' . $forbidden);
    }
}

foreach (['SELECT COUNT(*) AS total FROM nodes WHERE status = :status', 'SELECT COUNT(*) AS total FROM sidecar_points', 'COUNT(DISTINCT region)', 'bridge-home-metrics'] as $forbidden) {
    if (str_contains($auth . $home, $forbidden)) {
        throw new RuntimeException('homepage still exposes live business data ' . $forbidden);
    }
}

foreach ([
    'publicPage(string $title, string $body, ?array $viewer = null, string $variant = \'default\')',
    "'bridge-home'",
    '/assets/bridge-home.css?v=20260915M',
    '/assets/signal-chamber.css?v=20260725',
    '/assets/signal-chamber.js?v=20260725',
    '$script = $isBridgeHome ? \'\' :',
] as $required) {
    if (!str_contains($base, $required)) {
        throw new RuntimeException('public shell variant contract missing ' . $required);
    }
}

foreach (['.bridge-home-page', '.bridge-home-hero', '.bridge-home-map', '.bridge-home-network', '@media (max-width: 900px)'] as $selector) {
    if (!str_contains($bridgeCss, $selector)) {
        throw new RuntimeException('bridge home selector missing ' . $selector);
    }
}

foreach (['prefers-reduced-motion', 'IntersectionObserver', 'document.visibilityState', 'Math.min(window.devicePixelRatio || 1, 2)'] as $guard) {
    if (!str_contains($signalCss . $js, $guard)) {
        throw new RuntimeException('signal chamber motion guard missing ' . $guard);
    }
}
if (!str_contains($js, "if (!reduced.matches && visible && document.visibilityState === 'visible')")) {
    throw new RuntimeException('animation frame loop is not gated by reduced motion and visibility');
}

echo "ok\n";
