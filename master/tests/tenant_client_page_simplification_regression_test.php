<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');
if (!is_string($source) || $source === '') {
    fail('failed to read TenantController source');
}

$start = strpos($source, 'public function clientsPage(Request $request, array $params): Response');
$end = strpos($source, 'public function sidecarsPage(Request $request, array $params): Response');
if (!is_int($start) || !is_int($end) || $end <= $start) {
    fail('failed to isolate tenant clientsPage source');
}

$clientsPage = substr($source, $start, $end - $start);

foreach ([
    'Quick start',
    'Online device limit',
    'Linux Install',
    'Online devices',
    'These are only defaults for future forwards. They are not required for app install.',
    'Keep this simple. Create a client, install the app, and watch whether the device is online.',
] as $required) {
    if (!str_contains($clientsPage, $required)) {
        fail('missing simplified tenant client page snippet: ' . $required);
    }
}

foreach ([
    'Install details',
    'Download Windows App',
    'Download Android App',
    'Windows App',
    'Android App',
    'Client ID:',
    'Token:',
    'Usage Calendar',
    'Assigned Forwards',
    'renderClientAppDownloadOverview(',
    'renderAssignedForwardSummary(',
    'renderUsageOverviewPanel(',
    'Latest Panel Version:',
] as $forbidden) {
    if (str_contains($clientsPage, $forbidden)) {
        fail('stale tenant client complexity still present: ' . $forbidden);
    }
}

echo "ok\n";
