<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
use PDO;

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
        $this->ensureHistoryTable();
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
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return [
            'available' => $this->availableMigrations(),
            'applied' => $this->appliedMigrations(),
            'pending' => $this->pendingMigrations(),
        ];
    }

    private function runMigration(string $migration): void
    {
        $path = $this->migrationsPath . '/' . $migration;
        if (!is_file($path)) {
            throw new ApiException('migration_file_missing', 500, ['migration' => $migration]);
        }

        $sql = (string) file_get_contents($path);
        $statements = $this->splitStatements($sql);
        $pdo = $this->db->pdo();
        try {
            foreach ($statements as $statement) {
                $trimmed = trim($this->normalizeStatement($statement));
                if ($trimmed === '') {
                    continue;
                }
                try {
                    $pdo->exec($trimmed);
                } catch (\Throwable $throwable) {
                    if ($this->shouldIgnoreStatementError($trimmed, $throwable)) {
                        continue;
                    }

                    throw $throwable;
                }
            }
        } catch (\Throwable $throwable) {
            throw new ApiException('migration_failed', 500, [
                'migration' => $migration,
                'statement' => $trimmed ?? null,
                'message' => $throwable->getMessage(),
            ]);
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

        if (str_contains($message, 'duplicate column name')) {
            return true;
        }

        if (str_contains($message, 'already exists') && str_contains(strtolower($statement), 'add column')) {
            return true;
        }

        if (str_contains($message, 'table') && str_contains($message, 'already exists') && str_contains(strtolower($statement), 'create table')) {
            return true;
        }

        if (str_contains($message, 'duplicate key name')) {
            return true;
        }

        if (str_contains($message, 'duplicate foreign key constraint name')) {
            return true;
        }

        if (str_contains($message, 'duplicate entry') && str_contains($message, 'for key')) {
            return true;
        }

        return false;
    }
}
