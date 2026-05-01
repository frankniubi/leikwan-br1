<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$auth = file_get_contents(__DIR__ . '/../src/Controllers/AuthController.php');
$tenant = file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');

if (!is_string($auth) || $auth === '') {
    fail('failed to read AuthController source');
}
if (!is_string($tenant) || $tenant === '') {
    fail('failed to read TenantController source');
}

foreach ([
    'new KnowledgebaseService($this->db)',
    'listPublishedThreads()',
    'findPublishedThreadBySlug',
    'Latest Threads',
    'No published threads yet.',
    'Open admin editor',
    '?thread=',
    'Knowledgebase / Docs',
] as $required) {
    if (!str_contains($auth, $required)) {
        fail('missing threaded reader snippet: ' . $required);
    }
}

foreach ([
    '/knowledgebase?thread=getting-started-first-forward-checklist',
    '/knowledgebase?thread=connection-modes-single-race-bond',
    '/knowledgebase?thread=troubleshooting-common-mistakes',
] as $requiredTenantLink) {
    if (!str_contains($tenant, $requiredTenantLink)) {
        fail('missing updated tenant knowledgebase link: ' . $requiredTenantLink);
    }
}

foreach ([
    '#quick-start',
    '#advanced-options',
    '#usage-patterns',
    'The Default Path /',
    'Core Modes /',
    'Three Common Goals /',
] as $forbidden) {
    if (str_contains($auth . $tenant, $forbidden)) {
        fail('found stale static knowledgebase snippet: ' . $forbidden);
    }
}

echo "ok\n";
