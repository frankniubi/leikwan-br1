<?php
declare(strict_types=1);

// REGRESSION: a Forward-Mode flip on an existing combination must converge the client/slave INGRESS
// axis, not only the slave<->sidecar self-leg. saveForwardCombination() updates only the
// forward_combinations row, but the publish task (publishTaskPayloadForForward) reads backend_mode +
// cf_location_group_id from the DENORMALIZED forwards row, and forwardUsesCfMode() keys CF off
// forwards.cf_location_group_id > 0. So unless the bound forwards rows are re-synced from the
// combination on save, a live cf_mode->leigod_mode flip ships an INCOHERENT task (leigod self-leg +
// still-armed CF ingress leg) -- the exact cf/leigod mismatch that flaps bond legs. The one-time
// promotion migration (20260603_promote_leigod_mode.sql step 2) does this forwards re-sync and its
// own comment says "CRITICAL: without this the cutover is incomplete." This asserts the live save
// path does the same, for all three Forward Modes.
//
// A capturing Database fake records every execute() so we can assert the forwards-ingress sync UPDATE.

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<int, array{sql: string, params: array<string, mixed>}> */
        public array $executes = [];

        /** @param array<int, array<string, mixed>> $rows */
        public function seed(string $table, array $rows): void {}

        /** @param array<string, mixed> $params */
        public function fetchOne(string $sql, array $params = []): ?array { return null; }

        /** @param array<string, mixed> $params */
        public function fetchAll(string $sql, array $params = []): array { return []; }

        /** @param array<string, mixed> $params */
        public function execute(string $sql, array $params = []): int
        {
            $this->executes[] = ['sql' => $sql, 'params' => $params];
            return 1;
        }

        /** @param array<string, mixed> $row */
        public function insert(string $table, array $row): int { return 42; }
    }
}

namespace {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Leikwan\\Master\\';
        if (!str_starts_with($class, $prefix) || $class === 'Leikwan\\Master\\Database\\Database') {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });

    use Leikwan\Master\Config;
    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Support\ProvisioningService;

    function fail(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    /**
     * Find the single forwards-ingress sync UPDATE issued by saveForwardCombination.
     * @param array<int, array{sql: string, params: array<string, mixed>}> $executes
     * @return array<string, mixed>
     */
    function findForwardsIngressSync(array $executes): array
    {
        $matches = array_values(array_filter(
            $executes,
            static fn(array $e): bool =>
                str_contains($e['sql'], 'UPDATE forwards')
                && str_contains($e['sql'], 'backend_mode')
                && str_contains($e['sql'], 'cf_location_group_id')
                && str_contains($e['sql'], 'forward_combination_id')
        ));
        if (count($matches) !== 1) {
            fail('expected exactly one forwards-ingress sync UPDATE, got ' . count($matches));
        }
        return $matches[0]['params'];
    }

    $envPath = tempnam(sys_get_temp_dir(), 'lw-leigod-ingress-env-');
    file_put_contents($envPath, "DEFAULT_TLS_CERT_REF=default-edge-cert\n");
    $config = new Config($envPath);

    $base = [
        'id' => 5, // EDIT path: an existing combination with bound forwards
        'name' => 'leigod-mode-combo',
        'tenant_label' => 'Leigod',
        'node_group_key' => 'sha-tencent',
        'sidecar_point_id' => 4,
        'client_mode' => 'bond',
        'runtime_preference' => 'mptcp_preferred_bond_fallback',
        'is_active' => 1,
    ];

    // Case 1: flip an existing combo to leigod_mode -> forwards.backend_mode='leigod_mode',
    // cf_location_group_id cleared to null (drop the stale CF ingress).
    $db = new Database();
    $service = new ProvisioningService($config, $db);
    $service->saveForwardCombination($base + ['backend_mode' => 'leigod_mode', 'leigod_line_id' => 453]);
    $p = findForwardsIngressSync($db->executes);
    if (($p['forward_combination_id'] ?? null) !== 5) {
        fail('leigod sync must target the combination id 5, got ' . var_export($p['forward_combination_id'] ?? 'MISSING', true));
    }
    if (($p['backend_mode'] ?? null) !== 'leigod_mode') {
        fail('leigod sync must set forwards.backend_mode=leigod_mode, got ' . var_export($p['backend_mode'] ?? 'MISSING', true));
    }
    if (!array_key_exists('cf_location_group_id', $p) || $p['cf_location_group_id'] !== null) {
        fail('leigod sync must clear forwards.cf_location_group_id to null, got ' . var_export($p['cf_location_group_id'] ?? 'MISSING', true));
    }

    // Case 2: cf_mode keeps backend_mode='cf_mode' and the cf location group on the forwards row.
    $db = new Database();
    $service = new ProvisioningService($config, $db);
    $service->saveForwardCombination($base + ['backend_mode' => 'cf_mode', 'cf_location_group_id' => 7]);
    $p = findForwardsIngressSync($db->executes);
    if (($p['backend_mode'] ?? null) !== 'cf_mode') {
        fail('cf sync must set forwards.backend_mode=cf_mode, got ' . var_export($p['backend_mode'] ?? 'MISSING', true));
    }
    if ((int) ($p['cf_location_group_id'] ?? 0) !== 7) {
        fail('cf sync must keep forwards.cf_location_group_id=7, got ' . var_export($p['cf_location_group_id'] ?? 'MISSING', true));
    }

    // Case 3: current_mode -> backend_mode='current_mode', cf cleared.
    $db = new Database();
    $service = new ProvisioningService($config, $db);
    $service->saveForwardCombination($base + ['backend_mode' => 'current_mode']);
    $p = findForwardsIngressSync($db->executes);
    if (($p['backend_mode'] ?? null) !== 'current_mode') {
        fail('current_mode sync must set forwards.backend_mode=current_mode, got ' . var_export($p['backend_mode'] ?? 'MISSING', true));
    }
    if (!array_key_exists('cf_location_group_id', $p) || $p['cf_location_group_id'] !== null) {
        fail('current_mode sync must clear forwards.cf_location_group_id, got ' . var_export($p['cf_location_group_id'] ?? 'MISSING', true));
    }

    echo "ok\n";
}
