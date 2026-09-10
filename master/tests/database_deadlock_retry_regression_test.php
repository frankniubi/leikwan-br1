<?php
declare(strict_types=1);

// Regression: concurrent NodeController::heartbeat calls run reconcile/republish
// inline, and queueTask -> collapsePendingProvisionTasksForNode issues an UPDATE
// on provision_tasks. Under load these collide on InnoDB row locks and raise a
// deadlock (SQLSTATE 40001 / error 1213). Database::execute() must retry the write
// a couple of times before giving up, and must NOT retry non-deadlock errors.

$baseDir = __DIR__ . '/..';

spl_autoload_register(static function (string $class) use ($baseDir): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = $baseDir . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

/**
 * Fake statement: succeeds, or throws a configured PDOException on execute().
 */
final class FakeStatement extends PDOStatement
{
    /** @var array<int, ?PDOException> queued exceptions per execute() call (null = success) */
    private array $script;
    private mixed $onFailure = null;
    public int $executeCalls = 0;

    /** @param array<int, ?PDOException> $script */
    public function __construct(array $script)
    {
        $this->script = $script;
    }

    public function execute(?array $params = null): bool
    {
        $index = $this->executeCalls;
        $this->executeCalls++;
        $exception = $this->script[$index] ?? null;
        if ($exception instanceof PDOException) {
            if (is_callable($this->onFailure)) {
                ($this->onFailure)();
            }
            throw $exception;
        }
        return true;
    }

    public function setOnFailure(callable $callback): void { $this->onFailure = $callback; }

    public function rowCount(): int
    {
        return 1;
    }
}

/**
 * Fake PDO that hands out a shared FakeStatement and counts prepare() calls.
 */
final class FakePdo extends PDO
{
    public int $prepareCalls = 0;
    public int $beginCalls = 0;
    public int $commitCalls = 0;
    public int $rollbackCalls = 0;
    private bool $active = false;

    public function __construct(private FakeStatement $statement)
    {
        // Intentionally do NOT call parent::__construct — no real connection.
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->prepareCalls++;
        return $this->statement;
    }

    public function beginTransaction(): bool { $this->active = true; $this->beginCalls++; return true; }
    public function inTransaction(): bool { return $this->active; }
    public function commit(): bool { $this->active = false; $this->commitCalls++; return true; }
    public function rollBack(): bool { $this->active = false; $this->rollbackCalls++; return true; }
    public function simulateServerRollback(): void { $this->active = false; }
}

function makeDeadlock(): PDOException
{
    $exception = new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock');
    // PDOException uses a string code for SQLSTATE; errorInfo carries the driver code.
    $exception->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];
    return $exception;
}

function makeOtherError(): PDOException
{
    $exception = new PDOException('SQLSTATE[42S02]: Base table or view not found: 1146 Table does not exist');
    $exception->errorInfo = ['42S02', 1146, "Table 'x' doesn't exist"];
    return $exception;
}

$envPath = tempnam(sys_get_temp_dir(), 'lw-db-retry-env-');
if ($envPath === false) {
    fail('failed to create temp env');
}
file_put_contents($envPath, "DB_DRIVER=mysql\n");
$config = new Config($envPath);

// --- Case 1: deadlock twice, then success -> execute() retries and ultimately succeeds.
$stmt = new FakeStatement([makeDeadlock(), makeDeadlock(), null]);
$pdo = new FakePdo($stmt);
$db = new Database($config);
$db->setPdo($pdo);

$start = microtime(true);
$rows = $db->execute('UPDATE provision_tasks SET status = :s WHERE node_id = :n', ['s' => 'completed', 'n' => 7]);
$elapsed = microtime(true) - $start;

if ($rows !== 1) {
    fail("expected rowCount 1 after successful retry, got {$rows}");
}
if ($stmt->executeCalls !== 3) {
    fail("expected 3 execute() attempts (2 deadlocks + 1 success), got {$stmt->executeCalls}");
}
if ($elapsed <= 0.0) {
    fail('expected a small backoff delay between retries');
}

// --- Case 2: deadlock on every attempt -> rethrows after exhausting the 3 attempts.
$stmt2 = new FakeStatement([makeDeadlock(), makeDeadlock(), makeDeadlock(), makeDeadlock()]);
$db2 = new Database($config);
$db2->setPdo(new FakePdo($stmt2));
$threw = false;
try {
    $db2->execute('UPDATE provision_tasks SET status = :s WHERE node_id = :n', ['s' => 'completed', 'n' => 8]);
} catch (Throwable $t) {
    $threw = true;
}
if (!$threw) {
    fail('expected a persistent deadlock to be rethrown after exhausting retries');
}
if ($stmt2->executeCalls !== 3) {
    fail("expected exactly 3 attempts before giving up, got {$stmt2->executeCalls}");
}

// --- Case 3: a non-deadlock error is NOT retried — it is rethrown immediately.
$stmt3 = new FakeStatement([makeOtherError(), null]);
$db3 = new Database($config);
$db3->setPdo(new FakePdo($stmt3));
$threw3 = false;
try {
    $db3->execute('UPDATE provision_tasks SET status = :s WHERE node_id = :n', ['s' => 'completed', 'n' => 9]);
} catch (Throwable $t) {
    $threw3 = true;
}
if (!$threw3) {
    fail('expected a non-deadlock error to propagate');
}
if ($stmt3->executeCalls !== 1) {
    fail("non-deadlock error must NOT be retried; expected 1 attempt, got {$stmt3->executeCalls}");
}

// --- Case 4: a deadlock inside a transaction retries the whole callback.
$stmt4 = new FakeStatement([makeDeadlock(), null]);
$pdo4 = new FakePdo($stmt4);
$stmt4->setOnFailure(static fn() => $pdo4->simulateServerRollback());
$db4 = new Database($config);
$db4->setPdo($pdo4);
$callbackCalls = 0;
$result = $db4->transaction(static function (Database $db) use (&$callbackCalls): string {
    $callbackCalls++;
    $db->execute('UPDATE provision_tasks SET status = :s WHERE node_id = :n', ['s' => 'completed', 'n' => 10]);
    return 'transaction-ok';
});
if ($result !== 'transaction-ok' || $callbackCalls !== 2) {
    fail('deadlock inside a transaction must rerun the complete callback');
}
if ($pdo4->beginCalls !== 2 || $pdo4->rollbackCalls !== 0 || $pdo4->commitCalls !== 1) {
    fail('server-rolled-back deadlock must begin and commit a fresh transaction without retrying one statement');
}

echo "ok\n";
