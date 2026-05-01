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

foreach ([
    'Task Queue Health',
    'Recent Failed Tasks',
    'Worst Legs Right Now',
    'Bond Leg Reputation',
] as $snippet) {
    if (!str_contains($adminSource, $snippet)) {
        fail('expected AdminController to contain snippet: ' . $snippet);
    }
}

if (!str_contains($bootstrapSource, "/admin/provision-tasks/purge-stale")) {
    fail('expected Bootstrap to register the stale pending purge route');
}

echo "ok\n";
