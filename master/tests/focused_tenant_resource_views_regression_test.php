<?php
declare(strict_types=1);

$source = (string) file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');
$contracts = [
    'dashboard' => ['next' => 'billingPage', 'views' => ['list']],
    'clientsPage' => ['next' => 'sidecarsPage', 'views' => []],
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
foreach (['pg-sheet', 'pg-mobile-resource-row', 'resourceCell', 'status(', 'rowMenu'] as $marker) {
    if (!str_contains($dashboard, $marker)) {
        throw new RuntimeException('tenant forward workspace missing ' . $marker);
    }
}

$clientsStart = strpos($source, 'public function clientsPage(');
$clientsEnd = strpos($source, 'public function sidecarsPage(', $clientsStart ?: 0);
$clients = substr($source, $clientsStart ?: 0, ($clientsEnd ?: 0) - ($clientsStart ?: 0));
foreach (['Linux', 'Windows App', '最大在线', '/tenant/clients/reset-token'] as $required) {
    if (!str_contains($clients, $required)) {
        throw new RuntimeException('tenant client install surface missing ' . $required);
    }
}
foreach (['pg-sheet', 'pg-mobile-resource-row', '/diagnose', '/request-update', '/delete', 'viewTabs'] as $forbidden) {
    if (str_contains($clients, $forbidden)) {
        throw new RuntimeException('tenant client install surface contains legacy ' . $forbidden);
    }
}

echo "ok\n";
