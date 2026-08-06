<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/fixtures/tenant_user_guide_fixture.php');
if (!is_string($source) || $source === '') {
    fwrite(STDERR, "tenant screenshot fixture missing\n");
    exit(1);
}

foreach ([
    'overview', 'forward-form', 'client-install', 'forward-result', 'diagnostics', 'target-endpoint',
    'Forward Route', 'Target Host', 'Target Port', 'Transport', 'Run Diagnostics',
    'View Diagnostics', 'Transport Listen Port', 'Alternate Listen Port', 'CF Listen Port',
] as $required) {
    if (!str_contains($source, $required)) {
        fwrite(STDERR, "fixture missing: {$required}\n");
        exit(1);
    }
}

foreach (['password', 'bearer', 'private key', 'real_ip', '198.51.100.10'] as $forbidden) {
    if (str_contains(strtolower($source), strtolower($forbidden))) {
        fwrite(STDERR, "fixture contains forbidden secret marker: {$forbidden}\n");
        exit(1);
    }
}

echo "tenant_user_guide_fixture_test: OK\n";
