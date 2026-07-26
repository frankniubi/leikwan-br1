<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$base = (string) file_get_contents($root . '/src/Controllers/BaseController.php');
$auth = (string) file_get_contents($root . '/src/Controllers/AuthController.php');
$css = (string) file_get_contents($root . '/public/assets/leikwan-v2.css');
$health = (string) file_get_contents($root . '/src/Controllers/HealthController.php');
$index = (string) file_get_contents($root . '/public/index.php');
$install = (string) file_get_contents($root . '/public/install.php');
foreach ([$base, $health, $index, $install] as $source) {
    if (!str_contains($source, '/assets/leikwan-v2.css')) {
        throw new RuntimeException('public surface does not load the v2 asset stack');
    }
}
foreach (['leikwan-ui.css', 'operator-console.css', 'signal-chamber.css', 'signal-chamber.js'] as $legacy) {
    if (str_contains($base . $health . $index . $install, $legacy)) {
        throw new RuntimeException('legacy public asset remains referenced: ' . $legacy);
    }
}
foreach (['pg-public', 'pg-auth-layout', 'pg-form-section'] as $required) {
    if (!str_contains($base . $health . $index . $install, $required)) {
        throw new RuntimeException('public template marker missing ' . $required);
    }
}
foreach (['signal-hero', 'signal-summary', 'signal-light-detail', 'signal-architecture'] as $required) {
    if (!str_contains($auth . $css, $required)) {
        throw new RuntimeException('public homepage marker missing ' . $required);
    }
}
foreach (['launch-hero', 'flow-visual', 'flow-band', 'proof-grid', 'launch-entry-grid'] as $forbidden) {
    if (str_contains($auth, $forbidden)) {
        throw new RuntimeException('legacy marketing component remains on homepage: ' . $forbidden);
    }
}
echo "ok\n";
