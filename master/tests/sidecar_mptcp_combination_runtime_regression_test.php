<?php
declare(strict_types=1);

function fail_sidecar_mptcp_combination_runtime(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$controllerSource = file_get_contents(__DIR__ . '/../src/Controllers/SidecarAgentController.php');
$serviceSource = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');

if ($controllerSource === false || $serviceSource === false) {
    fail_sidecar_mptcp_combination_runtime('failed to read panel sources');
}

foreach ([
    'service exposes active mptcp runtime rows' => 'public function mptcpRuntimeRowsForSidecarPoint',
    'query includes active mptcp forwards regardless of combination' => 'f.client_mode = :client_mode',
    'controller fetches mptcp runtime rows' => '$mptcpRuntimeRows = $this->provisioning->mptcpRuntimeRowsForSidecarPoint',
    'controller enables mptcp runtime from rows' => '$mptcpRuntimeRows !== []',
    'controller routes mptcp runtime rows' => '}, $mptcpRuntimeRows))',
] as $label => $snippet) {
    if (!str_contains($controllerSource . $serviceSource, $snippet)) {
        fail_sidecar_mptcp_combination_runtime("missing {$label}: {$snippet}");
    }
}

$runtimeRowsPos = strpos($serviceSource, 'public function mptcpRuntimeRowsForSidecarPoint');
$sidecarManagementPos = strpos($serviceSource, 'public function mptcpSidecarManagementForPoint');
if ($runtimeRowsPos === false || $sidecarManagementPos === false || $sidecarManagementPos <= $runtimeRowsPos) {
    fail_sidecar_mptcp_combination_runtime('failed to isolate mptcp runtime rows method');
}
$runtimeRowsMethod = substr($serviceSource, $runtimeRowsPos, $sidecarManagementPos - $runtimeRowsPos);
if (str_contains($runtimeRowsMethod, 'forward_combination_id IS NULL') || str_contains($runtimeRowsMethod, 'forward_combination_id = 0')) {
    fail_sidecar_mptcp_combination_runtime('mptcp runtime activation must not exclude forward-combination forwards');
}

echo "ok\n";
