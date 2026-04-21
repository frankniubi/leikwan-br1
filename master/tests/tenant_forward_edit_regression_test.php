<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$tenant = file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');
$bootstrap = file_get_contents(__DIR__ . '/../src/Bootstrap.php');

foreach ([
    "public function editForward(Request \$request, array \$params): Response",
    "public function saveForwardEdit(Request \$request, array \$params): Response",
    "private function normalizeTenantForwardEditPayload(array \$payload): array",
    "/tenant/services/{id}/forward/{fid}/edit",
    "Current Forward Combination",
] as $snippet) {
    if (!str_contains($tenant . $bootstrap, $snippet)) {
        fail('missing tenant edit snippet: ' . $snippet);
    }
}

echo "ok\n";
