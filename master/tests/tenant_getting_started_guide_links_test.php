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

$start = strpos($source, 'public function gettingStartedPage(Request $request, array $params): Response');
$end = strpos($source, 'public function dashboard(Request $request, array $params): Response', $start ?: 0);
if (!is_int($start) || !is_int($end) || $end <= $start) {
    fail('failed to isolate tenant gettingStartedPage source');
}

$gettingStartedPage = substr($source, $start, $end - $start);
$requiredPairs = [
    ['No active package yet', 'getting-started-five-minute-overview'],
    ['Create your first forward', 'getting-started-first-forward-checklist'],
    ['Install the client', 'install-client'],
    ['Connect and verify', 'connect-and-verify'],
];
foreach ($requiredPairs as [$state, $topicKey]) {
    if (!str_contains($gettingStartedPage, $state) || !str_contains($gettingStartedPage, $topicKey)) {
        fail('missing state-specific getting-started guide pair: ' . $state . ' -> ' . $topicKey);
    }
}

foreach ([
    'getting-started-five-minute-overview',
    'install-client',
    'getting-started-first-forward-checklist',
    'connect-and-verify',
    'connection-modes-single-race-bond',
    'bond-sidecar-setup',
    'troubleshooting-common-mistakes',
    'glossary',
] as $topicKey) {
    if (!str_contains($source, $topicKey)) {
        fail('missing stable tenant guide link: ' . $topicKey);
    }
}

if (str_contains($source, '</a> ? <a')) {
    fail('tenant contextual help still uses malformed question-mark separators');
}
foreach (['Advanced Options', 'Usage Patterns'] as $vagueLabel) {
    if (str_contains($source, $vagueLabel)) {
        fail('tenant contextual help still uses vague label: ' . $vagueLabel);
    }
}

foreach ([
    'Create your first forward',
    'Choose a connection mode',
    'Troubleshoot a connection',
    'Read the glossary',
] as $label) {
    if (!str_contains($source, $label)) {
        fail('missing descriptive tenant guide label: ' . $label);
    }
}

echo "ok\n";
