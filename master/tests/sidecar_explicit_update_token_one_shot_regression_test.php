<?php
declare(strict_types=1);

// Behavioral regression (sidecar_explicit_update_token_one_shot): the sidecar
// agent keeps a single last_completed_request_token, so an explicit
// agent_update_request_token left on sidecar_points forever ping-pongs with any
// other live token (explicit -> auto-release -> explicit ...) and reinstalls the
// sidecar every heartbeat (fra1 point 7, 2026-09/10: ~750 installs/day).
// The panel must retire the explicit token once the agent reports it completed,
// and must not touch it otherwise.

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var list<array{0:string,1:array}> */
        public array $executed = [];
        public function __construct(\Leikwan\Master\Config $config) {}
        public function fetchOne(string $sql, array $params = []): ?array { return null; }
        public function fetchAll(string $sql, array $params = []): array { return []; }
        public function insert(string $table, array $data): int { return 1; }
        public function execute(string $sql, array $params = []): int { $this->executed[] = [$sql, $params]; return 1; }
        public function transaction(callable $callback) { return $callback($this); }
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
    use Leikwan\Master\Controllers\SidecarAgentController;
    use Leikwan\Master\Support\ProvisioningService;

    function fail(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    function retire(SidecarAgentController $controller, int $pointId, string $token, mixed $state): string
    {
        $ref = new ReflectionMethod($controller, 'retireCompletedUpdateRequestToken');
        $ref->setAccessible(true);
        return $ref->invoke($controller, $pointId, $token, $state);
    }

    $envPath = tempnam(sys_get_temp_dir(), 'lw-sidecar-token-env-');
    file_put_contents($envPath, "\n");
    $config = new Config($envPath);
    $db = new Database($config);
    $controller = new SidecarAgentController($config, $db, new ProvisioningService($config, $db));

    // 1. Completed explicit token: retired (cleared in DB, not handed out).
    $result = retire($controller, 7, 'uot-0.2.2-rollout-20260813', [
        'request_token' => 'auto-release:installer=2026.10.02.1',
        'status' => 'scheduled',
        'last_completed_request_token' => 'uot-0.2.2-rollout-20260813',
    ]);
    if ($result !== '') {
        fail('completed explicit token must not be handed out again, got ' . var_export($result, true));
    }
    if (count($db->executed) !== 1) {
        fail('completed explicit token must be cleared with exactly one UPDATE');
    }
    [$sql, $params] = $db->executed[0];
    if (!str_contains($sql, 'agent_update_request_token = NULL') || !str_contains($sql, 'AND agent_update_request_token = :request_token')) {
        fail('token clear must be guarded on the token value so a newer admin request survives: ' . $sql);
    }
    if (($params['id'] ?? null) !== 7 || ($params['request_token'] ?? null) !== 'uot-0.2.2-rollout-20260813') {
        fail('token clear must target the heartbeating point and token');
    }

    // 2. Token not yet completed (different last_completed): kept, no write.
    $db->executed = [];
    $result = retire($controller, 7, 'fresh-admin-token', [
        'request_token' => 'fresh-admin-token',
        'status' => 'running',
        'last_completed_request_token' => 'auto-release:installer=2026.10.02.1',
    ]);
    if ($result !== 'fresh-admin-token' || $db->executed !== []) {
        fail('pending explicit token must be handed out unchanged with no DB write');
    }

    // 3. Old agent without agent_update_state / no token: untouched.
    $db->executed = [];
    if (retire($controller, 7, 'fresh-admin-token', null) !== 'fresh-admin-token' || $db->executed !== []) {
        fail('missing agent_update_state must keep the token');
    }
    if (retire($controller, 7, '', ['last_completed_request_token' => '']) !== '' || $db->executed !== []) {
        fail('empty token must stay empty with no DB write');
    }

    // 4. The heartbeat must actually route the stored token through the retire step.
    $source = (string) file_get_contents(__DIR__ . '/../src/Controllers/SidecarAgentController.php');
    if (!str_contains($source, "\$request->input('agent_update_state')") || !str_contains($source, '$updateRequestToken = $this->retireCompletedUpdateRequestToken(')) {
        fail('heartbeat must pass the reported agent_update_state through retireCompletedUpdateRequestToken');
    }

    echo "ok\n";
}
