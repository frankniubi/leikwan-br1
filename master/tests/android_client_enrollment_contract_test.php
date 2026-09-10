<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function source(string $path): string
{
    $value = file_get_contents($path);
    if (!is_string($value) || $value === '') {
        fail('could not read ' . $path);
    }
    return $value;
}

function section(string $source, string $start, string $end = ''): string
{
    $offset = strpos($source, $start);
    if (!is_int($offset)) {
        fail('could not find source section: ' . $start);
    }
    if ($end === '') {
        return substr($source, $offset);
    }
    $endOffset = strpos($source, $end, $offset + strlen($start));
    if (!is_int($endOffset)) {
        fail('could not bound source section: ' . $start);
    }
    return substr($source, $offset, $endOffset - $offset);
}

$root = __DIR__ . '/..';
$bootstrap = source($root . '/src/Bootstrap.php');
$controller = source($root . '/src/Controllers/ClientAgentController.php');
$tenant = source($root . '/src/Controllers/TenantController.php');
$base = source($root . '/src/Controllers/BaseController.php');
$publicIndex = source($root . '/public/index.php');
$codeService = source($root . '/src/Support/AndroidEnrollmentCodeService.php');
$ultraLeaseService = source($root . '/src/Support/UltraLeaseService.php');
$deviceControlService = source($root . '/src/Support/DeviceControlService.php');

foreach ([
    "'/api/client/devices/challenge'",
    "'/api/client/devices/enroll'",
    "'/api/client/devices/renew/challenge'",
    "'/api/client/devices/renew'",
    "'/api/client/devices/identity/enroll'",
    "'/api/client/devices/code/identity-enroll'",
    "'/api/client/devices/ack'",
    "'/api/client/devices/code/challenge'",
    "'/api/client/devices/code/enroll'",
    "'/tenant/clients/kick'",
    "'/tenant/clients/ban'",
    "'/tenant/clients/unban'",
    "'/tenant/clients/android-enrollment-code'",
] as $route) {
    if (!str_contains($bootstrap, $route)) {
        fail('missing Android device-control route: ' . $route);
    }
}

foreach ([
    'public function deviceChallenge(',
    'public function enrollDevice(',
    'public function deviceRenewalChallenge(',
    'public function renewDevice(',
    'public function deviceIdentityEnroll(',
    'public function enrollmentCodeIdentityEnroll(',
    'public function acknowledgeDevice(',
    'public function enrollmentCodeChallenge(',
    'public function enrollmentCodeEnroll(',
    'requireActive(',
    'isAndroidRequest(',
    'DeviceControlService',
] as $required) {
    if (!str_contains($controller, $required)) {
        fail('missing Android controller contract: ' . $required);
    }
}

foreach (['ANDROID_ATTESTATION_SIGNING_CERT_SHA256', 'ANDROID_ATTESTATION_ROOT_CERT_SHA256'] as $required) {
    if (!str_contains($deviceControlService, $required)) {
        fail('missing attestation trust configuration: ' . $required);
    }
}

foreach ([
    'public function issueRenewalChallenge(',
    'public function renew(',
    'public function enrollIdentity(',
    'LEIKWAN_ANDROID_SESSION_RENEWAL_V1',
    'device_renewal_not_available',
] as $required) {
    if (!str_contains($deviceControlService, $required)) {
        fail('missing trusted-device renewal contract: ' . $required);
    }
}

if (!str_contains($ultraLeaseService, 'public function revokeDevice(')) {
    fail('kick and ban must revoke Ultra leases for the controlled device');
}

foreach ([
    'public function banClientDevice(',
    'public function unbanClientDevice(',
    'public function createAndroidEnrollmentCode(',
    'setState(',
    'controlsForClient(',
] as $required) {
    if (!str_contains($tenant, $required)) {
        fail('missing tenant device-control action: ' . $required);
    }
}

foreach ([
    'code_hash',
    'expires_at',
    'consumed_at',
    'UPDATE android_enrollment_codes',
] as $required) {
    if (!str_contains($codeService, $required)) {
        fail('missing one-time enrollment code control: ' . $required);
    }
}

if (str_contains($base, 'clientAgentAndroidImportCommand')
    || str_contains($base, 'agent_token=')
    || str_contains($base, 'leikwan-android://')) {
    fail('Android import links must not carry an agent token or custom raw-token URI');
}

foreach ([
    "'device_kicked'",
    "'device_banned'",
    "'device_untrusted'",
    "'ok' => false",
    "\$payload['command']",
] as $required) {
    if (!str_contains($publicIndex, $required)) {
        fail('missing structured terminal Android error response: ' . $required);
    }
}

$terminalResponse = section($publicIndex, '$clientSafeCodes = [', '    if ($status < 400 || $status >= 500)');
if (str_contains($terminalResponse, 'endpoint')
    || str_contains($terminalResponse, 'lease')
    || str_contains($terminalResponse, 'credential')
    || str_contains($terminalResponse, 'forward')) {
    fail('terminal Android errors must not contain config or lease material');
}

echo "ok\n";
