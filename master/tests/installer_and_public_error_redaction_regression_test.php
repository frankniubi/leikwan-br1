<?php
declare(strict_types=1);

function failInstallerRedaction(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$installer = file_get_contents(__DIR__ . '/../public/install.php') ?: '';
$frontController = file_get_contents(__DIR__ . '/../public/index.php') ?: '';
$env = file_get_contents(__DIR__ . '/../.env.example') ?: '';
$migrationRunner = file_get_contents(__DIR__ . '/../src/Support/MigrationRunner.php') ?: '';

foreach (['$throwable->getMessage()', '$exception->getMessage()'] as $forbidden) {
    if (str_contains($installer, $forbidden)) {
        failInstallerRedaction('installer exposes exception text through ' . $forbidden);
    }
}
$publicBoundary = strpos($frontController, 'function publicErrorResponse(');
if ($publicBoundary === false
    || str_contains($frontController, '$exception->details()')
    || str_contains($frontController, "'error' => \$exception->getMessage()")) {
    failInstallerRedaction('front controller must route all uncaught errors through a redacted responder');
}
foreach (['internal_error', 'reference_id', 'Cache-Control', 'X-Content-Type-Options'] as $required) {
    if (!str_contains($frontController, $required)) {
        failInstallerRedaction('front controller redaction boundary missing: ' . $required);
    }
}

foreach ([
    'INSTALLER_ACCESS_TOKEN',
    'hash_equals(',
    'installer_csrf_token',
    'Cache-Control: no-store',
    'Install / update could not be completed. Check the server logs.',
    'bootstrapInstallerToken(',
    'flock(',
    'rename(',
    'chmod(',
    'LOCK_NB',
    'installer_env_value_invalid',
] as $required) {
    if (!str_contains($installer . $env, $required)) {
        failInstallerRedaction('installer hardening missing: ' . $required);
    }
}
foreach ([
    'name="DB_PASSWORD" value="\' . h((string) $formValues[\'DB_PASSWORD\'])',
    'name="WHMCS_API_SECRET" value="\' . h((string) $formValues[\'WHMCS_API_SECRET\'])',
    'name="MASTER_ADMIN_TOKEN" value="\' . h((string) $formValues[\'MASTER_ADMIN_TOKEN\'])',
] as $forbidden) {
    if (str_contains($installer, $forbidden)) {
        failInstallerRedaction('installer must not prefill stored secret fields: ' . $forbidden);
    }
}

$statusStart = strpos($migrationRunner, 'public function status(): array');
$statusEnd = strpos($migrationRunner, 'private function runMigration(', $statusStart === false ? 0 : $statusStart);
$statusMethod = ($statusStart !== false && $statusEnd !== false)
    ? substr($migrationRunner, $statusStart, $statusEnd - $statusStart)
    : '';
foreach (['try {', "throw new ApiException('migration_status_failed', 500)"] as $required) {
    if (!str_contains($statusMethod, $required)) {
        failInstallerRedaction('migration status must return a stable error boundary: ' . $required);
    }
}

echo "ok\n";
