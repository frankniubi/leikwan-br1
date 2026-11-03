<?php
declare(strict_types=1);

$admin = (string) file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');
$tenant = (string) file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');

foreach ([
    'admin' => [$admin, 'saveSidecarPoint', ['list', 'create', 'edit', 'updates', 'health'], ['/admin-transport-endpoints', '/request-update', '/delete']],
    'tenant' => [$tenant, 'saveSidecarPoint', ['list', 'create', 'edit'], ['/tenant-transport-endpoints', '/request-update', '/delete']],
] as $label => [$source, $next, $views, $actions]) {
    $start = strpos($source, 'public function sidecarsPage(');
    $end = strpos($source, 'public function ' . $next . '(', $start ?: 0);
    if ($start === false || $end === false) throw new RuntimeException('unable to extract ' . $label . ' endpoints');
    $method = substr($source, $start, $end - $start);
    foreach ($views as $view) {
        if (!str_contains($method, "'{$view}'")) throw new RuntimeException($label . ' endpoint view missing ' . $view);
    }
    foreach (['normalizeView', 'pg-sheet', 'pg-mobile-resource-row', 'resourceCell', 'status(', 'rowMenu'] as $marker) {
        if (!str_contains($method, $marker)) throw new RuntimeException($label . ' endpoint marker missing ' . $marker);
    }
    foreach ($actions as $action) {
        if (!str_contains($method, $action)) throw new RuntimeException($label . ' endpoint action missing ' . $action);
    }
}

if (!str_contains($admin, 'External UoT Health')
    || !str_contains($admin, 'externalUotHealthInfo')
    || !str_contains($admin, 'manifest match')
    || !str_contains($admin, 'Configured Forwards')
    || !str_contains($admin, 'Agent Runtime / Arch')
    || !str_contains($admin, 'Configured external target')
    || !str_contains($admin, 'unregistered / no heartbeat')) {
    throw new RuntimeException('admin transport endpoint health view is missing the external UoT audit surface');
}

foreach (["'runtime'", '/admin/clients?view=runtime', 'Runtime / Arch', 'runtime_binary_mode', 'agent_architecture'] as $marker) {
    if (!str_contains($admin, $marker)) {
        throw new RuntimeException('admin client runtime audit surface is missing ' . $marker);
    }
}

echo "ok\n";
