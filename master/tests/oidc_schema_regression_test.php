<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function tableBlock(string $sql, string $table): string
{
    if (preg_match('/CREATE TABLE IF NOT EXISTS ' . preg_quote($table, '/') . '\s*\((.*?)\n\);/si', $sql, $match) !== 1) {
        fail("missing {$table} table");
    }
    return $match[1];
}

function assertContains(string $haystack, string $needle, string $message): void
{
    if (!str_contains($haystack, $needle)) {
        fail($message . ': missing ' . $needle);
    }
}

$root = __DIR__ . '/..';
$schema = file_get_contents($root . '/migrations/schema.sql');
$migration = file_get_contents($root . '/migrations/20260727_add_oidc_identities.sql');
if (!is_string($schema) || !is_string($migration)) {
    fail('OIDC schema or migration is unreadable');
}

foreach (['fresh schema' => $schema, 'migration' => $migration] as $label => $sql) {
    $block = $label === 'fresh schema' ? tableBlock($sql, 'oidc_identities') : $sql;
    foreach ([
        'user_id BIGINT UNSIGNED NOT NULL',
        'issuer TEXT NOT NULL',
        'subject TEXT NOT NULL',
        'issuer_hash CHAR(64) NOT NULL',
        'subject_hash CHAR(64) NOT NULL',
        'email VARCHAR(191) NOT NULL',
        'email_verified_at DATETIME NULL',
        'last_login_at DATETIME NULL',
        'created_at DATETIME NOT NULL',
        'updated_at DATETIME NOT NULL',
        'UNIQUE KEY uniq_oidc_identity_subject (issuer_hash, subject_hash)',
        'UNIQUE KEY uniq_oidc_identity_user_issuer (user_id, issuer_hash)',
        'CONSTRAINT fk_oidc_identities_user FOREIGN KEY (user_id) REFERENCES users(id)',
        'INDEX idx_oidc_identities_user (user_id)',
        'INDEX idx_oidc_identities_email (email)',
    ] as $required) {
        assertContains($block, $required, $label . ' OIDC definition');
    }
}

echo "ok\n";
