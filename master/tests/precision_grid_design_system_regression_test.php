<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$base = (string) file_get_contents($root . '/src/Controllers/BaseController.php');

foreach (['leikwan-ui.css', 'operator-console.css', 'signal-chamber.css'] as $asset) {
    if (!is_file($root . '/public/assets/' . $asset)) {
        throw new RuntimeException('replacement stylesheet is missing: ' . $asset);
    }
}
$css = (string) file_get_contents($root . '/public/assets/leikwan-ui.css')
    . (string) file_get_contents($root . '/public/assets/operator-console.css')
    . (string) file_get_contents($root . '/public/assets/signal-chamber.css');
$compact = preg_replace('/\s+/', '', $css) ?: '';
foreach (['--page:#eef0ed', '--surface:#fff', '--ink:#17201c', '--healthy:#139b68', '--danger:#c84538'] as $token) {
    if (!str_contains($compact, $token)) {
        throw new RuntimeException('missing token ' . $token);
    }
}
foreach ([':focus-visible', '.table-scroll', '.mobile-nav-toggle', '.status[data-state', '@media(max-width:900px)'] as $required) {
    if (!str_contains($compact, str_replace(' ', '', $required))) {
        throw new RuntimeException('missing responsive/accessibility rule ' . $required);
    }
}
foreach (['/assets/leikwan-ui.css?v=20260725', '/assets/operator-console.css?v=20260825', '/assets/signal-chamber.css?v=20260725'] as $asset) {
    if (!str_contains($base, $asset)) {
        throw new RuntimeException('shell missing replacement stylesheet ' . $asset);
    }
}
if (str_contains($base, 'precision-grid.css') || is_file($root . '/public/assets/precision-grid.css')) {
    throw new RuntimeException('retired precision-grid stylesheet remains');
}
echo "ok\n";
