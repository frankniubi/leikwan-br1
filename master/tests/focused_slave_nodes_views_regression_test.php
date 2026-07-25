<?php
declare(strict_types=1);

$source = (string) file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');
$start = strpos($source, 'public function nodesPage(');
$end = strpos($source, 'public function servicesPage(', $start ?: 0);
if ($start === false || $end === false) {
    throw new RuntimeException('unable to extract nodesPage');
}
$method = substr($source, $start, $end - $start);

foreach (['nodes', 'install', 'defaults', 'updates', 'detail'] as $view) {
    if (!str_contains($method, "'{$view}'")) {
        throw new RuntimeException('missing focused node view ' . $view);
    }
}
foreach (['pg-view-tabs', 'pg-sheet', 'pg-mobile-resource-row', 'pg-detail-section'] as $class) {
    if (!str_contains($method, $class)) {
        throw new RuntimeException('missing focused node component ' . $class);
    }
}

$listStart = strpos($method, '$nodesView =');
$listEnd = strpos($method, '$viewBody = match', $listStart ?: 0);
if ($listStart === false || $listEnd === false) {
    throw new RuntimeException('nodes list branch is not isolated');
}
$nodesListBranch = substr($method, $listStart, $listEnd - $listStart);
foreach (['Manual Install Command', 'Additional Agent Config JSON', '<th>Credential</th>', '<th>SSL Certificate</th>'] as $forbidden) {
    if (str_contains($nodesListBranch, $forbidden)) {
        throw new RuntimeException('nodes list still includes dense content ' . $forbidden);
    }
}

foreach ([
    '/admin/edge-defaults',
    '/manage',
    '/request-update',
    '/delete',
    '/admin/nodes/request-update-group',
    '/admin/nodes/request-update-selected',
] as $action) {
    if (!str_contains($method, $action)) {
        throw new RuntimeException('existing node action missing ' . $action);
    }
}

echo "ok\n";
