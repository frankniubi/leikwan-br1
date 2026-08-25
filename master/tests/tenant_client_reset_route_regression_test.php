<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$bootstrap = (string) file_get_contents(__DIR__ . '/../src/Bootstrap.php');
$tenant = (string) file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');
$provisioning = (string) file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');
if ($bootstrap === '' || $tenant === '' || $provisioning === '') {
    fail('failed to read reset route sources');
}

foreach ([
    "\$this->router->add('POST', '/tenant/clients/reset-token', [\$tenant, 'resetDefaultClientToken']);",
    'public function resetDefaultClientToken(Request $request, array $params): Response',
    'SessionAuth::validateCsrf($request);',
    'rotateDefaultClientTokenForUser(',
    "'allow_rotate_client_token'",
    "Response::redirect('/tenant/clients')",
] as $required) {
    if (!str_contains($bootstrap . $tenant . $provisioning, $required)) {
        fail('missing reset-token contract: ' . $required);
    }
}

$start = strpos($tenant, 'public function resetDefaultClientToken(');
$end = strpos($tenant, 'public function sidecarsPage(', $start ?: 0);
if (!is_int($start) || !is_int($end) || $end <= $start) {
    fail('failed to isolate reset route method');
}
$resetMethod = substr($tenant, $start, $end - $start);
foreach (["input('client_id'", 'input("client_id"', "input('agent_token'", 'input("agent_token"', '$throwable->getMessage()'] as $forbidden) {
    if (str_contains($resetMethod, $forbidden)) {
        fail('reset route must not trust or echo secret input: ' . $forbidden);
    }
}
if (!str_contains($resetMethod, "SessionAuth::flash('error', 'Client token reset failed.')")) {
    fail('reset route must use a fixed generic error flash');
}

echo "ok\n";
