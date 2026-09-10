<?php
declare(strict_types=1);

namespace Leikwan\Master\Database;

use Leikwan\Master\Config;
use PDO;
use PDOException;
use Throwable;

final class Database
{
    /**
     * Maximum number of attempts for a write that fails with an InnoDB
     * deadlock / lock-wait (SQLSTATE 40001, MySQL error 1213). The first
     * attempt plus two retries.
     */
    private const DEADLOCK_MAX_ATTEMPTS = 3;

    private Config $config;
    private ?PDO $pdo = null;
    private int $transactionDepth = 0;
    private ?Throwable $rollbackOnlyCause = null;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Inject a PDO instance, primarily for tests that want to exercise the
     * query/retry wrappers against a fake driver. Production code never calls
     * this; the lazy connection in pdo() is used instead.
     */
    public function setPdo(PDO $pdo): void
    {
        $this->pdo = $pdo;
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $driver = (string) $this->config->get('DB_DRIVER', 'mysql');
        if ($driver === 'sqlite') {
            $dsn = 'sqlite:' . $this->config->get('DB_NAME', __DIR__ . '/../../database.sqlite');
            $user = null;
            $password = null;
        } else {
            $host = $this->config->get('DB_HOST', '127.0.0.1');
            $port = $this->config->get('DB_PORT', '3306');
            $name = $this->config->get('DB_NAME', 'leikwan_master');
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);
            $user = $this->config->get('DB_USER', 'root');
            $password = $this->config->get('DB_PASSWORD', '');
        }

        try {
            $this->pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $exception) {
            throw new \RuntimeException('Database connection failed: ' . $exception->getMessage(), 0, $exception);
        }

        return $this->pdo;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        try {
            $stmt = $this->pdo()->prepare($sql);
            $stmt->execute($this->sanitizeParams($sql, $params));
            $row = $stmt->fetch();
            return $row === false ? null : $row;
        } catch (Throwable $throwable) {
            $this->markRollbackOnlyOnDeadlock($throwable);
            throw $this->queryException($throwable, $sql, $params);
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        try {
            $stmt = $this->pdo()->prepare($sql);
            $stmt->execute($this->sanitizeParams($sql, $params));
            return $stmt->fetchAll() ?: [];
        } catch (Throwable $throwable) {
            $this->markRollbackOnlyOnDeadlock($throwable);
            throw $this->queryException($throwable, $sql, $params);
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                $stmt = $this->pdo()->prepare($sql);
                $stmt->execute($this->sanitizeParams($sql, $params));
                return $stmt->rowCount();
            } catch (Throwable $throwable) {
                // A standalone statement may be retried. Inside a transaction,
                // InnoDB can roll back the whole transaction, so the outer
                // transaction wrapper must rerun the complete callback instead.
                $inTransaction = $this->transactionDepth > 0 || $this->pdo()->inTransaction();
                if ($inTransaction && $this->isDeadlock($throwable)) {
                    $this->rollbackOnlyCause = $throwable;
                }
                if (!$inTransaction && $this->isDeadlock($throwable) && $attempt < self::DEADLOCK_MAX_ATTEMPTS) {
                    usleep($attempt * 20000);
                    continue;
                }
                throw $this->queryException($throwable, $sql, $params);
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $placeholders = array_map(static fn(string $key): string => ':' . $key, $columns);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );
        $this->execute($sql, $data);
        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * @param callable $callback
     * @return mixed
     */
    public function transaction(callable $callback)
    {
        $pdo = $this->pdo();
        if ($pdo->inTransaction()) {
            return $this->nestedTransaction($pdo, $callback);
        }

        $attempt = 0;
        while (true) {
            $attempt++;
            $pdo->beginTransaction();
            $this->transactionDepth = 1;
            $this->rollbackOnlyCause = null;
            try {
                $result = $callback($this);
                if ($this->rollbackOnlyCause instanceof Throwable) {
                    throw $this->rollbackOnlyCause;
                }
                if (!$pdo->inTransaction()) {
                    throw new \RuntimeException('database_transaction_was_rolled_back');
                }
                $pdo->commit();
                return $result;
            } catch (Throwable $throwable) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($this->isDeadlock($throwable) && $attempt < self::DEADLOCK_MAX_ATTEMPTS) {
                    usleep($attempt * 20000);
                    continue;
                }
                throw $throwable;
            } finally {
                $this->transactionDepth = 0;
                $this->rollbackOnlyCause = null;
            }
        }
    }

    private function nestedTransaction(PDO $pdo, callable $callback): mixed
    {
        $this->transactionDepth++;
        $savepoint = 'leikwan_sp_' . $this->transactionDepth;
        $savepointCreated = false;
        try {
            $pdo->exec('SAVEPOINT ' . $savepoint);
            $savepointCreated = true;
            $result = $callback($this);
            if ($this->rollbackOnlyCause instanceof Throwable) {
                throw $this->rollbackOnlyCause;
            }
            $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            return $result;
        } catch (Throwable $throwable) {
            if ($this->isDeadlock($throwable)) {
                $this->rollbackOnlyCause = $throwable;
            } elseif ($savepointCreated && $pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            throw $throwable;
        } finally {
            $this->transactionDepth = max(0, $this->transactionDepth - 1);
        }
    }

    private function markRollbackOnlyOnDeadlock(Throwable $throwable): void
    {
        if ($this->isDeadlock($throwable)
            && ($this->transactionDepth > 0 || $this->pdo()->inTransaction())) {
            $this->rollbackOnlyCause = $throwable;
        }
    }

    /**
     * Detect an InnoDB deadlock / lock-wait timeout. PDO reports the deadlock
     * with SQLSTATE 40001 and driver error code 1213 (a lock-wait timeout is
     * 40001/1205); both are safe to retry because the failed transaction was
     * rolled back by the server.
     */
    private function isDeadlock(Throwable $throwable): bool
    {
        if ($throwable instanceof PDOException) {
            $sqlState = (string) ($throwable->errorInfo[0] ?? $throwable->getCode());
            if ($sqlState === '40001') {
                return true;
            }
            $driverCode = (int) ($throwable->errorInfo[1] ?? 0);
            if ($driverCode === 1213 || $driverCode === 1205) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function queryException(Throwable $throwable, string $sql, array $params): Throwable
    {
        $message = $throwable->getMessage();
        if (str_contains($message, 'HY093') || str_contains($message, 'Invalid parameter number')) {
            return new \RuntimeException(
                'SQL parameter binding failed: ' . $message
                . ' | SQL: ' . preg_replace('/\s+/', ' ', trim($sql))
                . ' | Params: ' . json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                0,
                $throwable
            );
        }

        return $throwable;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function sanitizeParams(string $sql, array $params): array
    {
        if ($params === []) {
            return $params;
        }

        $hasNamedKeys = false;
        foreach (array_keys($params) as $key) {
            if (is_string($key)) {
                $hasNamedKeys = true;
                break;
            }
        }

        if (!$hasNamedKeys) {
            return $params;
        }

        preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $sql, $matches);
        $placeholders = array_values(array_unique($matches[1] ?? []));
        if ($placeholders === []) {
            return [];
        }

        $allowed = array_flip($placeholders);
        $filtered = [];
        foreach ($params as $key => $value) {
            if (is_string($key) && isset($allowed[$key])) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }
}
