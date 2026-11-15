<?php
declare(strict_types=1);

// Opt-in: this creates and removes uniquely named schemas on a local test DB.
if (getenv('LEIKWAN_TEST_MYSQL') !== '1') {
    echo "skip: set LEIKWAN_TEST_MYSQL=1 for the isolated MariaDB migration test\n";
    exit(0);
}

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Support\MigrationRunner;

$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'Leikwan\\Master\\';
    if (str_starts_with($class, $prefix)) {
        require_once $root . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

$host = getenv('LEIKWAN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = getenv('LEIKWAN_TEST_MYSQL_PORT') ?: '33306';
$user = getenv('LEIKWAN_TEST_MYSQL_USER') ?: 'root';
$password = getenv('LEIKWAN_TEST_MYSQL_PASSWORD') ?: '';
if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
    throw new RuntimeException('migration rehearsal requires a local database');
}
$admin = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$temp = sys_get_temp_dir() . '/lk-debug-migration-' . bin2hex(random_bytes(8));
mkdir($temp, 0700);
$migration = '20261115_isolate_debug_uploads.sql';
copy($root . '/migrations/' . $migration, $temp . '/' . $migration);
$schemas = [];

try {
    foreach (['legacy', 'fresh'] as $variant) {
        $schema = 'lk_debug_test_' . bin2hex(random_bytes(8));
        $admin->exec("CREATE DATABASE `{$schema}` CHARACTER SET utf8mb4");
        $schemas[] = $schema;
        $db = new Database(new Config($temp . '/missing.env'));
        $pdo = new PDO("mysql:host={$host};port={$port};dbname={$schema};charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $db->setPdo($pdo);
        $uploadColumn = $variant === 'fresh' ? "upload_id VARCHAR(36) NOT NULL DEFAULT ''," : '';
        $unique = $variant === 'fresh'
            ? 'UNIQUE KEY idx_client_debug_chunks_upload (client_id, collection_uuid, upload_id, sequence)'
            : 'UNIQUE (client_id, collection_uuid, sequence)';
        $pdo->exec("CREATE TABLE client_debug_chunks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            client_id BIGINT UNSIGNED NOT NULL,
            collection_uuid VARCHAR(64) NOT NULL,
            {$uploadColumn}
            sequence BIGINT UNSIGNED NOT NULL,
            chunk_sha256 CHAR(64) NOT NULL,
            byte_length INT UNSIGNED NOT NULL,
            storage_path VARCHAR(768) NOT NULL,
            created_at DATETIME NOT NULL,
            {$unique}
        )");
        $pdo->exec("INSERT INTO client_debug_chunks (client_id, collection_uuid, sequence, chunk_sha256, byte_length, storage_path, created_at)
            VALUES (1, '11111111-1111-4111-8111-111111111111', 0, REPEAT('a', 64), 5, '/legacy.log', UTC_TIMESTAMP())");

        $runner = new MigrationRunner(new Config($temp . '/missing.env'), $db, $temp);
        if ($runner->applyPending() !== [$migration]) {
            throw new RuntimeException($variant . ': migration was not applied');
        }
        $legacy = $db->fetchOne('SELECT upload_id, byte_length, storage_path FROM client_debug_chunks WHERE id = 1');
        if ($legacy !== ['upload_id' => '', 'byte_length' => 5, 'storage_path' => '/legacy.log']) {
            throw new RuntimeException($variant . ': historical chunk changed');
        }
        $pdo->exec("INSERT INTO client_debug_chunks (client_id, collection_uuid, upload_id, sequence, chunk_sha256, byte_length, storage_path, created_at)
            VALUES (1, '11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222', 0, REPEAT('b', 64), 6, '/new.log', UTC_TIMESTAMP())");
        $indexes = $db->fetchAll('SHOW INDEX FROM client_debug_chunks');
        $newColumns = [];
        foreach ($indexes as $index) {
            if ($index['Key_name'] === 'client_id') {
                throw new RuntimeException($variant . ': old sequence constraint remains');
            }
            if ($index['Key_name'] === 'idx_client_debug_chunks_upload') {
                $newColumns[] = $index['Column_name'];
            }
        }
        if ($newColumns !== ['client_id', 'collection_uuid', 'upload_id', 'sequence']) {
            throw new RuntimeException($variant . ': upload constraint is missing');
        }
        $rejected = false;
        try {
            $pdo->exec("INSERT INTO client_debug_chunks (client_id, collection_uuid, upload_id, sequence, chunk_sha256, byte_length, storage_path, created_at)
                VALUES (1, '11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222', 0, REPEAT('c', 64), 7, '/conflict.log', UTC_TIMESTAMP())");
        } catch (PDOException $error) {
            $rejected = ($error->errorInfo[1] ?? 0) === 1062;
        }
        if (!$rejected || $runner->applyPending() !== []) {
            throw new RuntimeException($variant . ': uniqueness or normal rerun failed');
        }
        // A crash after DDL but before recording history must also be restartable.
        $pdo->exec('DELETE FROM schema_migrations');
        if ($runner->applyPending() !== [$migration]
            || (int) $db->fetchOne('SELECT COUNT(*) AS n FROM client_debug_chunks')['n'] !== 2) {
            throw new RuntimeException($variant . ': interrupted migration cannot resume');
        }
        echo "ok: {$variant} schema, preserved rows, isolated sequences, replay\n";
    }
} finally {
    foreach ($schemas as $schema) {
        $admin->exec("DROP DATABASE `{$schema}`");
    }
    unlink($temp . '/' . $migration);
    rmdir($temp);
}
