<?php
declare(strict_types=1);

$source = (string) file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');
$contracts = [
    'dashboard' => ['next' => 'billingPage', 'views' => ['list']],
    'clientsPage' => ['next' => 'sidecarsPage', 'views' => ['list', 'install', 'detail', 'advanced']],
    'serviceDetail' => ['next' => 'editForward', 'views' => ['overview', 'forwards']],
    'editForward' => ['next' => 'saveForwardEdit', 'views' => ['detail']],
];

foreach ($contracts as $methodName => $contract) {
    $start = strpos($source, 'public function ' . $methodName . '(');
    $end = strpos($source, 'public function ' . $contract['next'] . '(', $start ?: 0);
    if ($start === false || $end === false) {
        throw new RuntimeException('unable to extract ' . $methodName);
    }
    $method = substr($source, $start, $end - $start);
    foreach ($contract['views'] as $view) {
        if (!str_contains($method, "'{$view}'")) {
            throw new RuntimeException($methodName . ' missing view ' . $view);
        }
    }
    foreach (['client_strategy', 'latency_ms_by_node', 'Latency Best', 'Speed max', '>Random<'] as $forbidden) {
        if (str_contains($method, $forbidden)) {
            throw new RuntimeException($methodName . ' exposes client-local selection state ' . $forbidden);
        }
    }
}

$dashboardStart = strpos($source, 'public function dashboard(');
$dashboardEnd = strpos($source, 'public function billingPage(', $dashboardStart ?: 0);
$dashboard = substr($source, $dashboardStart ?: 0, ($dashboardEnd ?: 0) - ($dashboardStart ?: 0));
foreach (['pg-sheet', 'pg-mobile-resource-row', 'resourceCell', 'resourceStatusHtml', 'rowMenu'] as $marker) {
    if (!str_contains($dashboard, $marker)) {
        throw new RuntimeException('tenant forward workspace missing ' . $marker);
    }
}

$clientsStart = strpos($source, 'public function clientsPage(');
$clientsEnd = strpos($source, 'protected function renderTenantClientLinuxInstall', $clientsStart ?: 0);
$clients = substr($source, $clientsStart ?: 0, ($clientsEnd ?: 0) - ($clientsStart ?: 0));
foreach (['pg-sheet', 'pg-mobile-resource-row', '/tenant/clients', '/diagnose', '/request-update', '/delete'] as $marker) {
    if (!str_contains($clients, $marker)) {
        throw new RuntimeException('tenant client workspace missing ' . $marker);
    }
}

echo "ok\n";
