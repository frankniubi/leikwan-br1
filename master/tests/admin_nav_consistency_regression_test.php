<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$admin = file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');
$auth = file_get_contents(__DIR__ . '/../src/Controllers/AuthController.php');
$base = file_get_contents(__DIR__ . '/../src/Controllers/BaseController.php');

if (!is_string($admin) || $admin === '') {
    fail('failed to read AdminController source');
}

if (!is_string($auth) || $auth === '') {
    fail('failed to read AuthController source');
}

if (!is_string($base) || $base === '') {
    fail('failed to read BaseController source');
}

foreach ([
    'protected function adminNavigationItems',
    '/admin/tenants',
    '/admin/registration',
    '/admin/services',
    '/admin/plans',
    '/admin/client-groups',
    '/admin/credentials',
    '/admin/clients',
    '/admin-transport-endpoints',
    '/knowledgebase',
    '/admin/node-groups',
    '/admin/nodes',
    '/admin/user-groups',
    'Transport Endpoints',
    'Slave Groups',
    'Slave Nodes',
    'User Groups',
    '/admin/ultra',
    '/admin/forward-combinations',
    'Ultra Operations',
    'Forward Combinations',
] as $required) {
    if (!str_contains($base, $required)) {
        fail('shared admin nav missing required snippet: ' . $required);
    }
}

foreach ([
    '/admin/cf-location-groups',
    '/admin/chains',
    'CF Location Groups',
    "'label' => 'Chains'",
] as $retiredNavigationSnippet) {
    if (str_contains($base, $retiredNavigationSnippet)) {
        fail('retired routing tab remains in shared admin nav: ' . $retiredNavigationSnippet);
    }
}

if (!str_contains($auth, '$this->adminNavigationItems(\'knowledgebase\')')) {
    fail('auth knowledgebase nav is not using the shared admin navigation helper');
}

if (!str_contains($admin, '$this->adminNavigationItems($active)')) {
    fail('admin page is not using the shared admin navigation helper');
}

foreach ([
    '/admin/user-groups',
] as $forbiddenDirectSnippet) {
    if (str_contains($auth, $forbiddenDirectSnippet) && !str_contains($auth, '$this->adminNavigationItems(\'knowledgebase\')')) {
        fail('auth controller still appears to own a stale direct admin nav snippet: ' . $forbiddenDirectSnippet);
    }
}

echo "ok\n";
