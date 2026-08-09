<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$service = file_get_contents(__DIR__ . '/../src/Controllers/ServiceController.php');
$tenant = file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');
if (!is_string($service) || !is_string($tenant)) {
    fail('failed to read compatibility controllers');
}

foreach ([
    'ForwardLineApiContract',
    'normalizeLegacyCreate',
    'normalizeLegacyUpdate',
    'allow_manage_forwards',
    'allow_delete_forwards',
    'service_inactive',
    'forward_quota_exceeded',
    'legacyForwardErrorResponse',
    "'Deprecation' => 'true'",
    'private function requireTenant(Request $request): array',
] as $required) {
    if (!str_contains($service . $tenant, $required)) {
        fail('missing compatibility security gate: ' . $required);
    }
}

if (substr_count($service, '$this->requireTenant($request)') < 7) {
    fail('every legacy user service endpoint must enforce the tenant role');
}
if (!str_contains($service, 'private function safeForwardMutation(callable $callback): mixed')
    || substr_count($service, '$this->safeForwardMutation(') < 3) {
    fail('legacy forward provisioning calls must normalize internal failures before the global error envelope');
}

if (str_contains($service, "'client_config' => \$this->provisioning->createForward")) {
    fail('legacy create still returns runtime client config');
}
if (str_contains($service, "'client_config' => \$this->provisioning->updateForwardNonStructural")) {
    fail('legacy update still returns runtime client config');
}

$updateStart = strpos($service, 'public function updateForward(');
$updateEnd = strpos($service, 'public function deleteForward(', $updateStart === false ? 0 : $updateStart);
if ($updateStart === false || $updateEnd === false || $updateEnd <= $updateStart) {
    fail('failed to isolate legacy update method');
}
$update = substr($service, $updateStart, $updateEnd - $updateStart);
if (!str_contains($update, 'ForwardLineApiContract::mergePatch($forward, $changes)')) {
    fail('legacy update must preserve target-source invariants through the shared merge contract');
}
if (!str_contains($update, "(int) (\$forward['api_revision'] ?? 1)")) {
    fail('legacy update must pass the loaded revision into the transaction');
}
if (!str_contains($update, "array_key_exists('transport_mode', \$changes)")) {
    fail('legacy transport changes must use structural availability validation');
}

if (!str_contains($service, "'endpoint_count'")) {
    fail('legacy endpoint_count must remain accepted-and-ignored for compatibility');
}
if (!str_contains($service, "'udp+tcp', 'both' => 'tcp+udp'")) {
    fail('legacy transport aliases must remain compatible after strict normalization');
}
if (str_contains($service, "'forward_combination_id' =>")) {
    fail('legacy compatibility must not reopen internal combination selection');
}

$deleteStart = strpos($service, 'public function deleteForward(');
$deleteEnd = strpos($service, 'public function clientConfig(', $deleteStart === false ? 0 : $deleteStart);
$delete = ($deleteStart !== false && $deleteEnd !== false && $deleteEnd > $deleteStart)
    ? substr($service, $deleteStart, $deleteEnd - $deleteStart)
    : '';
if (!str_contains($delete, "(int) (\$forward['api_revision'] ?? 1)")) {
    fail('legacy delete must pass the loaded revision into the transaction');
}

$normalizerStart = strpos($tenant, 'private function normalizeTenantForwardPayload(');
$normalizerEnd = strpos($tenant, 'private function isBondReady(', $normalizerStart === false ? 0 : $normalizerStart);
if ($normalizerStart === false || $normalizerEnd === false || $normalizerEnd <= $normalizerStart) {
    fail('failed to isolate tenant forward payload normalizers');
}
$normalizers = substr($tenant, $normalizerStart, $normalizerEnd - $normalizerStart);
if (str_contains($normalizers, "default => 'tcp'")) {
    fail('tenant forward normalizers must not silently coerce unknown transports to tcp');
}
if (!str_contains($normalizers, 'default => $transportInput')) {
    fail('tenant forward normalizers must preserve invalid transports for strict contract rejection');
}

echo "ok\n";
