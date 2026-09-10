<?php
declare(strict_types=1);

$source = (string) file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');
$contracts = [
    'tenantsPage' => [
        'next' => 'tenantRegistrationPage',
        'views' => ['list', 'create', 'edit'],
        'actions' => ['/admin/tenants', '/delete', '/admin/tenant-registration/settings', '/admin/tenant-registration/codes'],
    ],
    'servicesPage' => [
        'next' => 'saveTenant',
        'views' => ['list', 'create'],
        'actions' => ['/admin/services'],
    ],
    'clientsPage' => [
        'next' => 'cfLocationGroupsPage',
        'views' => ['list', 'create', 'detail', 'updates'],
        'actions' => ['/admin/clients', '/delete', '/request-update'],
    ],
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
    foreach (['normalizeView', 'pg-sheet', 'resourceCell', 'status(', 'rowMenu', 'pg-mobile-resource-row'] as $marker) {
        if (!str_contains($method, $marker)) {
            throw new RuntimeException($methodName . ' missing resource marker ' . $marker);
        }
    }
    foreach ($contract['actions'] as $action) {
        if (!str_contains($method, $action)) {
            throw new RuntimeException($methodName . ' lost action ' . $action);
        }
    }
}

echo "ok\n";
