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
$end = strpos($source, 'public function resetDefaultClientToken(Request $request, array $params): Response');
if (!is_int($start) || !is_int($end) || $end <= $start) {
    fail('failed to isolate tenant clientsPage source');
}

$clientsPage = substr($source, $start, $end - $start);

foreach ([
    'defaultClientForUser(',
    'ensureDefaultClientForUser(',
    'activeCountForClient(',
    'activeSessionsForClient(',
    'clientAgentInstallCommand(',
    'windowsClientAppUrl()',
    '最大在线',
    '在线客户端',
    '客户端版本',
    '踢走',
    '复制命令',
    '下载 installer',
    '/tenant/clients/reset-token',
] as $required) {
    if (!str_contains($clientsPage, $required)) {
        fail('missing compact tenant client page snippet: ' . $required);
    }
}

foreach ([
    'listClientsForUser(',
    'maxClientProfilesForTenantOwnedClients(',
    'availableClientGroupsForUser(',
    'availableForwardChainsForService(',
    'BondLegReputationService',
    'scopedRowsForTenant(',
    'recentEvents(',
    'assignedForwardConfigsForClient(',
    'fetchAll(',
    'serviceSummary(',
    'defaultClientOnlineDeviceLimitForTenant(',
    'viewTabs(',
    '?view=',
    'Android',
    'Client Profiles',
    'System distribution',
    'system distribution',
    '安装次数',
    '/diagnose',
    '/request-update',
    '/delete',
] as $forbidden) {
    if (str_contains($clientsPage, $forbidden)) {
        fail('legacy tenant client page surface remains: ' . $forbidden);
    }
}

if (substr_count($clientsPage, '最大在线') !== 1) {
    fail('expected exactly one global online label');
}
if (substr_count($clientsPage, 'activeCountForClient(') !== 1) {
    fail('tenant Client page must use exactly one active online count query');
}
if (substr_count($clientsPage, 'defaultClientForUser(') !== 1
    || substr_count($clientsPage, 'ensureDefaultClientForUser(') !== 1) {
    fail('tenant Client page default resolution budget changed');
}

$resetStart = strpos($source, 'public function resetDefaultClientToken(Request $request, array $params): Response');
$resetEnd = strpos($source, 'public function sidecarsPage(Request $request, array $params): Response', $resetStart ?: 0);
if (!is_int($resetStart) || !is_int($resetEnd) || $resetEnd <= $resetStart) {
    fail('failed to isolate reset route method');
}
$resetMethod = substr($source, $resetStart, $resetEnd - $resetStart);
if (str_contains($resetMethod, "input('client_id'")
    || str_contains($resetMethod, 'input("client_id"')
    || str_contains($resetMethod, "input('agent_token'")
    || str_contains($resetMethod, 'input("agent_token"')) {
    fail('reset route must not trust browser client id or token');
}

echo "ok\n";
