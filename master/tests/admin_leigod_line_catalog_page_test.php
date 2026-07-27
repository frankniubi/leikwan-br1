<?php
declare(strict_types=1);

$bootstrap = file_get_contents(__DIR__ . '/../src/Bootstrap.php') ?: '';
$base = file_get_contents(__DIR__ . '/../src/Controllers/BaseController.php') ?: '';
$admin = file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php') ?: '';
$provisioning = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php') ?: '';
$failures = 0;

function requireCatalogSnippet(string $needle, string $source, string $where, int &$failures): void
{
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, 'FAIL: ' . $where . ' missing ' . $needle . PHP_EOL);
        $failures++;
    }
}

foreach ([
    "add('GET', '/admin/leigod-lines'",
    "add('POST', '/admin/leigod-lines'",
    "add('POST', '/admin/leigod-lines/{id}/delete'",
] as $needle) {
    requireCatalogSnippet($needle, $bootstrap, 'Bootstrap.php', $failures);
}

foreach ([
    'function leigodLinesPage(',
    'function saveLeigodLine(',
    'function deleteLeigodLine(',
    "SessionAuth::validateCsrf(\$request)",
    'name="line_id"',
    'name="label"',
    'name="tcp_probe_host"',
    'name="tcp_probe_port"',
    'name="udp_probe_host"',
    'name="udp_probe_port"',
    'name="udp_probe_method"',
    'name="config_sha256"',
    'name="is_active"',
    'name="sort_order"',
    'configuration_state',
    'tcp_healthy_nodes',
    'udp_healthy_nodes',
    'last_checked_at',
    'last_error',
] as $needle) {
    requireCatalogSnippet($needle, $admin, 'AdminController.php', $failures);
}

foreach (['listLeigodLines(', 'saveLeigodLine(', 'deleteLeigodLine('] as $needle) {
    requireCatalogSnippet($needle, $provisioning, 'ProvisioningService.php', $failures);
}

requireCatalogSnippet("'/admin/leigod-lines'", $base, 'BaseController.php', $failures);

foreach (['LEIGOD_SESSION_JSON_B64', 'LEIGOD_LINE_TOML_', "['toml']", 'session_json'] as $forbidden) {
    if (str_contains($admin, $forbidden)) {
        fwrite(STDERR, 'FAIL: AdminController.php exposes forbidden secret surface ' . $forbidden . PHP_EOL);
        $failures++;
    }
}

if ($failures > 0) {
    fwrite(STDERR, $failures . " FAIL\n");
    exit(1);
}
echo "ok\n";
