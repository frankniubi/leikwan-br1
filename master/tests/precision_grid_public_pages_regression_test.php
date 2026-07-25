<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$base = (string) file_get_contents($root . '/src/Controllers/BaseController.php');
$health = (string) file_get_contents($root . '/src/Controllers/HealthController.php');
$index = (string) file_get_contents($root . '/public/index.php');
$install = (string) file_get_contents($root . '/public/install.php');
foreach ([$base, $health, $index, $install] as $source) {
    if (!str_contains($source, '/assets/precision-grid.css?v=20260725')) {
        throw new RuntimeException('public surface does not load precision grid asset');
    }
}
foreach (['pg-public', 'pg-auth-layout', 'pg-form-section'] as $required) {
    if (!str_contains($base . $health . $index . $install, $required)) {
        throw new RuntimeException('public template marker missing ' . $required);
    }
}
echo "ok\n";
