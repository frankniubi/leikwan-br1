<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function sourceBetween(string $source, string $startMarker, string $endMarker, string $description): string
{
    $start = strpos($source, $startMarker);
    if (!is_int($start)) {
        fail('failed to find start of ' . $description);
    }

    $end = strpos($source, $endMarker, $start + strlen($startMarker));
    if (!is_int($end) || $end <= $start) {
        fail('failed to find end of ' . $description);
    }

    return substr($source, $start, $end - $start);
}

function bodyAssignmentContaining(string $source, string $visibleText): string
{
    $textPosition = strpos($source, $visibleText);
    if (!is_int($textPosition)) {
        fail('missing getting-started state text: ' . $visibleText);
    }

    $assignmentStart = strrpos(substr($source, 0, $textPosition), '$body = \'');
    $assignmentEnd = strpos($source, "';", $textPosition + strlen($visibleText));
    if (!is_int($assignmentStart) || !is_int($assignmentEnd) || $assignmentEnd <= $assignmentStart) {
        fail('failed to isolate getting-started branch for: ' . $visibleText);
    }

    return substr($source, $assignmentStart, $assignmentEnd - $assignmentStart);
}

$controllerPath = getenv('TENANT_CONTROLLER_SOURCE');
if (!is_string($controllerPath) || $controllerPath === '') {
    $controllerPath = __DIR__ . '/../src/Controllers/TenantController.php';
}
$source = file_get_contents($controllerPath);
if (!is_string($source) || $source === '') {
    fail('failed to read TenantController source');
}

$start = strpos($source, 'public function gettingStartedPage(Request $request, array $params): Response');
$end = strpos($source, 'public function dashboard(Request $request, array $params): Response', $start ?: 0);
if (!is_int($start) || !is_int($end) || $end <= $start) {
    fail('failed to isolate tenant gettingStartedPage source');
}

$gettingStartedPage = substr($source, $start, $end - $start);
$stateBranches = [
    [
        'No active package yet',
        'getting-started-five-minute-overview',
        bodyAssignmentContaining($gettingStartedPage, 'No active package yet'),
    ],
    [
        'Create your first forward',
        'getting-started-first-forward-checklist',
        bodyAssignmentContaining($gettingStartedPage, 'Create your first forward'),
    ],
    [
        'Install the client',
        'install-client',
        bodyAssignmentContaining($gettingStartedPage, 'Install the client'),
    ],
    [
        'Connect and verify',
        'connect-and-verify',
        bodyAssignmentContaining($gettingStartedPage, 'Connect and verify'),
    ],
];
foreach ($stateBranches as [$state, $topicKey, $branch]) {
    $href = 'href="/knowledgebase?thread=' . $topicKey . '"';
    if (!str_contains($branch, $state) || !str_contains($branch, $href)) {
        fail('missing state-specific getting-started guide pair: ' . $state . ' -> ' . $topicKey);
    }
}

$topicKeys = [
    'getting-started-five-minute-overview',
    'install-client',
    'getting-started-first-forward-checklist',
    'connect-and-verify',
    'no-gui-clients',
    'troubleshooting-common-mistakes',
    'glossary',
];
foreach ($topicKeys as $topicKey) {
    if (preg_match(
        '/href=["\']\/knowledgebase\?thread=' . preg_quote($topicKey, '/') . '(?:&|&amp;)(?:lang|locale)=/i',
        $source
    ) === 1) {
        fail('tenant guide link must not append a locale parameter: ' . $topicKey);
    }

    $href = 'href="/knowledgebase?thread=' . $topicKey . '"';
    if (!str_contains($source, $href)) {
        fail('missing stable tenant guide link: ' . $topicKey);
    }
}

$contextualHelp = sourceBetween(
    $source,
    '<p class="field-note">Need help?',
    '</p>',
    'tenant contextual help'
);
if (preg_match('/<\/a>\s*(?:<[^>]+>\s*)*\?\s*(?:<[^>]+>\s*)*<a\b/is', $contextualHelp) === 1) {
    fail('tenant contextual help still uses malformed question-mark separators');
}
foreach (['Advanced Options', 'Usage Patterns'] as $vagueLabel) {
    if (str_contains($source, $vagueLabel)) {
        fail('tenant contextual help still uses vague label: ' . $vagueLabel);
    }
}

foreach ([
    'Create your first forward',
    'Connect and verify',
    'Troubleshoot a connection',
    'Read the glossary',
] as $label) {
    if (!str_contains($source, $label)) {
        fail('missing descriptive tenant guide label: ' . $label);
    }
}

echo "ok\n";
