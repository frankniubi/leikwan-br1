<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../migrations/20260424_add_knowledgebase_threads.sql');
if (!is_string($source) || $source === '') {
    fail('failed to read knowledgebase migration');
}

foreach ([
    'CREATE TABLE IF NOT EXISTS knowledgebase_threads',
    'Getting Started: First Forward Checklist',
    'Connection Modes: Single, Race, Bond',
    'Bond Sidecar Setup',
    'Troubleshooting Common Mistakes',
    'Install Client',
    'How To Use The Forward',
    '127.0.0.1',
    'Client Public IP',
    'Tenant -&gt; Clients',
    'Tenant -&gt; Sidecars',
    'Download Windows App',
    'Download Android App',
    'Copy Install Command',
    'Create Forward',
    'Forward Combination',
    'Local Listen Port',
    'View Diagnostics',
    'getting-started-first-forward-checklist',
    'connection-modes-single-race-bond',
    'bond-sidecar-setup',
    'troubleshooting-common-mistakes',
] as $required) {
    if (!str_contains($source, $required)) {
        fail('missing knowledgebase migration snippet: ' . $required);
    }
}

foreach ([
    '第一條轉發',
    '安裝 Client',
    '如何使用轉發後的服務',
    '外部使用',
    '點擊',
    '複製',
    '貼上',
] as $requiredUtf8) {
    if (!str_contains($source, $requiredUtf8)) {
        fail('missing utf-8 knowledgebase snippet: ' . $requiredUtf8);
    }
}

echo "ok\n";
