<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$nodeSource = file_get_contents(__DIR__ . '/../src/Controllers/NodeController.php');
$adminSource = file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');
if (!is_string($nodeSource) || $nodeSource === '') {
    fail('failed to read NodeController source');
}
if (!is_string($adminSource) || $adminSource === '') {
    fail('failed to read AdminController source');
}

$authoritativePos = strpos($nodeSource, 'private function authoritativeAgentConfig');
if ($authoritativePos === false) {
    fail('NodeController::authoritativeAgentConfig missing');
}
$authoritativeMethod = substr($nodeSource, $authoritativePos, 4200);
if (!str_contains($authoritativeMethod, "'accept_panel_republish'")) {
    fail('NodeController authoritative node management config must include accept_panel_republish');
}
foreach (["\$snapshot['accept_panel_republish'] = true;", 'is_bool($rawAcceptPanelRepublish)', "'false', 'no', 'off'"] as $snippet) {
    if (!str_contains($authoritativeMethod, $snippet)) {
        fail('NodeController must normalize accept_panel_republish and default it to true: ' . $snippet);
    }
}
if (!str_contains($authoritativeMethod, "'disable_auto_update'")) {
    fail('NodeController authoritative node management config must include disable_auto_update so panel can unpin stale slaves');
}
foreach (["\$snapshot['disable_auto_update'] = false;", 'is_bool($rawDisableAutoUpdate)'] as $snippet) {
    if (!str_contains($authoritativeMethod, $snippet)) {
        fail('NodeController must normalize disable_auto_update and default it to false: ' . $snippet);
    }
}


$savePos = strpos($adminSource, 'public function saveNodeManagement');
if ($savePos === false) {
    fail('AdminController::saveNodeManagement missing');
}
$saveMethod = substr($adminSource, $savePos, 5200);
if (!str_contains($saveMethod, "'accept_panel_republish'")) {
    fail('AdminController saveNodeManagement must preserve accept_panel_republish when saving node management');
}

$preservePos = strpos($saveMethod, '$preservedRuntimeConfig = []');
$mergePos = strpos($saveMethod, '$agentConfig = array_merge');
if ($preservePos === false || $mergePos === false || $mergePos <= $preservePos) {
    fail('AdminController saveNodeManagement preserve/merge structure changed unexpectedly');
}
$preserveBlock = substr($saveMethod, $preservePos, $mergePos - $preservePos);
if (!str_contains($preserveBlock, "'accept_panel_republish'")) {
    fail('accept_panel_republish must be preserved from existing agent_config_json, not only accepted from extra JSON');
}

echo "ok
";
