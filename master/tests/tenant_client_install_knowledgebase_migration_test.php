<?php
declare(strict_types=1);

$path = __DIR__ . '/../migrations/20260825_refresh_tenant_client_install_surface.sql';
$migration = is_file($path) ? (string) file_get_contents($path) : '';
if ($migration === '') {
    fwrite(STDERR, "missing tenant Client knowledgebase migration\n");
    exit(1);
}

foreach ([
    "topic_key = 'install-client'",
    "locale = 'en'",
    "locale = 'zh-Hans'",
    "locale = 'zh-TW'",
    'updated_at <',
    'Download installer',
    'Copy command',
    '最大在线',
    'CURRENT_TIMESTAMP',
] as $required) {
    if (!str_contains($migration, $required)) {
        fwrite(STDERR, 'migration missing required contract: ' . $required . "\n");
        exit(1);
    }
}

foreach (['Download Android App', 'Android App', '?view=install', 'Run Diagnostics', 'View Diagnostics', 'Client Profiles'] as $forbidden) {
    if (str_contains($migration, $forbidden)) {
        fwrite(STDERR, 'migration contains retired Client guidance: ' . $forbidden . "\n");
        exit(1);
    }
}

foreach (['CREATE TABLE', 'DROP TABLE', 'ALTER TABLE'] as $forbiddenSql) {
    if (preg_match('/\b' . $forbiddenSql . '\b/i', $migration) === 1) {
        fwrite(STDERR, 'migration must not change schema: ' . $forbiddenSql . "\n");
        exit(1);
    }
}

echo "ok\n";
