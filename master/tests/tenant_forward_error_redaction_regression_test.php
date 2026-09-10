<?php
declare(strict_types=1);

function fail_tenant_forward_error_redaction(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');
if (!is_string($source)) {
    fail_tenant_forward_error_redaction('failed to read TenantController');
}
$start = strpos($source, 'private function forwardCreateFlashMessage(');
$end = strpos($source, 'private function runLocalCommand(', $start === false ? 0 : $start);
if ($start === false || $end === false || $end <= $start) {
    fail_tenant_forward_error_redaction('failed to isolate forward error presenter');
}
$method = substr($source, $start, $end - $start);
foreach (['route_unavailable', 'forward_version_conflict', 'The selected route is currently unavailable.'] as $required) {
    if (!str_contains($method, $required)) {
        fail_tenant_forward_error_redaction('missing safe forward error mapping: ' . $required);
    }
}
foreach (['available_node_groups', 'nodes_missing_public_host', "\$node['name']", "return 'Failed to create forward: ' . \$message", "return 'Failed to create forward: ' . \$throwable->getMessage()"] as $forbidden) {
    if (str_contains($method, $forbidden)) {
        fail_tenant_forward_error_redaction('forward error presenter leaks internal detail through: ' . $forbidden);
    }
}

foreach ([
    ['public function reactivateForwards(', 'public function purchasePackage('],
    ['public function quickCreateForward(', 'public function editForward('],
    ['public function editForward(', 'public function saveForwardEdit('],
    ['public function saveForwardEdit(', 'public function forwardWizard('],
    ['public function createForward(', 'public function generateEnrollmentToken('],
    ['public function deleteForward(', 'public function regenerateClientToken('],
] as [$methodStart, $methodEnd]) {
    $start = strpos($source, $methodStart);
    $end = strpos($source, $methodEnd, $start === false ? 0 : $start);
    $guardedMethod = ($start !== false && $end !== false) ? substr($source, $start, $end - $start) : '';
    $try = strpos($guardedMethod, 'try {');
    $userLookup = strpos($guardedMethod, 'SessionAuth::currentUser(');
    if ($try === false || $userLookup === false || $try > $userLookup) {
        fail_tenant_forward_error_redaction($methodStart . ' must guard session and database preflight failures');
    }
    $idLookup = strpos($guardedMethod, '$this->idParam(');
    if ($idLookup !== false && $try > $idLookup) {
        fail_tenant_forward_error_redaction($methodStart . ' must guard path identifier parsing');
    }
    if (str_contains($guardedMethod, '$throwable->getMessage()')) {
        fail_tenant_forward_error_redaction($methodStart . ' leaks exception messages');
    }
}

echo "ok\n";
