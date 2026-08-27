<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertContains(string $source, string $needle, string $message): void
{
    if (!str_contains($source, $needle)) {
        fail($message . ': ' . $needle);
    }
}

function methodBlock(string $source, string $method, string $nextMethod): string
{
    $start = strpos($source, 'function ' . $method . '(');
    $end = strpos($source, 'function ' . $nextMethod . '(', $start === false ? 0 : $start + 1);
    if ($start === false || $end === false) {
        fail('missing method boundary for ' . $method);
    }
    return substr($source, $start, $end - $start);
}

$controller = (string) file_get_contents(__DIR__ . '/../src/Controllers/ClientAgentController.php');
$service = (string) file_get_contents(__DIR__ . '/../src/Support/AccessPolicyService.php');

foreach (['locationChallenge', 'locationReport', 'accessPolicyStatus'] as $method) {
    assertContains($controller, 'function ' . $method . '(', 'shared access-policy endpoint missing');
}
assertContains($controller, '?AccessPolicyService $accessPolicy = null', 'controller optional injection missing');
assertContains($controller, '$this->accessPolicy = $accessPolicy ?? new AccessPolicyService(', 'controller default policy service missing');

$enforce = methodBlock($controller, 'enforceClientRequest', 'clientOnlineSessions');
assertContains($enforce, 'authorizeClientRequest(', 'protected client requests must call access policy');
$policyPosition = strpos($enforce, 'authorizeClientRequest(');
$sessionPosition = strpos($enforce, 'enforceFromRequest(');
if ($policyPosition === false || $sessionPosition === false || $policyPosition > $sessionPosition) {
    fail('access policy must run before recording/returning a usable client session');
}

$enroll = methodBlock($controller, 'enrollDevice', 'acknowledgeDevice');
assertContains($enroll, 'authorizeClientRequest(', 'enrollment must enforce access policy');
if (strpos($enroll, 'authorizeClientRequest(') > strpos($enroll, '->enroll(')) {
    fail('access policy must run before device enrollment');
}

$select = methodBlock($controller, 'selectDesktopClient', 'enforceClientRequest');
assertContains($select, 'authorizeClientRequest(', 'desktop client selection must enforce access policy');
if (strpos($select, 'authorizeClientRequest(') > strpos($select, "'agent_token'")) {
    fail('access policy must run before returning an agent token');
}

$challenge = methodBlock($controller, 'locationChallenge', 'locationReport');
if (str_contains($challenge, 'requireAndroidRequest')) {
    fail('location challenge must not contain an Android-only guard');
}
assertContains($service, 'public function policyStatus(', 'safe policy status method missing');
assertContains($service, 'public function authorizeClientRequest(', 'shared authorization hook missing');

echo "ok\n";
