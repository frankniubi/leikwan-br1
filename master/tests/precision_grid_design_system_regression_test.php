<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$cssPath = $root . '/public/assets/precision-grid.css';
$base = (string) file_get_contents($root . '/src/Controllers/BaseController.php');

if (!is_file($cssPath)) {
    throw new RuntimeException('precision grid stylesheet is missing');
}
$css = (string) file_get_contents($cssPath);
$compact = preg_replace('/\s+/', '', $css) ?: '';
foreach (['--page-bg:#f3f3f0', '--surface:#ffffff', '--ink:#111111', '--success:#087f5b', '--danger:#c73f32'] as $token) {
    if (!str_contains($compact, $token)) {
        throw new RuntimeException('missing token ' . $token);
    }
}
foreach ([':focus-visible', '.table-scroll', '.mobile-nav-toggle', '.status[data-state', '@media(max-width:900px)'] as $required) {
    if (!str_contains($compact, str_replace(' ', '', $required))) {
        throw new RuntimeException('missing responsive/accessibility rule ' . $required);
    }
}
if (substr_count($base, '/assets/precision-grid.css?v=20260725.2') < 2) {
    throw new RuntimeException('both shells must load precision-grid.css');
}
foreach (['radial-gradient', 'linear-gradient'] as $forbidden) {
    if (str_contains($css, $forbidden)) {
        throw new RuntimeException('forbidden decoration in shared stylesheet: ' . $forbidden);
    }
}

echo "ok\n";
