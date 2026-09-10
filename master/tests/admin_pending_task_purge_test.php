<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$repoRoot = dirname(__DIR__, 2);
$adminSource = file_get_contents($repoRoot . '/master/src/Controllers/AdminController.php');
if (!is_string($adminSource) || $adminSource === '') {
    fail('failed to read AdminController source');
}

foreach ([
    'public function purgeStaleProvisionTasks(Request $request, array $params): Response',
    "status = 'pending'",
    '15 min',
    '30 min',
    '60 min',
    'deleted',
    'skipped',
] as $snippet) {
    if (!str_contains($adminSource, $snippet)) {
        fail('expected purge flow snippet: ' . $snippet);
    }
}

if (str_contains($adminSource, "DELETE FROM provision_tasks\n                 WHERE status = 'running'")) {
    fail('purge flow must never delete running tasks');
}

echo "ok\n";
