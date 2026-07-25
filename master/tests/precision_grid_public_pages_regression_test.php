<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$base = (string) file_get_contents($root . '/src/Controllers/BaseController.php');
$auth = (string) file_get_contents($root . '/src/Controllers/AuthController.php');
$css = (string) file_get_contents($root . '/public/assets/signal-chamber.css');
$health = (string) file_get_contents($root . '/src/Controllers/HealthController.php');
$index = (string) file_get_contents($root . '/public/index.php');
$install = (string) file_get_contents($root . '/public/install.php');
foreach ([$base, $health, $index] as $source) {
    if (!str_contains($source, '/assets/leikwan-ui.css?v=20260725') || !str_contains($source, '/assets/signal-chamber.css?v=20260725')) {
        throw new RuntimeException('public surface does not load the public asset stack');
    }
}
if (!str_contains($install, '/assets/leikwan-ui.css?v=20260725') || !str_contains($install, '/assets/operator-console.css?v=20260725')) {
    throw new RuntimeException('installer does not load the operator asset stack');
}
foreach (['pg-public', 'pg-auth-layout', 'pg-form-section'] as $required) {
    if (!str_contains($base . $health . $index . $install, $required)) {
        throw new RuntimeException('public template marker missing ' . $required);
    }
}
foreach (['pg-home-header', 'pg-home-flow', 'pg-home-proof-list', 'pg-home-entry-list'] as $required) {
    if (!str_contains($auth . $css, $required)) {
        throw new RuntimeException('signal chamber homepage marker missing ' . $required);
    }
}
foreach (['launch-hero', 'flow-visual', 'flow-band', 'proof-grid', 'launch-entry-grid'] as $forbidden) {
    if (str_contains($auth, $forbidden)) {
        throw new RuntimeException('legacy marketing component remains on homepage: ' . $forbidden);
    }
}
echo "ok\n";
