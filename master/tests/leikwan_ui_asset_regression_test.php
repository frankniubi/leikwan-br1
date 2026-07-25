<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$base = (string) file_get_contents($root . '/src/Controllers/BaseController.php');
$index = (string) file_get_contents($root . '/public/index.php');
$install = (string) file_get_contents($root . '/public/install.php');
$health = (string) file_get_contents($root . '/src/Controllers/HealthController.php');

foreach (['leikwan-ui.css', 'operator-console.css', 'signal-chamber.css'] as $asset) {
    if (!is_file($root . '/public/assets/' . $asset)) {
        throw new RuntimeException('missing UI asset ' . $asset);
    }
}
foreach (['/assets/leikwan-ui.css?v=20260725', '/assets/operator-console.css?v=20260725'] as $href) {
    if (!str_contains($base, $href)) {
        throw new RuntimeException('application shell missing ' . $href);
    }
}
foreach (['/assets/leikwan-ui.css?v=20260725', '/assets/signal-chamber.css?v=20260725'] as $href) {
    if (!str_contains($base, $href)) {
        throw new RuntimeException('public shell missing ' . $href);
    }
}
if (str_contains($base, '<style>') || str_contains($base, 'publicPageStyles()')) {
    throw new RuntimeException('BaseController still emits competing embedded CSS');
}
if (str_contains($base . $index . $install . $health, 'precision-grid.css')) {
    throw new RuntimeException('retired precision-grid asset is still loaded');
}
echo "ok\n";
