<?php
declare(strict_types=1);

/**
 * Pausing a forward used to roll itself back.
 *
 * setForwardEnabled's pause branch disabled the row and then called
 * buildClientConfig, whose query requires f.enabled = 1 AND s.status = 'active'.
 * It therefore threw forward_not_found 404, the surrounding transaction rolled
 * back, and the pause silently never persisted even though the tenant UI
 * reported success.
 *
 * This asserts the observable outcome: the disable is still executed, no
 * exception escapes, and the returned payload reports the forward as paused
 * rather than handing back credentials for a forward that was just shut down.
 */

namespace Leikwan\Master\Database {

    final class Database
    {
        /** @var array<int, array<string, mixed>> */
        public array $executed = [];

        /** @var array<int, array<string, mixed>> */
        public array $audited = [];

        public function fetchOne(string $sql, array $params = []): ?array
        {
            if (str_contains($sql, 'FROM forwards')) {
                return [
                    'id' => 170,
                    'service_id' => 30,
                    'enabled' => 1,
                    'api_revision' => 7,
                    'user_deleted_at' => null,
                    'native_outbound_enabled' => 0,
                    'native_direction_key' => null,
                    'forward_combination_id' => null,
                ];
            }
            if (str_contains($sql, 'FROM services')) {
                return ['id' => 30, 'user_id' => 5, 'status' => 'active', 'plan_id' => 0];
            }
            return null;
        }

        public function fetchAll(string $sql, array $params = []): array
        {
            // No existing endpoints: skip the destroy-task fan-out entirely.
            return [];
        }

        public function insert(string $table, array $data): int
        {
            if ($table === 'audit_logs') {
                $this->audited[] = $data;
            }
            return 1;
        }

        public function execute(string $sql, array $params = []): int
        {
            $this->executed[] = ['sql' => $sql, 'params' => $params];
            return 1;
        }

        public function transaction(callable $callback)
        {
            return $callback($this);
        }
    }
}

namespace {

    spl_autoload_register(static function (string $class): void {
        $prefix = 'Leikwan\\Master\\';
        if (!str_starts_with($class, $prefix) || $class === 'Leikwan\\Master\\Database\\Database') {
            return;
        }
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });

    function check(bool $ok, string $message): void
    {
        if (!$ok) {
            fwrite(STDERR, $message . PHP_EOL);
            exit(1);
        }
    }

    use Leikwan\Master\Config;
    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Support\ProvisioningService;

    $envPath = tempnam(sys_get_temp_dir(), 'lk-pause-');
    file_put_contents($envPath, "AGENT_API_URL=https://panel.example.com\n");
    $db = new Database();
    $service = new ProvisioningService(new Config($envPath), $db);

    $result = $service->setForwardEnabled(170, false, 'tenant:5', 7, null);

    check(is_array($result), 'pause did not return an array');
    check(($result['forward_id'] ?? null) === 170, 'pause did not report the forward id');
    check(($result['enabled'] ?? null) === false, 'pause still reports the forward as enabled');
    check(($result['state'] ?? '') === 'paused', 'pause did not report paused state');
    check(!array_key_exists('client_token', $result), 'pause leaked client credentials');

    $disabled = null;
    foreach ($db->executed as $statement) {
        if (str_contains($statement['sql'], 'SET enabled = 0')) {
            $disabled = $statement;
            break;
        }
    }
    check($disabled !== null, 'pause never executed the disable update');
    check((int) ($disabled['params']['api_revision'] ?? 0) === 8, 'pause did not advance the revision');

    $pausedAudit = false;
    foreach ($db->audited as $entry) {
        if (($entry['action'] ?? '') === 'forward.paused') {
            $pausedAudit = true;
        }
    }
    check($pausedAudit, 'pause did not write an audit record');

    @unlink($envPath);
    echo "ok pause persists without forward_not_found\n";
}
