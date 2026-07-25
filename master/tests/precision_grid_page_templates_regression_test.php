<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$base = (string) file_get_contents($root . '/src/Controllers/BaseController.php');
$css = (string) file_get_contents($root . '/public/assets/precision-grid.css');
foreach (['pg-template-overview', 'pg-template-operations', 'pg-template-detail', 'PrecisionGridUi::pageHeader'] as $required) {
    if (!str_contains($base, $required)) {
        throw new RuntimeException('shared page template missing ' . $required);
    }
}
foreach (['.pg-page-header', '.pg-metric-strip', '.pg-section-header', '.pg-empty-state', '.pg-auth-layout'] as $required) {
    if (!str_contains($css, $required)) {
        throw new RuntimeException('shared component missing ' . $required);
    }
}
echo "ok\n";
