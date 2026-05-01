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
    'Republish All Forwards',
    '/admin/republish/all',
    '/admin/diagnostics',
    'public function republishAllForwards(Request $request, array $params): Response',
    'public function diagnosticsPage(Request $request, array $params): Response',
];

foreach ($requiredAdminSnippets as $snippet) {
    if (!str_contains($adminSource, $snippet)) {
        fail('expected AdminController to contain snippet: ' . $snippet);
    }
}

$requiredBootstrapSnippets = [
    "\$this->router->add('POST', '/admin/republish/all', [\$admin, 'republishAllForwards']);",
    "\$this->router->add('GET', '/admin/diagnostics', [\$admin, 'diagnosticsPage']);",
];

foreach ($requiredBootstrapSnippets as $snippet) {
    if (!str_contains($bootstrapSource, $snippet)) {
        fail('expected Bootstrap to contain snippet: ' . $snippet);
    }
}

echo "ok\n";
