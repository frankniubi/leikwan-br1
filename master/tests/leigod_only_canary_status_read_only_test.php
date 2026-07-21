<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use Leikwan\Master\Bootstrap;
use Leikwan\Master\Http\Request;
use Leikwan\Master\Support\SessionAuth;

function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }
function assert_true(bool $condition, string $message): void { if (!$condition) fail($message); }
function assert_status($response, int $status, string $label): void { if ($response->status() !== $status) fail($label . ' expected status ' . $status . ' got ' . $response->status() . ' body=' . $response->body()); }

final class LeigodOnlyCanaryStatement extends PDOStatement
{
    public function __construct(private string $sql) {}
    public function execute(?array $params = null): bool { return true; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if (str_contains($this->sql, 'SELECT * FROM users')) {
            return ['id' => 1, 'role' => 'admin', 'name' => 'Admin', 'email' => 'admin@example.test'];
        }
        return false;
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return []; }
    public function rowCount(): int { return 1; }
}
final class LeigodOnlyCanaryPdo extends PDO
{
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false { return new LeigodOnlyCanaryStatement($query); }
    public function lastInsertId(?string $name = null): string|false { return '1'; }
}

final class LeigodOnlyCanaryReadOnlySpy
{
    /** @var list<string> */ public array $reads = [];
    /** @var list<string> */ public array $mutators = [];
    public int $artifactProbeCalls = 0;
    public int $metadataProbeCalls = 0;
    public int $lockCalls = 0;
    public int $publishQueueCalls = 0;

    public function rolloutStatus(array $request): array { $this->reads[] = 'status'; return ['canary_cohorts' => [['cohort' => 0, 'platform_family' => 'linux-bond', 'promotion_ready' => true]]]; }
    public function canaryStatus(array $request): array { $this->reads[] = 'canary.status:' . (string) ($request['platform_family'] ?? ''); return ['ok' => true, 'promotion_ready' => true, 'platform_family' => (string) ($request['platform_family'] ?? ''), 'cohorts' => [['cohort' => 0, 'status' => 'observing']]]; }
    public function plan(array $request): array { $this->reads[] = 'planner.plan:' . (string) ($request['platform_family'] ?? ''); return ['ok' => true, 'dry_run_only' => true, 'plan_hash' => str_repeat('c', 64), 'payload' => ['scope' => (string) ($request['scope'] ?? 'global'), 'dry_run_only' => true, 'blockers' => []], 'blockers' => []]; }
    public function probe(string $path): array { $this->artifactProbeCalls++; return ['status' => 200, 'body' => '{}']; }
    public function metadataForActivation(array $keys): array { $this->metadataProbeCalls++; return []; }
    public function acquire(array $request): array { $this->lockCalls++; $this->mutators[] = 'lock.acquire'; return ['ok' => true]; }
    public function enqueue(array $request): array { $this->publishQueueCalls++; $this->mutators[] = 'publish.enqueue'; return ['ok' => true]; }
    public function writeStatus(array $request, array $status = []): array { $this->mutators[] = 'canary.writeStatus'; return ['ok' => true]; }
}

$env = tempnam(sys_get_temp_dir(), 'lw-canary-task5-');
file_put_contents($env, "DB_DRIVER=mysql\nAUTO_RUN_MIGRATIONS=false\n");
$spy = new LeigodOnlyCanaryReadOnlySpy();
$app = new Bootstrap($env, ['leigod_only_services' => ['status' => $spy, 'planner' => $spy, 'canary' => $spy, 'artifact_route_probe' => $spy, 'artifact_metadata_probe' => $spy, 'lock' => $spy, 'publish_queue' => $spy]]);
$app->db()->setPdo(new LeigodOnlyCanaryPdo());
SessionAuth::boot();
$_SESSION['auth'] = ['user_id' => 1, 'role' => 'admin'];
$_SESSION['csrf_token'] = 'csrf-task5-canary';

$response = $app->handle(new Request('GET', '/admin/leigod-only-rollout', [], ['platform_family' => 'linux-bond'], [], ''));
assert_status($response, 200, 'GET rollout page');
assert_true(in_array('status', $spy->reads, true), 'page should read rollout status');
assert_true($spy->mutators === [], 'GET page must not mutate: ' . json_encode($spy->mutators));

$api = $app->handle(new Request('POST', '/admin/leigod-only-rollout/dry-run', [], ['csrf_token' => 'csrf-task5-canary', 'scope' => 'global', 'platform_family' => 'linux-bond'], [], ''));
assert_status($api, 200, 'canary dry run status');
assert_true($spy->mutators === [], 'read-only canary/dry-run must not call mutators: ' . json_encode($spy->mutators));
assert_true($spy->artifactProbeCalls === 0 && $spy->metadataProbeCalls === 0 && $spy->lockCalls === 0 && $spy->publishQueueCalls === 0, 'read-only canary status must not probe artifacts, locks, or publish queue');

$unsupported = $app->handle(new Request('POST', '/admin/leigod-only-rollout/dry-run', [], ['csrf_token' => 'csrf-task5-canary', 'scope' => 'global', 'platform_family' => 'android-bond'], [], ''));
assert_status($unsupported, 422, 'android dry run rejected');
assert_true(str_contains($unsupported->body(), 'unsupported_platform_family:android-bond'), 'android dry-run rejection should be explicit: ' . $unsupported->body());
assert_true($spy->artifactProbeCalls === 0 && $spy->metadataProbeCalls === 0 && $spy->lockCalls === 0 && $spy->publishQueueCalls === 0 && $spy->mutators === [], 'unsupported canary status must short-circuit before probes/locks/mutators');

echo "ok\n";
