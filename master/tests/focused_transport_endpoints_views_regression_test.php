<?php
declare(strict_types=1);

$admin = (string) file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');
$tenant = (string) file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');

foreach ([
    'admin' => [$admin, 'saveSidecarPoint', ['list', 'create', 'edit', 'updates'], ['/admin-transport-endpoints', '/request-update', '/delete']],
    'tenant' => [$tenant, 'saveSidecarPoint', ['list', 'create', 'edit'], ['/tenant-transport-endpoints', '/request-update', '/delete']],
] as $label => [$source, $next, $views, $actions]) {
    $start = strpos($source, 'public function sidecarsPage(');
    $end = strpos($source, 'public function ' . $next . '(', $start ?: 0);
    if ($start === false || $end === false) throw new RuntimeException('unable to extract ' . $label . ' endpoints');
    $method = substr($source, $start, $end - $start);
    foreach ($views as $view) {
        if (!str_contains($method, "'{$view}'")) throw new RuntimeException($label . ' endpoint view missing ' . $view);
    }
    foreach (['normalizeView', 'pg-sheet', 'pg-mobile-resource-row', 'resourceCell', 'resourceStatusHtml', 'rowMenu'] as $marker) {
        if (!str_contains($method, $marker)) throw new RuntimeException($label . ' endpoint marker missing ' . $marker);
    }
    foreach ($actions as $action) {
        if (!str_contains($method, $action)) throw new RuntimeException($label . ' endpoint action missing ' . $action);
    }
}

echo "ok\n";
