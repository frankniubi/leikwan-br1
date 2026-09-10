<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$repoRoot = dirname(__DIR__, 2);
$adminSource = file_get_contents($repoRoot . '/master/src/Controllers/AdminController.php');
$bootstrapSource = file_get_contents($repoRoot . '/master/src/Bootstrap.php');

if (!is_string($adminSource) || $adminSource === '') {
    fail('failed to read AdminController source');
}

if (!is_string($bootstrapSource) || $bootstrapSource === '') {
    fail('failed to read Bootstrap source');
}

$requiredAdminSnippets = [
    'public function republishForClient(Request $request, array $params): Response',
    "/admin/republish/client/' . (int) \$client['id']",
    'Republish',
    "/admin/clients/' . (int) \$client['id'] . '/diagnose",
    'Run Diagnostics',
    "\$this->provisioning?->republishForwardsForClientWithResult(\$clientId)",
];

foreach ($requiredAdminSnippets as $snippet) {
    if (!str_contains($adminSource, $snippet)) {
        fail('expected AdminController to contain snippet: ' . $snippet);
    }
}

$requiredBootstrapSnippets = [
    "\$this->router->add('POST', '/admin/republish/client/{id}', [\$admin, 'republishForClient']);",
];

foreach ($requiredBootstrapSnippets as $snippet) {
    if (!str_contains($bootstrapSource, $snippet)) {
        fail('expected Bootstrap to contain snippet: ' . $snippet);
    }
}

echo "ok\n";
