<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$auth = (string) file_get_contents($root . '/src/Controllers/AuthController.php');
$base = (string) file_get_contents($root . '/src/Controllers/BaseController.php');
$css = (string) file_get_contents($root . '/public/assets/signal-chamber.css');
$jsPath = $root . '/public/assets/signal-chamber.js';
$js = is_file($jsPath) ? (string) file_get_contents($jsPath) : '';

foreach ([
    'class="signal-hero"',
    '<h1>&#21033;&#32676;&#26371;&#32858; LeiKwan Bridge</h1>',
    'class="signal-canvas"',
    'Open Console',
    'View Network',
    'class="signal-light-detail"',
    'Sidecar Endpoints',
    'Client &rarr; Slave &rarr; Sidecar &rarr; Target',
] as $required) {
    if (!str_contains($auth, $required)) {
        throw new RuntimeException('signal homepage markup missing ' . $required);
    }
}

foreach (['pg-home-proof-list', 'pg-home-entry-list', 'client_strategy', 'Latency Best', 'Speed max', 'Random'] as $forbidden) {
    if (str_contains($auth, $forbidden)) {
        throw new RuntimeException('signal homepage exposes forbidden content ' . $forbidden);
    }
}

foreach (['SELECT COUNT(*) AS total FROM nodes WHERE status = :status', 'SELECT COUNT(*) AS total FROM sidecar_points', 'COUNT(DISTINCT region)'] as $query) {
    if (!str_contains($auth, $query)) {
        throw new RuntimeException('truthful homepage summary query missing ' . $query);
    }
}

if (!str_contains($base, '/assets/signal-chamber.js?v=20260725')) {
    throw new RuntimeException('public shell does not load signal chamber script');
}

foreach (['prefers-reduced-motion', 'IntersectionObserver', 'document.visibilityState', 'Math.min(window.devicePixelRatio || 1, 2)'] as $guard) {
    if (!str_contains($css . $js, $guard)) {
        throw new RuntimeException('signal chamber motion guard missing ' . $guard);
    }
}
if (!str_contains($js, 'if (!reduced.matches && visible && document.visibilityState === \'visible\')')) {
    throw new RuntimeException('animation frame loop is not gated by reduced motion and visibility');
}

echo "ok\n";
