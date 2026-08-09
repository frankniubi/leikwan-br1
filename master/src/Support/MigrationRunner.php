<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;

final class MigrationRunner
{
    private string $migrationsPath;

    public function __construct(
        private Config $config,
        private Database $db,
        ?string $migrationsPath = null,
    ) {
        $this->migrationsPath = $migrationsPath ?? dirname(__DIR__, 2) . '/migrations';
    }

    public function ensureHistoryTable(): void
    {
        $pdo = $this->db->pdo();
        $driver = $this->config->get('DB_DRIVER', 'mysql');
        if ($driver === 'sqlite') {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS schema_migrations (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    migration_name TEXT NOT NULL UNIQUE,
                    applied_at TEXT NOT NULL
                )'
            );
            return;
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                migration_name VARCHAR(255) NOT NULL UNIQUE,
                applied_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /**
     * @return array<int, string>
     */
    public function appliedMigrations(): array
    {
        $this->ensureHistoryTable();
        $rows = $this->db->fetchAll('SELECT migration_name FROM schema_migrations ORDER BY id ASC');
        return array_map(static fn(array $row): string => (string) $row['migration_name'], $rows);
    }

    /**
     * @return array<int, string>
     */
    public function availableMigrations(): array
    {
        $files = glob($this->migrationsPath . '/*.sql') ?: [];
        $names = array_map(static fn(string $path): string => basename($path), $files);
        usort($names, static function (string $left, string $right): int {
            if ($left === 'schema.sql') {
                return -1;
            }
            if ($right === 'schema.sql') {
                return 1;
            }
            return strcmp($left, $right);
        });
        return $names;
    }

    /**
     * @return array<int, string>
     */
    public function pendingMigrations(): array
    {
        $applied = $this->appliedMigrations();
        return array_values(array_diff($this->availableMigrations(), $applied));
    }

    /**
     * @return array<int, string>
     */
    public function applyPending(): array
    {
        $lockName = $this->migrationLockName();
        $locked = false;
        try {
            $this->ensureHistoryTable();
            $locked = $this->acquireMigrationLock($lockName);
            if (!$locked) {
                throw new ApiException('migration_lock_unavailable', 503);
            }

            $appliedNow = [];
            foreach ($this->pendingMigrations() as $migration) {
                $this->runMigration($migration);
                $this->db->insert('schema_migrations', [
                    'migration_name' => $migration,
                    'applied_at' => gmdate('Y-m-d H:i:s'),
                ]);
                $appliedNow[] = $migration;
            }
            return $appliedNow;
        } catch (ApiException $exception) {
            if (in_array($exception->getMessage(), ['migration_failed', 'migration_lock_unavailable'], true)) {
                throw $exception;
            }
            error_log('migration application failed: ' . $exception::class);
            throw new ApiException('migration_failed', 500);
        } catch (\Throwable $throwable) {
            error_log('migration application failed: ' . $throwable::class);
            throw new ApiException('migration_failed', 500);
        } finally {
            if ($locked) {
                $this->releaseMigrationLock($lockName);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        try {
            return [
                'available' => $this->availableMigrations(),
                'applied' => $this->appliedMigrations(),
                'pending' => $this->pendingMigrations(),
            ];
        } catch (\Throwable $throwable) {
            error_log('migration status failed: ' . $throwable::class);
            throw new ApiException('migration_status_failed', 500);
        }
    }

    private function runMigration(string $migration): void
    {
        $path = $this->migrationsPath . '/' . $migration;
        if (!is_file($path)) {
            throw new ApiException('migration_file_missing', 500, ['migration' => $migration]);
        }

        $sql = (string) file_get_contents($path);
        $statements = $this->splitStatements($sql);
        try {
            foreach ($statements as $statement) {
                $trimmed = trim($this->normalizeStatement($statement));
                if ($trimmed === '') {
                    continue;
                }
                try {
                    $this->db->execute($trimmed);
                } catch (\Throwable $throwable) {
                    if ($this->shouldIgnoreStatementError($trimmed, $throwable)) {
                        continue;
                    }

                    throw $throwable;
                }
            }
        } catch (\Throwable $throwable) {
            error_log('migration failed [' . basename($migration) . ']: ' . $throwable::class);
            throw new ApiException('migration_failed', 500);
        }
    }

    private function migrationLockName(): string
    {
        $database = (string) $this->config->get('DB_NAME', 'leikwan_master');
        return 'leikwan_migrations_' . substr(hash('sha256', $database), 0, 32);
    }

    private function acquireMigrationLock(string $lockName): bool
    {
        if ((string) $this->config->get('DB_DRIVER', 'mysql') === 'sqlite') {
            return true;
        }
        try {
            $row = $this->db->fetchOne(
                'SELECT GET_LOCK(:lock_name, :timeout_seconds) AS acquired',
                ['lock_name' => $lockName, 'timeout_seconds' => 30]
            );
            return (int) ($row['acquired'] ?? 0) === 1;
        } catch (\Throwable $throwable) {
            error_log('migration lock acquisition failed: ' . $throwable::class);
            throw new ApiException('migration_lock_unavailable', 503);
        }
    }

    private function releaseMigrationLock(string $lockName): void
    {
        if ((string) $this->config->get('DB_DRIVER', 'mysql') === 'sqlite') {
            return;
        }
        try {
            $this->db->fetchOne('SELECT RELEASE_LOCK(:lock_name) AS released', ['lock_name' => $lockName]);
        } catch (\Throwable $throwable) {
            error_log('migration lock release failed: ' . $throwable::class);
        }
    }

    /**
     * @return array<int, string>
     */
    private function splitStatements(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $sql = preg_replace('/^\s*#.*$/m', '', $sql) ?? $sql;

        $statements = [];
        $buffer = '';
        $inSingle = false;
        $inDouble = false;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if (!$inSingle && !$inDouble && $char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                if ($end === false) {
                    break;
                }
                $i = $end + 1;
                continue;
            }

            if ($char === "'" && !$inDouble) {
                $escaped = $i > 0 && $sql[$i - 1] === '\\';
                if (!$escaped) {
                    $inSingle = !$inSingle;
                }
            } elseif ($char === '"' && !$inSingle) {
                $escaped = $i > 0 && $sql[$i - 1] === '\\';
                if (!$escaped) {
                    $inDouble = !$inDouble;
                }
            }

            if ($char === ';' && !$inSingle && !$inDouble) {
                $statements[] = $buffer;
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        if (trim($buffer) !== '') {
            $statements[] = $buffer;
        }

        return $statements;
    }

    private function normalizeStatement(string $statement): string
    {
        $normalized = preg_replace('/\bADD\s+COLUMN\s+IF\s+NOT\s+EXISTS\b/i', 'ADD COLUMN', $statement);
        $normalized = preg_replace('/\bADD\s+IF\s+NOT\s+EXISTS\b/i', 'ADD', $normalized ?? $statement);
        $driver = $this->config->get('DB_DRIVER', 'mysql');
        if ($driver === 'sqlite') {
            $normalized = preg_replace('/\bENUM\s*\([^)]*\)/i', 'TEXT', $normalized ?? $statement);
            if (preg_match('/^\s*ALTER\s+TABLE\b.*\bADD\s+CONSTRAINT\b.*\bFOREIGN\s+KEY\b/si', $normalized ?? $statement) === 1) {
                return '';
            }
        }
        return $normalized ?? $statement;
    }

    private function shouldIgnoreStatementError(string $statement, \Throwable $throwable): bool
    {
        $message = strtolower($throwable->getMessage());
        $normalizedStatement = strtolower(trim($statement));
        $singleAddClause = preg_match_all('/\badd\b/i', $statement) === 1;
        $singleColumnAdd = $singleAddClause
            && preg_match('/^alter\s+table\b[\s\S]*\badd\s+column\b/i', $statement) === 1;

        if (str_contains($message, 'duplicate column name') && $singleColumnAdd) {
            return true;
        }

        if (str_contains($message, 'already exists') && $singleColumnAdd) {
            return true;
        }

        if (str_contains($message, 'table')
            && str_contains($message, 'already exists')
            && preg_match('/^create\s+table\b/i', $normalizedStatement) === 1) {
            return true;
        }

        $singleIndexAdd = $singleAddClause
            && preg_match('/^alter\s+table\b[\s\S]*\badd\s+(?:unique\s+)?(?:key|index)\b/i', $statement) === 1;
        $createIndex = preg_match('/^create\s+(?:unique\s+)?index\b/i', $normalizedStatement) === 1;
        if (str_contains($message, 'duplicate key name') && ($singleIndexAdd || $createIndex)) {
            return true;
        }

        $singleForeignKeyAdd = $singleAddClause
            && preg_match('/^alter\s+table\b[\s\S]*\badd\s+constraint\b[\s\S]*\bforeign\s+key\b/i', $statement) === 1;
        if (str_contains($message, 'duplicate foreign key constraint name') && $singleForeignKeyAdd) {
            return true;
        }

        return false;
    }
}
