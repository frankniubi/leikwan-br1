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
    'Download Windows App',
    'Download Android App',
    'Online device limit',
    'Linux Install',
    'Online devices',
    'These are only defaults for future forwards. They are not required for app install.',
    'Keep this simple. Install Windows or Android from the top of this page; use Linux Install only for Linux hosts.',
] as $required) {
    if (!str_contains($clientsPage, $required)) {
        fail('missing simplified tenant client page snippet: ' . $required);
    }
}

foreach ([
    'Install details',
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

if (!str_contains($clientsPage, '$canManageClients = !empty($features[\'allow_manage_clients\']);')) {
    fail('tenant clientsPage does not compute canManageClients from allow_manage_clients');
}
if (!str_contains($clientsPage, '$clientFormSection = \'\';')
    || !str_contains($clientsPage, 'if ($canManageClients) {')
    || !str_contains($clientsPage, '$clientFormSection = \'')
    || !str_contains($clientsPage, "<h3>' . (\$editing !== null ? 'Edit Client' : 'Create Client') . '</h3>")
    || !str_contains($clientsPage, '$clientFormSection .')) {
    fail('tenant create/edit client form is not rendered only through a canManageClients-gated section');
}

$clientCardsStart = strpos($clientsPage, '$clientCards .=');
$clientCardsEnd = strpos($clientsPage, '$groupOptions =', $clientCardsStart ?: 0);
if (!is_int($clientCardsStart) || !is_int($clientCardsEnd) || $clientCardsEnd <= $clientCardsStart) {
    fail('failed to isolate tenant client card source');
}
$clientCardsBlock = substr($clientsPage, $clientCardsStart, $clientCardsEnd - $clientCardsStart);
if (!str_contains($clientCardsBlock, '<summary>Linux Install</summary>')) {
    fail('missing Linux Install details summary in client cards');
}
foreach (['Download Windows App', 'Download Android App', 'Windows App', 'Android App', 'renderClientAgentInstallDetails('] as $staleInstallSnippet) {
    if (str_contains($clientCardsBlock, $staleInstallSnippet)) {
        fail('per-client Linux Install/card still includes non-Linux app install snippet: ' . $staleInstallSnippet);
    }
}

$bodyStart = strpos($clientsPage, '$body =');
$bodyEnd = strpos($clientsPage, '<script>', $bodyStart ?: 0);
if (!is_int($bodyStart) || !is_int($bodyEnd) || $bodyEnd <= $bodyStart) {
    fail('failed to isolate tenant body source');
}
$bodyBlock = substr($clientsPage, $bodyStart, $bodyEnd - $bodyStart);
$quickStartPos = strpos($bodyBlock, '<h3>Quick start</h3>');
$windowsButtonPos = strpos($bodyBlock, 'Download Windows App');
$androidButtonPos = strpos($bodyBlock, 'Download Android App');
$clientListPos = strpos($bodyBlock, 'My Client List');
if ($quickStartPos === false || $windowsButtonPos === false || $androidButtonPos === false) {
    fail('missing top quick-start app install buttons');
}
if (!($quickStartPos < $windowsButtonPos && $windowsButtonPos < $clientListPos)) {
    fail('Windows install button must be in the top quick-start area before the client list');
}
if (!($quickStartPos < $androidButtonPos && $androidButtonPos < $clientListPos)) {
    fail('Android install button must be in the top quick-start area before the client list');
}

echo "ok\n";
