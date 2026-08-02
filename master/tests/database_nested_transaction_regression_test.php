<?php
declare(strict_types=1);

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Database/Database.php';

final class NestedTransactionPdo extends PDO
{
    public int $beginCalls = 0;
    public int $commitCalls = 0;
    public int $rollbackCalls = 0;
    private bool $active = false;

    public function __construct()
    {
    }

    public function beginTransaction(): bool
    {
        if ($this->active) {
            throw new RuntimeException('nested beginTransaction');
        }
        $this->active = true;
        $this->beginCalls++;
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->active;
    }

    public function commit(): bool
    {
        if (!$this->active) {
            throw new RuntimeException('commit without transaction');
        }
        $this->active = false;
        $this->commitCalls++;
        return true;
    }

    public function rollBack(): bool
    {
        if (!$this->active) {
            throw new RuntimeException('rollback without transaction');
        }
        $this->active = false;
        $this->rollbackCalls++;
        return true;
    }
}

function fail_nested_transaction(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$envPath = tempnam(sys_get_temp_dir(), 'lw-nested-transaction-env-');
if ($envPath === false) {
    fail_nested_transaction('failed to create temp env');
}
file_put_contents($envPath, "DB_DRIVER=mysql\n");

$pdo = new NestedTransactionPdo();
$db = new Database(new Config($envPath));
$db->setPdo($pdo);
$result = $db->transaction(static function (Database $db): string {
    return $db->transaction(static fn(Database $db): string => 'nested-ok');
});

if ($result !== 'nested-ok') {
    fail_nested_transaction('nested transaction result was not returned');
}
if ($pdo->beginCalls !== 1 || $pdo->commitCalls !== 1 || $pdo->rollbackCalls !== 0) {
    fail_nested_transaction('nested transaction must use one outer begin/commit');
}

echo "ok\n";
