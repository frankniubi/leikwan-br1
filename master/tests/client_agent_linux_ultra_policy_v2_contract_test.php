<?php
declare(strict_types=1);

function failLinuxUltraPolicy(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Controllers/ClientAgentController.php');
if ($source === false) {
    failLinuxUltraPolicy('failed to read ClientAgentController');
}

if (!str_contains($source, "str_starts_with(\$platform, 'linux')")) {
    failLinuxUltraPolicy('Linux pull requests must select the Ultra v2 policy path');
}

if (!str_contains($source, "str_starts_with(\$platform, 'windows')")
    || !str_contains($source, "str_starts_with(\$platform, 'linux')")) {
    failLinuxUltraPolicy('Windows and Linux platform gates must both select Ultra v2');
}

if (!str_contains($source, "artifactMetadata('install_forward_runtime')")) {
    failLinuxUltraPolicy('client config snapshot must load install_forward_runtime metadata');
}

if (!str_contains($source, "'desired_install_forward_runtime_sha256'")) {
    failLinuxUltraPolicy('client pull must publish the install_forward_runtime hash');
}

if (!str_contains($source, "'ultra_lease_ticket_rotated client=%d forward=%d device=%s'")) {
    failLinuxUltraPolicy('Ultra lease rotation diagnostics are missing');
}
if (!str_contains($source, "(int) (\$client['id'] ?? 0),")) {
    failLinuxUltraPolicy('Ultra lease rotation diagnostics must use the authenticated client id');
}
if (preg_match('/ultra_lease_ticket_rotated[\s\S]{0,160}\$clientId/', $source) === 1) {
    failLinuxUltraPolicy('Ultra lease rotation diagnostics use undefined $clientId and can corrupt JSON responses');
}
foreach (["\$failures = [];", "'ultra_lease_forward_refused client=%d forward=%d device=%s error=%s'", "'failures' => \$failures"] as $needle) {
    if (!str_contains($source, $needle)) {
        failLinuxUltraPolicy('Ultra lease response diagnostics are missing: ' . $needle);
    }
}

echo "ok\n";
