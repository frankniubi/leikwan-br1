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
use Leikwan\Master\Support\LeigodActivationGateContext;
use Leikwan\Master\Support\LeigodNormalizationActivationContext;
use Leikwan\Master\Support\LeigodOnlyRuntimeContract;
use Leikwan\Master\Support\LeigodRetirementActivationContext;
use Leikwan\Master\Support\SessionAuth;

function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }
function assert_true(bool $condition, string $message): void { if (!$condition) fail($message); }
function assert_status($response, int $status, string $label): void { if ($response->status() !== $status) fail($label . ' expected status ' . $status . ' got ' . $response->status() . ' body=' . $response->body()); }
function response_json($response, string $label): array { $decoded = json_decode($response->body(), true); if (!is_array($decoded)) fail($label . ' returned non-json body=' . $response->body()); return $decoded; }
function standalone_runtime_row(array $patch = []): array { return array_replace(['backend_mode' => 'leigod_mode', 'client_mode' => 'bond', 'runtime_preference' => 'single', 'bond_ingress_mode' => 'plain_tcp_mux', 'active_family' => 'standalone_bond'], $patch); }
function normalization_activation_payload(array $patch = []): array
{
    return array_replace_recursive([
        'activation_type' => 'normalization',
        'current_plan_hash' => str_repeat('a', 64),
        'expected_artifact_pins' => ['client_agent.py' => str_repeat('1', 64)],
        'affected_forward_ids' => [65],
    ], $patch);
}

function retirement_activation_payload(array $patch = []): array
{
    return array_replace_recursive([
        'activation_type' => 'retirement',
        'current_plan_hash' => str_repeat('a', 64),
        'batch_hash' => str_repeat('d', 64),
        'current_batch_hash' => str_repeat('d', 64),
        'expected_artifact_pins' => ['client_agent.py' => str_repeat('1', 64)],
        'affected_forward_ids' => [65],
        'retirement_forward_ids' => [65],
        'cleanup_protocol' => 'forward_retirement_v1',
        'cleanup_lease_token' => str_repeat('2', 64),
        'cleanup_result_token' => str_repeat('3', 64),
        'max_result_bytes' => 4096,
        'replacement_map' => [65 => ['retirement_forward_id' => 65, 'replacement_forward_id' => 165]],
        'role_capabilities' => ['client' => ['ready' => true], 'slave' => ['ready' => true], 'sidecar' => ['ready' => true]],
        'desired_state' => ['runtime_row' => standalone_runtime_row()],
        'applied_state' => ['runtime_row' => standalone_runtime_row()],
        'runtime_retirements' => [65 => ['forward_id' => 65, 'runtime_row' => ['backend_mode' => 'cf_mode']]],
        'active_keep_set' => ['forward_ids' => [165], 'resources' => []],
    ], $patch);
}

final class LeigodOnlyAdminSurfaceStatement extends PDOStatement
{
    public function __construct(private string $sql) {}
    public function execute(?array $params = null): bool { return true; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if (str_contains($this->sql, 'SELECT * FROM users')) {
            return ['id' => 1, 'role' => 'admin', 'status' => 'active', 'name' => 'Admin', 'email' => 'admin@example.test'];
        }
        return false;
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return []; }
    public function rowCount(): int { return 1; }
}
final class LeigodOnlyAdminSurfacePdo extends PDO
{
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false { return new LeigodOnlyAdminSurfaceStatement($query); }
    public function lastInsertId(?string $name = null): string|false { return '1'; }
}

final class LeigodOnlyAdminSurfaceSpy
{
    /** @var list<string> */ public array $reads = [];
    /** @var list<string> */ public array $mutators = [];
    /** @var array<string, mixed>|null */ public ?array $plannerResult = null;
    public bool $artifactGateResultSet = false;
    /** @var mixed */ public mixed $artifactGateResult = null;
    public bool $activationResultSet = false;
    /** @var mixed */ public mixed $activationResult = null;
    /** @var array<string, mixed>|null */ public ?array $rollbackResult = null;
    public bool $runtimeGateResultSet = false;
    /** @var mixed */ public mixed $runtimeGateResult = null;
    /** @var array<string, mixed>|null */ public ?array $lastRuntimeAssertRow = null;
    /** @var array<string, mixed>|null */ public ?array $lastActivationPayload = null;
    /** @var array<string, mixed>|null */ public ?array $lastActivationGateContext = null;
    /** @var array<string, mixed>|null */ public ?array $lastArtifactGateRequest = null;

    public function rolloutStatus(array $request): array { $this->reads[] = 'status'; return ['artifact_consistency' => ['ok' => true], 'canary_cohorts' => [['cohort' => 0, 'status' => 'read_only']]]; }
    public function plan(array $request): array
    {
        $this->reads[] = 'planner.plan:' . (string) ($request['write_mode'] ?? '');
        return $this->plannerResult ?? ['plan_id' => 'plan-1', 'plan_hash' => str_repeat('a', 64), 'payload' => ['scope' => (string) ($request['scope'] ?? 'global'), 'dry_run_only' => true, 'blockers' => []], 'blockers' => []];
    }
    public function canaryStatus(array $request): array { $this->reads[] = 'canary.status'; return ['ok' => true, 'platform_family' => $request['platform_family'] ?? 'linux-bond']; }
    public function activate(array $request): mixed { $this->mutators[] = 'activation.apply'; $this->lastActivationPayload = $request; return $this->activationResultSet ? $this->activationResult : ['ok' => true]; }
    public function activateRetirement(LeigodRetirementActivationContext $context): mixed { $this->mutators[] = 'activation.typed'; $this->lastActivationPayload = ['scope' => $context->scope, 'plan_hash' => $context->plan_hash, 'batch_hash' => $context->batch_hash, 'artifact_snapshot_hash' => $context->artifact_snapshot_hash]; return $this->activationResultSet ? $this->activationResult : ['ok' => true]; }
    public function activateNormalization(LeigodNormalizationActivationContext $context, string $method): mixed { $this->mutators[] = 'activation.normalization.' . $method; $this->lastActivationPayload = ['scope' => $context->scope, 'plan_hash' => $context->plan_hash, 'current_plan_hash' => $context->current_plan_hash, 'artifact_snapshot_hash' => $context->artifact_snapshot_hash, 'affected_forward_ids' => $context->affected_forward_ids]; return $this->activationResultSet ? $this->activationResult : ['ok' => true]; }
    public function activateGate(LeigodActivationGateContext $context): mixed { $this->mutators[] = 'activation.gate'; $this->lastActivationGateContext = $context->toArray(); return $this->activationResultSet ? $this->activationResult : ['ok' => true]; }
    public function apply(int $batchId, string $batchHash, string $ownerToken, string $fencingToken): array { $this->mutators[] = 'rollback.apply'; return $this->rollbackResult ?? ['ok' => true, 'batch_id' => $batchId, 'batch_hash' => $batchHash, 'owner_token' => $ownerToken, 'fencing_token' => $fencingToken]; }
    public function rollback(array $request): array { $this->mutators[] = 'rollback.unexpected'; return ['ok' => true]; }
    public function acquire(array $request): array { $this->mutators[] = 'lock.acquire'; return ['ok' => true]; }
    public function requireHeld(string $scope, string $action, string $ownerToken, string $fencingToken): array { $this->reads[] = 'lock.requireHeld:' . $action; return ['ok' => true, 'scope' => $scope, 'owner_token' => $ownerToken, 'fencing_token' => $fencingToken]; }
    public function validateForActivation(array $request): mixed { $this->reads[] = 'artifact.validate'; $this->lastArtifactGateRequest = $request; return $this->artifactGateResultSet ? $this->artifactGateResult : ['ok' => true, 'blockers' => []]; }
    public function assertActiveContract(array $request, string $context): mixed { $this->reads[] = 'runtime.assert:' . $context; $this->lastRuntimeAssertRow = $request; LeigodOnlyRuntimeContract::assertActiveContract($request, $context); return $this->runtimeGateResultSet ? $this->runtimeGateResult : ['ok' => true]; }
    public function clear(): void { $this->reads = []; $this->mutators = []; $this->plannerResult = null; $this->artifactGateResultSet = false; $this->artifactGateResult = null; $this->activationResultSet = false; $this->activationResult = null; $this->rollbackResult = null; $this->runtimeGateResultSet = false; $this->runtimeGateResult = null; $this->lastRuntimeAssertRow = null; $this->lastActivationPayload = null; $this->lastActivationGateContext = null; $this->lastArtifactGateRequest = null; }
}

final class LeigodOnlyAdminTypedActivationSpy
{
    public function __construct(private LeigodOnlyAdminSurfaceSpy $spy) {}
    public function activate(LeigodRetirementActivationContext $context): mixed { return $this->spy->activateRetirement($context); }
}

final class LeigodOnlyAdminTypedNormalizationActivationSpy
{
    public function __construct(private LeigodOnlyAdminSurfaceSpy $spy) {}
    public function activate(LeigodNormalizationActivationContext $context): mixed { return $this->spy->activateNormalization($context, 'typed'); }
}

final class LeigodOnlyAdminApplyOnlyNormalizationActivationSpy
{
    public function __construct(private LeigodOnlyAdminSurfaceSpy $spy) {}
    public function apply(LeigodNormalizationActivationContext $context): mixed { return $this->spy->activateNormalization($context, 'apply'); }
}

final class LeigodOnlyAdminTypedGateActivationSpy
{
    public function __construct(private LeigodOnlyAdminSurfaceSpy $spy) {}
    public function activate(LeigodActivationGateContext $context): mixed { return $this->spy->activateGate($context); }
}

function app_with_spy(LeigodOnlyAdminSurfaceSpy $spy): Bootstrap
{
    $env = tempnam(sys_get_temp_dir(), 'lw-admin-task5-');
    file_put_contents($env, "DB_DRIVER=mysql\nAUTO_RUN_MIGRATIONS=false\n");
    $app = new Bootstrap($env, [
        'leigod_only_services' => [
            'status' => $spy,
            'planner' => $spy,
            'canary' => $spy,
            'activation' => $spy,
            'rollback' => $spy,
            'lock' => $spy,
            'artifact_gate' => $spy,
            'runtime_contract' => $spy,
        ],
    ]);
    $app->db()->setPdo(new LeigodOnlyAdminSurfacePdo());
    SessionAuth::boot();
    $_SESSION['auth'] = ['user_id' => 1, 'role' => 'admin'];
    $_SESSION['csrf_token'] = 'csrf-task5';
    return $app;
}

function app_without_leigod_only_services(): Bootstrap
{
    $env = tempnam(sys_get_temp_dir(), 'lw-admin-task5-');
    file_put_contents($env, "DB_DRIVER=mysql\nAUTO_RUN_MIGRATIONS=false\n");
    $app = new Bootstrap($env, ['leigod_only_services' => []]);
    $app->db()->setPdo(new LeigodOnlyAdminSurfacePdo());
    SessionAuth::boot();
    $_SESSION['auth'] = ['user_id' => 1, 'role' => 'admin'];
    $_SESSION['csrf_token'] = 'csrf-task5';
    return $app;
}

function app_with_default_leigod_only_services(): Bootstrap
{
    $env = tempnam(sys_get_temp_dir(), 'lw-admin-default-task5-');
    file_put_contents($env, "DB_DRIVER=mysql\nAUTO_RUN_MIGRATIONS=false\n");
    $app = new Bootstrap($env, []);
    $app->db()->setPdo(new LeigodOnlyAdminSurfacePdo());
    SessionAuth::boot();
    $_SESSION['auth'] = ['user_id' => 1, 'role' => 'admin'];
    $_SESSION['csrf_token'] = 'csrf-task5';
    return $app;
}

function app_with_activation_service(LeigodOnlyAdminSurfaceSpy $spy, object $activationService): Bootstrap
{
    $env = tempnam(sys_get_temp_dir(), 'lw-admin-task5-');
    file_put_contents($env, "DB_DRIVER=mysql\nAUTO_RUN_MIGRATIONS=false\n");
    $app = new Bootstrap($env, [
        'leigod_only_services' => [
            'status' => $spy,
            'planner' => $spy,
            'canary' => $spy,
            'activation' => $activationService,
            'rollback' => $spy,
            'lock' => $spy,
            'artifact_gate' => $spy,
            'runtime_contract' => $spy,
        ],
    ]);
    $app->db()->setPdo(new LeigodOnlyAdminSurfacePdo());
    SessionAuth::boot();
    $_SESSION['auth'] = ['user_id' => 1, 'role' => 'admin'];
    $_SESSION['csrf_token'] = 'csrf-task5';
    return $app;
}

function app_with_typed_activation_spy(LeigodOnlyAdminSurfaceSpy $spy): Bootstrap
{
    return app_with_activation_service($spy, new LeigodOnlyAdminTypedActivationSpy($spy));
}

function app_with_typed_normalization_activation_spy(LeigodOnlyAdminSurfaceSpy $spy): Bootstrap
{
    return app_with_activation_service($spy, new LeigodOnlyAdminTypedNormalizationActivationSpy($spy));
}

function app_with_apply_only_normalization_activation_spy(LeigodOnlyAdminSurfaceSpy $spy): Bootstrap
{
    return app_with_activation_service($spy, new LeigodOnlyAdminApplyOnlyNormalizationActivationSpy($spy));
}

function app_with_typed_gate_activation_spy(LeigodOnlyAdminSurfaceSpy $spy): Bootstrap
{
    return app_with_activation_service($spy, new LeigodOnlyAdminTypedGateActivationSpy($spy));
}

/** @return array<string, mixed> */
function leigod_only_admin_services_from_app(Bootstrap $app): array
{
    $appRef = new ReflectionObject($app);
    $routerProp = $appRef->getProperty('router');
    $routerProp->setAccessible(true);
    $router = $routerProp->getValue($app);
    $routerRef = new ReflectionObject($router);
    $routesProp = $routerRef->getProperty('routes');
    $routesProp->setAccessible(true);
    $routes = $routesProp->getValue($router);
    foreach (is_array($routes) ? $routes : [] as $route) {
        if (($route['method'] ?? '') !== 'GET' || ($route['pattern'] ?? '') !== '/admin/leigod-only-rollout') {
            continue;
        }
        $handler = $route['handler'] ?? null;
        if (!is_array($handler) || !is_object($handler[0] ?? null)) {
            fail('rollout route handler unavailable');
        }
        $adminRef = new ReflectionObject($handler[0]);
        $servicesProp = $adminRef->getProperty('leigodOnlyServices');
        $servicesProp->setAccessible(true);
        $services = $servicesProp->getValue($handler[0]);
        return is_array($services) ? $services : [];
    }
    fail('rollout route not registered');
}

function dispatch(Bootstrap $app, string $method, string $path, array $body = []): mixed
{
    return $app->handle(new Request($method, $path, [], $body, [], ''));
}

function activation_request(array $patch = []): array
{
    return array_replace([
        'csrf_token' => 'csrf-task5',
        'scope' => 'global',
        'platform_family' => 'linux-bond',
        'plan_id' => 'plan-1',
        'plan_hash' => str_repeat('a', 64),
        'confirm_plan_hash' => str_repeat('a', 64),
        'artifact_snapshot_hash' => str_repeat('c', 64),
        'owner_token' => 'owner',
        'fencing_token' => 'fence',
        'activation_type' => 'standalone',
        'forward_id' => 65,
        'runtime_row' => standalone_runtime_row(),
    ], $patch);
}

function activation_request_without_runtime_row(array $patch = []): array
{
    $request = activation_request($patch);
    unset($request['runtime_row']);
    return $request;
}

$spy = new LeigodOnlyAdminSurfaceSpy();
$app = app_with_spy($spy);

$page = dispatch($app, 'GET', '/admin/leigod-only-rollout');
assert_status($page, 200, 'rollout page');
foreach (['Artifact consistency', 'Runtime contract', 'Leg policy', 'Upload gate', 'Download gate', 'Canary cohorts', 'Normalization activation', 'Legacy retirement activation', 'Rollback preview', 'Audit events'] as $section) {
    assert_true(str_contains($page->body(), $section), 'rollout page missing section ' . $section);
}
assert_true(!str_contains($page->body(), 'current_mode') && !str_contains($page->body(), 'cf_mode'), 'read-only surface must not expose deprecated current_mode/cf_mode controls');

$defaultApp = app_with_default_leigod_only_services();
$defaultServices = leigod_only_admin_services_from_app($defaultApp);
$defaultServiceKeys = array_keys($defaultServices);
sort($defaultServiceKeys, SORT_STRING);
assert_true($defaultServiceKeys === ['artifact_gate', 'lock', 'runtime_contract', 'status'], 'default Bootstrap service map must stay safe and explicit: ' . json_encode($defaultServiceKeys));
assert_true(($defaultServices['status'] ?? null) instanceof \Leikwan\Master\Support\LeigodOnlyAdminRolloutStatusService, 'default status service must be wired');
assert_true(($defaultServices['lock'] ?? null) instanceof \Leikwan\Master\Support\LeigodOnlyRolloutLockService, 'default lock service must be wired');
assert_true(($defaultServices['artifact_gate'] ?? null) instanceof \Leikwan\Master\Support\LeigodOnlyArtifactConsistencyService, 'default artifact gate service must be wired');
assert_true(($defaultServices['runtime_contract'] ?? null) instanceof \Leikwan\Master\Support\LeigodOnlyRuntimeContractService, 'default runtime contract service must be wired');
foreach (['planner', 'activation', 'rollback', 'normalization', 'retirement', 'standalone_gate', 'canary'] as $unsafeDefaultService) {
    assert_true(!array_key_exists($unsafeDefaultService, $defaultServices), 'default Bootstrap map must not wire unsafe service ' . $unsafeDefaultService);
}
$emptyOverrideApp = app_without_leigod_only_services();
assert_true(leigod_only_admin_services_from_app($emptyOverrideApp) === [], 'explicit empty leigod_only_services override must remain empty');
$bootstrapSource = file_get_contents(__DIR__ . '/../src/Bootstrap.php') ?: '';
foreach (['LeigodOnlyActivationPlanner', 'LeigodOnlyActivationService', 'LeigodOnlyRollbackService', 'LeigodOnlyNormalizationActivationService', 'LeigodOnlyLegacyRetirementActivationService', 'LeigodStandaloneBondActivationGateService', 'LeigodOnlyCanaryCampaignService'] as $forbiddenDefaultClass) {
    assert_true(!str_contains($bootstrapSource, 'new ' . $forbiddenDefaultClass . '('), 'Bootstrap default map must not instantiate unsafe service ' . $forbiddenDefaultClass);
    assert_true($forbiddenDefaultClass !== 'LeigodOnlyActivationService' || !str_contains($bootstrapSource, $forbiddenDefaultClass), 'Bootstrap must not import nonexistent ' . $forbiddenDefaultClass);
}
$defaultPage = dispatch($defaultApp, 'GET', '/admin/leigod-only-rollout');
assert_status($defaultPage, 200, 'default Bootstrap rollout page');
foreach (['production_services', 'wired_services', 'mutating_services_enabled'] as $statusField) {
    assert_true(str_contains($defaultPage->body(), $statusField), 'default status payload must expose ' . $statusField . ': ' . $defaultPage->body());
}
assert_true(!str_contains($defaultPage->body(), 'leigod_only_service_unavailable'), 'default rollout page must not report missing safe status service: ' . $defaultPage->body());
$defaultDryRun = dispatch($defaultApp, 'POST', '/admin/leigod-only-rollout/dry-run', ['csrf_token' => 'csrf-task5', 'scope' => 'global', 'platform_family' => 'linux-bond']);
assert_status($defaultDryRun, 422, 'default dry-run keeps unsafe planner absent');
assert_true(str_contains($defaultDryRun->body(), 'leigod_only_service_unavailable:planner.plan'), 'default dry-run must fail closed at unwired planner: ' . $defaultDryRun->body());
$defaultActivate = dispatch($defaultApp, 'POST', '/admin/leigod-only-rollout/activate', activation_request());
assert_status($defaultActivate, 422, 'default activation reaches real lock preflight');
assert_true(str_contains($defaultActivate->body(), 'rollout_lock_not_held'), 'default activation must fail at real lock preflight, not missing lock: ' . $defaultActivate->body());
assert_true(!str_contains($defaultActivate->body(), 'leigod_only_service_unavailable:lock.requireHeld'), 'default activation must wire lock service: ' . $defaultActivate->body());
$defaultRollback = dispatch($defaultApp, 'POST', '/admin/leigod-only-rollout/rollback', [
    'csrf_token' => 'csrf-task5',
    'scope' => 'global',
    'platform_family' => 'linux-bond',
    'batch_id' => '7',
    'confirm_batch_id' => '7',
    'batch_hash' => str_repeat('b', 64),
    'confirm_batch_hash' => str_repeat('b', 64),
    'owner_token' => 'owner',
    'fencing_token' => 'fence',
]);
assert_status($defaultRollback, 422, 'default rollback reaches real lock preflight');
assert_true(str_contains($defaultRollback->body(), 'rollout_lock_not_held'), 'default rollback must fail at real lock preflight, not missing lock: ' . $defaultRollback->body());
assert_true(!str_contains($defaultRollback->body(), 'leigod_only_service_unavailable:lock.requireHeld'), 'default rollback must wire lock service: ' . $defaultRollback->body());

foreach (['dry-run', 'activate', 'rollback'] as $op) {
    $spy->clear();
    $response = dispatch($app, 'POST', '/admin/leigod-only-rollout/' . $op, []);
    assert_status($response, 403, 'invalid csrf ' . $op);
    assert_true($spy->mutators === [], 'mutator log must stay empty when CSRF fails for ' . $op . ': ' . json_encode($spy->mutators));
    assert_true($spy->reads === [], 'read/probe log must stay empty when CSRF fails for ' . $op . ': ' . json_encode($spy->reads));
}

$spy->clear();
$dryRun = dispatch($app, 'POST', '/admin/leigod-only-rollout/dry-run', ['csrf_token' => 'csrf-task5', 'scope' => 'global', 'platform_family' => 'linux-bond']);
assert_status($dryRun, 200, 'valid dry-run');
$dryRunJson = response_json($dryRun, 'valid dry-run');
assert_true(($dryRunJson['ok'] ?? false) === true, 'dry-run must report ok=true: ' . $dryRun->body());
assert_true(($dryRunJson['plan_hash'] ?? '') === str_repeat('a', 64), 'dry-run must include plan_hash: ' . $dryRun->body());
assert_true(is_array($dryRunJson['payload'] ?? null), 'dry-run must include payload: ' . $dryRun->body());
assert_true(in_array('planner.plan:dry_run', $spy->reads, true), 'dry-run must call planner read-only path: ' . json_encode($spy->reads));
assert_true($spy->mutators === [], 'dry-run must not call activation/rollback/cleanup mutators: ' . json_encode($spy->mutators));

$spy->clear();
$spy->plannerResult = ['plan_id' => 'plan-bad', 'plan_hash' => str_repeat('a', 64), 'blockers' => []];
$malformedDryRun = dispatch($app, 'POST', '/admin/leigod-only-rollout/dry-run', ['csrf_token' => 'csrf-task5', 'scope' => 'global', 'platform_family' => 'linux-bond']);
assert_status($malformedDryRun, 422, 'dry-run malformed planner payload');
assert_true(str_contains($malformedDryRun->body(), 'admin_rollout_plan_payload_unavailable'), 'dry-run malformed payload must fail closed: ' . $malformedDryRun->body());
assert_true(!str_contains($malformedDryRun->body(), '"ok":true'), 'dry-run malformed payload must not report ok=true: ' . $malformedDryRun->body());
assert_true($spy->mutators === [], 'dry-run malformed payload must not mutate: ' . json_encode($spy->mutators));

$spy->clear();
$spy->plannerResult = ['plan_id' => 'plan-bad', 'plan_hash' => 'not-a-hash', 'payload' => ['scope' => 'global', 'dry_run_only' => true, 'blockers' => []]];
$missingPlanHash = dispatch($app, 'POST', '/admin/leigod-only-rollout/dry-run', ['csrf_token' => 'csrf-task5', 'scope' => 'global', 'platform_family' => 'linux-bond']);
assert_status($missingPlanHash, 422, 'dry-run malformed plan hash');
assert_true(str_contains($missingPlanHash->body(), 'admin_rollout_plan_hash_unavailable'), 'dry-run malformed plan hash must fail closed: ' . $missingPlanHash->body());
assert_true(!str_contains($missingPlanHash->body(), '"ok":true'), 'dry-run malformed plan hash must not report ok=true: ' . $missingPlanHash->body());
assert_true($spy->mutators === [], 'dry-run malformed plan hash must not mutate: ' . json_encode($spy->mutators));

$spy->clear();
$missingConfirm = dispatch($app, 'POST', '/admin/leigod-only-rollout/activate', ['csrf_token' => 'csrf-task5', 'scope' => 'global']);
assert_status($missingConfirm, 422, 'activate missing confirmation hash');
assert_true($spy->reads === [] && $spy->mutators === [], 'missing activation confirmation must reject before lock/artifact/runtime writes: reads=' . json_encode($spy->reads) . ' mutators=' . json_encode($spy->mutators));

$spy->clear();
$missingFence = dispatch($app, 'POST', '/admin/leigod-only-rollout/activate', ['csrf_token' => 'csrf-task5', 'scope' => 'global', 'plan_id' => 'plan-1', 'plan_hash' => str_repeat('a', 64), 'confirm_plan_hash' => str_repeat('a', 64)]);
assert_status($missingFence, 422, 'activate missing owner/fencing tokens');
assert_true($spy->reads === [] && $spy->mutators === [], 'missing owner/fencing tokens must reject before writes: reads=' . json_encode($spy->reads) . ' mutators=' . json_encode($spy->mutators));

$spy->clear();
$android = dispatch($app, 'POST', '/admin/leigod-only-rollout/activate', ['csrf_token' => 'csrf-task5', 'scope' => 'global', 'platform_family' => 'android-bond', 'plan_id' => 'plan-1', 'plan_hash' => str_repeat('a', 64), 'confirm_plan_hash' => str_repeat('a', 64), 'owner_token' => 'owner', 'fencing_token' => 'fence']);
assert_status($android, 422, 'activate unsupported android platform');
assert_true(str_contains($android->body(), 'unsupported_platform_family:android-bond'), 'android rejection must name unsupported platform: ' . $android->body());
assert_true($spy->reads === [] && $spy->mutators === [], 'android must reject before artifact probes/locks/writes: reads=' . json_encode($spy->reads) . ' mutators=' . json_encode($spy->mutators));

$spy->clear();
$rollbackMissing = dispatch($app, 'POST', '/admin/leigod-only-rollout/rollback', ['csrf_token' => 'csrf-task5', 'scope' => 'global']);
assert_status($rollbackMissing, 422, 'rollback missing confirmation/batch/fence');
assert_true($spy->reads === [] && $spy->mutators === [], 'rollback missing guards must reject before writes: reads=' . json_encode($spy->reads) . ' mutators=' . json_encode($spy->mutators));

foreach (['7abc', '07', '0', '-7', '7.5'] as $badBatchId) {
    $spy->clear();
    $badRollbackBatch = dispatch($app, 'POST', '/admin/leigod-only-rollout/rollback', [
        'csrf_token' => 'csrf-task5',
        'scope' => 'global',
        'platform_family' => 'linux-bond',
        'batch_id' => $badBatchId,
        'confirm_batch_id' => $badBatchId,
        'batch_hash' => str_repeat('b', 64),
        'confirm_batch_hash' => str_repeat('b', 64),
        'owner_token' => 'owner',
        'fencing_token' => 'fence',
    ]);
    assert_status($badRollbackBatch, 422, 'rollback rejects non-canonical batch id ' . $badBatchId);
    assert_true(!str_contains($badRollbackBatch->body(), '"ok":true'), 'bad batch id must not report ok=true: ' . $badRollbackBatch->body());
    assert_true($spy->reads === [] && $spy->mutators === [], 'bad batch id must reject before lock/rollback: reads=' . json_encode($spy->reads) . ' mutators=' . json_encode($spy->mutators));
}

foreach ([
    ['batch_id' => '7', 'confirm_batch_id' => '8', 'batch_hash' => str_repeat('b', 64), 'confirm_batch_hash' => str_repeat('b', 64), 'label' => 'batch id mismatch'],
    ['batch_id' => '7', 'confirm_batch_id' => '7', 'batch_hash' => str_repeat('b', 64), 'confirm_batch_hash' => str_repeat('c', 64), 'label' => 'batch hash mismatch'],
] as $case) {
    $spy->clear();
    $rollbackMismatch = dispatch($app, 'POST', '/admin/leigod-only-rollout/rollback', [
        'csrf_token' => 'csrf-task5',
        'scope' => 'global',
        'platform_family' => 'linux-bond',
        'batch_id' => $case['batch_id'],
        'confirm_batch_id' => $case['confirm_batch_id'],
        'batch_hash' => $case['batch_hash'],
        'confirm_batch_hash' => $case['confirm_batch_hash'],
        'owner_token' => 'owner',
        'fencing_token' => 'fence',
    ]);
    assert_status($rollbackMismatch, 422, 'rollback rejects ' . $case['label']);
    assert_true(!str_contains($rollbackMismatch->body(), '"ok":true'), 'rollback mismatch must not report ok=true: ' . $rollbackMismatch->body());
    assert_true($spy->reads === [] && $spy->mutators === [], 'rollback mismatch must reject before lock/rollback: reads=' . json_encode($spy->reads) . ' mutators=' . json_encode($spy->mutators));
}

$missingServicesApp = app_without_leigod_only_services();
$dryRunWithoutServices = dispatch($missingServicesApp, 'POST', '/admin/leigod-only-rollout/dry-run', ['csrf_token' => 'csrf-task5', 'scope' => 'global', 'platform_family' => 'linux-bond']);
assert_status($dryRunWithoutServices, 422, 'dry-run missing planner service');
assert_true(str_contains($dryRunWithoutServices->body(), 'leigod_only_service_unavailable:planner.plan'), 'dry-run missing service error must name planner.plan: ' . $dryRunWithoutServices->body());
assert_true(!str_contains($dryRunWithoutServices->body(), '"ok":true'), 'dry-run missing services must not report ok=true: ' . $dryRunWithoutServices->body());

$activateWithoutServices = dispatch($missingServicesApp, 'POST', '/admin/leigod-only-rollout/activate', [
    'csrf_token' => 'csrf-task5',
    'scope' => 'global',
    'platform_family' => 'linux-bond',
    'plan_id' => 'plan-1',
    'plan_hash' => str_repeat('a', 64),
    'confirm_plan_hash' => str_repeat('a', 64),
    'owner_token' => 'owner',
    'fencing_token' => 'fence',
    'artifact_snapshot_hash' => str_repeat('c', 64),
    'forward_id' => 65,
    'runtime_row' => standalone_runtime_row(),
]);
assert_status($activateWithoutServices, 422, 'activate missing lock service');
assert_true(str_contains($activateWithoutServices->body(), 'leigod_only_service_unavailable:lock.requireHeld'), 'activate missing service error must name lock.requireHeld: ' . $activateWithoutServices->body());
assert_true(!str_contains($activateWithoutServices->body(), '"ok":true'), 'activate missing services must not report ok=true: ' . $activateWithoutServices->body());

$rollbackWithoutServices = dispatch($missingServicesApp, 'POST', '/admin/leigod-only-rollout/rollback', [
    'csrf_token' => 'csrf-task5',
    'scope' => 'global',
    'platform_family' => 'linux-bond',
    'batch_id' => '7',
    'confirm_batch_id' => '7',
    'batch_hash' => str_repeat('b', 64),
    'confirm_batch_hash' => str_repeat('b', 64),
    'owner_token' => 'owner',
    'fencing_token' => 'fence',
]);
assert_status($rollbackWithoutServices, 422, 'rollback missing lock service');
assert_true(str_contains($rollbackWithoutServices->body(), 'leigod_only_service_unavailable:lock.requireHeld'), 'rollback missing service error must name lock.requireHeld: ' . $rollbackWithoutServices->body());
assert_true(!str_contains($rollbackWithoutServices->body(), '"ok":true'), 'rollback missing services must not report ok=true: ' . $rollbackWithoutServices->body());

$spyApp = app_with_spy($spy);
$spy->clear();
$activateMissingArtifactSnapshot = dispatch($spyApp, 'POST', '/admin/leigod-only-rollout/activate', [
    'csrf_token' => 'csrf-task5',
    'scope' => 'global',
    'platform_family' => 'linux-bond',
    'plan_id' => 'plan-1',
    'plan_hash' => str_repeat('a', 64),
    'confirm_plan_hash' => str_repeat('a', 64),
    'owner_token' => 'owner',
    'fencing_token' => 'fence',
]);
assert_status($activateMissingArtifactSnapshot, 422, 'activate missing artifact snapshot hash');
assert_true(str_contains($activateMissingArtifactSnapshot->body(), 'artifact_snapshot_hash_required'), 'activate must require artifact snapshot hash before services: ' . $activateMissingArtifactSnapshot->body());
assert_true($spy->reads === [] && $spy->mutators === [], 'missing artifact snapshot hash must reject before services: reads=' . json_encode($spy->reads) . ' mutators=' . json_encode($spy->mutators));

$spy->clear();
$spy->artifactGateResultSet = true;
$spy->artifactGateResult = ['ok' => false, 'blockers' => ['route_bytes_mismatch']];
$artifactGateFailure = dispatch($spyApp, 'POST', '/admin/leigod-only-rollout/activate', [
    'csrf_token' => 'csrf-task5',
    'scope' => 'global',
    'platform_family' => 'linux-bond',
    'plan_id' => 'plan-1',
    'plan_hash' => str_repeat('a', 64),
    'confirm_plan_hash' => str_repeat('a', 64),
    'artifact_snapshot_hash' => str_repeat('c', 64),
    'owner_token' => 'owner',
    'fencing_token' => 'fence',
    'forward_id' => 65,
    'runtime_row' => standalone_runtime_row(),
]);
assert_status($artifactGateFailure, 422, 'activate artifact gate failure');
assert_true(str_contains($artifactGateFailure->body(), 'artifact_gate_failed:route_bytes_mismatch'), 'artifact gate failure must surface blockers: ' . $artifactGateFailure->body());
assert_true(!str_contains($artifactGateFailure->body(), '"ok":true'), 'artifact gate failure must not report ok=true: ' . $artifactGateFailure->body());
assert_true(!in_array('activation.apply', $spy->mutators, true), 'artifact gate failure must not call activation mutator: ' . json_encode($spy->mutators));

$spy->clear();
$spy->artifactGateResultSet = true;
$spy->artifactGateResult = [];
$malformedArtifactGate = dispatch($spyApp, 'POST', '/admin/leigod-only-rollout/activate', [
    'csrf_token' => 'csrf-task5',
    'scope' => 'global',
    'platform_family' => 'linux-bond',
    'plan_id' => 'plan-1',
    'plan_hash' => str_repeat('a', 64),
    'confirm_plan_hash' => str_repeat('a', 64),
    'artifact_snapshot_hash' => str_repeat('c', 64),
    'owner_token' => 'owner',
    'fencing_token' => 'fence',
    'forward_id' => 65,
    'runtime_row' => standalone_runtime_row(),
]);
assert_status($malformedArtifactGate, 422, 'activate malformed artifact gate result');
assert_true(str_contains($malformedArtifactGate->body(), 'artifact_gate_unavailable'), 'empty artifact gate result must fail closed: ' . $malformedArtifactGate->body());
assert_true(!in_array('runtime.assert:admin_activate', $spy->reads, true), 'malformed artifact gate must reject before runtime contract: ' . json_encode($spy->reads));
assert_true(!in_array('activation.apply', $spy->mutators, true), 'malformed artifact gate must not call activation mutator: ' . json_encode($spy->mutators));

$spy->clear();
$spy->artifactGateResultSet = true;
$spy->artifactGateResult = ['ok' => 1, 'blockers' => []];
$artifactGateTruthyOk = dispatch($spyApp, 'POST', '/admin/leigod-only-rollout/activate', activation_request());
assert_status($artifactGateTruthyOk, 422, 'activate artifact gate truthy ok rejected');
assert_true(str_contains($artifactGateTruthyOk->body(), 'artifact_gate_failed'), 'artifact gate must require strict boolean ok=true: ' . $artifactGateTruthyOk->body());
assert_true(!in_array('runtime.assert:admin_activate', $spy->reads, true), 'truthy artifact gate ok must reject before runtime contract: ' . json_encode($spy->reads));
assert_true(!in_array('activation.apply', $spy->mutators, true), 'truthy artifact gate ok must not call activation mutator: ' . json_encode($spy->mutators));

$spy->clear();
$spy->artifactGateResultSet = true;
$spy->artifactGateResult = ['ok' => true, 'blockers' => 'route_bytes_mismatch'];
$artifactGateMalformedBlockers = dispatch($spyApp, 'POST', '/admin/leigod-only-rollout/activate', activation_request());
assert_status($artifactGateMalformedBlockers, 422, 'activate artifact gate malformed blockers rejected');
assert_true(str_contains($artifactGateMalformedBlockers->body(), 'artifact_gate_failed:malformed_blockers'), 'malformed artifact gate blockers must fail closed: ' . $artifactGateMalformedBlockers->body());
assert_true(!in_array('runtime.assert:admin_activate', $spy->reads, true), 'malformed artifact gate blockers must reject before runtime contract: ' . json_encode($spy->reads));
assert_true(!in_array('activation.apply', $spy->mutators, true), 'malformed artifact gate blockers must not call activation mutator: ' . json_encode($spy->mutators));

$spy->clear();
$missingRuntimeRow = dispatch($spyApp, 'POST', '/admin/leigod-only-rollout/activate', [
    'csrf_token' => 'csrf-task5',
    'scope' => 'global',
    'platform_family' => 'linux-bond',
    'plan_id' => 'plan-1',
    'plan_hash' => str_repeat('a', 64),
    'confirm_plan_hash' => str_repeat('a', 64),
    'artifact_snapshot_hash' => str_repeat('c', 64),
    'owner_token' => 'owner',
    'fencing_token' => 'fence',
]);
assert_status($missingRuntimeRow, 422, 'activate missing runtime row');
assert_true(str_contains($missingRuntimeRow->body(), 'runtime_contract_row_required'), 'activation must require real runtime row: ' . $missingRuntimeRow->body());
assert_true($spy->reads === [] && $spy->mutators === [], 'missing runtime row must reject before services: reads=' . json_encode($spy->reads) . ' mutators=' . json_encode($spy->mutators));

$spy->clear();
$nestedRuntimeOnly = dispatch($spyApp, 'POST', '/admin/leigod-only-rollout/activate', [
    'csrf_token' => 'csrf-task5',
    'scope' => 'global',
    'platform_family' => 'linux-bond',
    'plan_id' => 'plan-1',
    'plan_hash' => str_repeat('a', 64),
    'confirm_plan_hash' => str_repeat('a', 64),
    'artifact_snapshot_hash' => str_repeat('c', 64),
    'owner_token' => 'owner',
    'fencing_token' => 'fence',
    'forward_id' => 65,
    'desired_state' => ['runtime_row' => standalone_runtime_row()],
    'applied_state' => ['runtime_row' => standalone_runtime_row()],
]);
assert_status($nestedRuntimeOnly, 422, 'activate nested runtime rows are not active evidence');
assert_true(str_contains($nestedRuntimeOnly->body(), 'runtime_contract_row_required'), 'nested desired/applied runtime rows must not satisfy active runtime evidence: ' . $nestedRuntimeOnly->body());
assert_true($spy->reads === [] && $spy->mutators === [], 'nested runtime rows must reject before services: reads=' . json_encode($spy->reads) . ' mutators=' . json_encode($spy->mutators));

foreach (['runtime_row', 'active_runtime_row', 'runtime_contract_row', 'current_runtime_row', 'runtime_contract_json'] as $runtimeField) {
    $spy->clear();
    $envelopedRequest = activation_request();
    unset($envelopedRequest['runtime_row']);
    $envelopedRequest[$runtimeField] = ['runtime_row' => standalone_runtime_row()];
    $envelopedRuntime = dispatch($spyApp, 'POST', '/admin/leigod-only-rollout/activate', $envelopedRequest);
    assert_status($envelopedRuntime, 422, 'activate rejects enveloped ' . $runtimeField);
    assert_true(str_contains($envelopedRuntime->body(), 'runtime_contract_row_required'), 'enveloped ' . $runtimeField . ' must not be unwrapped as active evidence: ' . $envelopedRuntime->body());
    assert_true($spy->reads === [] && $spy->mutators === [], 'enveloped ' . $runtimeField . ' must reject before services: reads=' . json_encode($spy->reads) . ' mutators=' . json_encode($spy->mutators));
}

$spy->clear();
$unknownShape = dispatch($spyApp, 'POST', '/admin/leigod-only-rollout/activate', activation_request(['forward_id' => null]));
assert_status($unknownShape, 422, 'activate unknown payload shape');
assert_true(str_contains($unknownShape->body(), 'activation_lock_key_required'), 'unknown activation shape must fail before service calls: ' . $unknownShape->body());
assert_true($spy->reads === [] && $spy->mutators === [], 'unknown activation shape must reject before services: reads=' . json_encode($spy->reads) . ' mutators=' . json_encode($spy->mutators));

$spy->clear();
$ambiguousShape = dispatch($spyApp, 'POST', '/admin/leigod-only-rollout/activate', activation_request(normalization_activation_payload([
    'activation_type' => null,
    'runtime_row' => standalone_runtime_row(),
])));
assert_status($ambiguousShape, 422, 'activate rejects ambiguous standalone plus normalization shape');
assert_true(str_contains($ambiguousShape->body(), 'activation_shape_ambiguous'), 'ambiguous activation shape must fail closed: ' . $ambiguousShape->body());
assert_true($spy->reads === [] && $spy->mutators === [], 'ambiguous activation shape must reject before services: reads=' . json_encode($spy->reads) . ' mutators=' . json_encode($spy->mutators));

$spy->clear();
$retargetedPayload = dispatch($spyApp, 'POST', '/admin/leigod-only-rollout/activate', activation_request([
    'activation_payload' => [
        'activation_type' => 'standalone',
        'forward_id' => 66,
        'runtime_row' => standalone_runtime_row(),
    ],
]));
assert_status($retargetedPayload, 422, 'activation payload forward id cannot retarget top-level forward');
assert_true(str_contains($retargetedPayload->body(), 'activation_payload_conflict:forward_id'), 'conflicting activation payload forward id must fail closed: ' . $retargetedPayload->body());
assert_true($spy->reads === [] && $spy->mutators === [], 'conflicting activation payload forward id must reject before services: reads=' . json_encode($spy->reads) . ' mutators=' . json_encode($spy->mutators));

$spy->clear();
$maskedRuntime = dispatch($spyApp, 'POST', '/admin/leigod-only-rollout/activate', activation_request([
    'activation_payload' => [
        'activation_type' => 'standalone',
        'forward_id' => 65,
        'runtime_row' => standalone_runtime_row(['backend_mode' => 'cf_mode']),
    ],
]));
assert_status($maskedRuntime, 422, 'activation payload runtime row cannot mask top-level runtime row');
assert_true(str_contains($maskedRuntime->body(), 'runtime_contract_row_conflict'), 'conflicting activation payload runtime row must fail closed: ' . $maskedRuntime->body());
assert_true(!in_array('runtime.assert:admin_activate', $spy->reads, true), 'conflicting active runtime evidence must reject before runtime gate: ' . json_encode($spy->reads));
assert_true(!in_array('activation.apply', $spy->mutators, true), 'conflicting active runtime evidence must not call activation mutator: ' . json_encode($spy->mutators));

$spy->clear();
$legacyRuntime = dispatch($spyApp, 'POST', '/admin/leigod-only-rollout/activate', [
    'csrf_token' => 'csrf-task5',
    'scope' => 'global',
    'platform_family' => 'linux-bond',
    'plan_id' => 'plan-1',
    'plan_hash' => str_repeat('a', 64),
    'confirm_plan_hash' => str_repeat('a', 64),
    'artifact_snapshot_hash' => str_repeat('c', 64),
    'owner_token' => 'owner',
    'fencing_token' => 'fence',
    'forward_id' => 65,
    'runtime_row' => standalone_runtime_row(['backend_mode' => 'cf_mode']),
]);
assert_status($legacyRuntime, 422, 'activate legacy runtime row');
assert_true(str_contains($legacyRuntime->body(), 'leigod_only_runtime_contract_violation'), 'legacy runtime row must fail runtime contract: ' . $legacyRuntime->body());
assert_true(!in_array('activation.apply', $spy->mutators, true), 'legacy runtime row must not call activation mutator: ' . json_encode($spy->mutators));

$spy->clear();
$spy->runtimeGateResultSet = true;
$spy->runtimeGateResult = false;
$runtimeGateFalse = dispatch($spyApp, 'POST', '/admin/leigod-only-rollout/activate', [
    'csrf_token' => 'csrf-task5',
    'scope' => 'global',
    'platform_family' => 'linux-bond',
    'plan_id' => 'plan-1',
    'plan_hash' => str_repeat('a', 64),
    'confirm_plan_hash' => str_repeat('a', 64),
    'artifact_snapshot_hash' => str_repeat('c', 64),
    'owner_token' => 'owner',
    'fencing_token' => 'fence',
    'forward_id' => 65,
    'runtime_row' => standalone_runtime_row(),
]);
assert_status($runtimeGateFalse, 422, 'activate malformed runtime contract gate result');
assert_true(str_contains($runtimeGateFalse->body(), 'runtime_contract_gate_unavailable'), 'false runtime contract gate result must fail closed: ' . $runtimeGateFalse->body());
assert_true(!in_array('activation.apply', $spy->mutators, true), 'malformed runtime contract gate must not call activation mutator: ' . json_encode($spy->mutators));

$spy->clear();
$spy->runtimeGateResultSet = true;
$spy->runtimeGateResult = null;
$runtimeGateVoidSuccess = dispatch($spyApp, 'POST', '/admin/leigod-only-rollout/activate', activation_request());
assert_status($runtimeGateVoidSuccess, 200, 'activate allows void runtime contract success');
assert_true(in_array('activation.apply', $spy->mutators, true), 'void runtime contract success must continue to activation: ' . json_encode($spy->mutators));

foreach ([
    ['value' => ['ok' => 1, 'blockers' => []], 'needle' => 'runtime_contract_gate_failed', 'label' => 'truthy ok'],
    ['value' => ['ok' => 'true', 'blockers' => []], 'needle' => 'runtime_contract_gate_failed', 'label' => 'string ok'],
    ['value' => [], 'needle' => 'runtime_contract_gate_unavailable', 'label' => 'empty array'],
    ['value' => ['blockers' => []], 'needle' => 'runtime_contract_gate_failed', 'label' => 'missing ok'],
    ['value' => 'ok', 'needle' => 'runtime_contract_gate_unavailable', 'label' => 'scalar'],
] as $case) {
    $spy->clear();
    $spy->runtimeGateResultSet = true;
    $spy->runtimeGateResult = $case['value'];
    $runtimeGateMalformed = dispatch($spyApp, 'POST', '/admin/leigod-only-rollout/activate', activation_request());
    assert_status($runtimeGateMalformed, 422, 'activate rejects runtime contract ' . $case['label']);
    assert_true(str_contains($runtimeGateMalformed->body(), $case['needle']), 'runtime contract ' . $case['label'] . ' must fail closed: ' . $runtimeGateMalformed->body());
    assert_true(!str_contains($runtimeGateMalformed->body(), '"ok":true'), 'runtime contract ' . $case['label'] . ' must not report ok=true: ' . $runtimeGateMalformed->body());
    assert_true(!in_array('activation.apply', $spy->mutators, true), 'runtime contract ' . $case['label'] . ' must not call activation mutator: ' . json_encode($spy->mutators));
}

$spy->clear();
$spy->runtimeGateResultSet = true;
$spy->runtimeGateResult = ['ok' => true, 'blockers' => ['nested' => ['not-scalar']]];
$runtimeGateMalformedBlockers = dispatch($spyApp, 'POST', '/admin/leigod-only-rollout/activate', activation_request());
assert_status($runtimeGateMalformedBlockers, 422, 'activate rejects runtime contract malformed blockers');
assert_true(str_contains($runtimeGateMalformedBlockers->body(), 'runtime_contract_gate_failed:malformed_blockers'), 'runtime contract malformed blockers must fail closed: ' . $runtimeGateMalformedBlockers->body());
assert_true(!in_array('activation.apply', $spy->mutators, true), 'runtime contract malformed blockers must not call activation mutator: ' . json_encode($spy->mutators));

$spy->clear();
$spy->activationResultSet = true;
$spy->activationResult = ['ok' => false, 'blockers' => ['activation_plan_hash_changed']];
$activationFailure = dispatch($spyApp, 'POST', '/admin/leigod-only-rollout/activate', activation_request());
assert_status($activationFailure, 422, 'activation failed result');
assert_true(str_contains($activationFailure->body(), 'activation_result_failed:activation_plan_hash_changed'), 'activation failed result must surface blockers: ' . $activationFailure->body());
assert_true(!str_contains($activationFailure->body(), '"ok":true'), 'activation failed result must not report ok=true: ' . $activationFailure->body());
assert_true(in_array('activation.apply', $spy->mutators, true), 'activation failed result still records attempted activation call: ' . json_encode($spy->mutators));

foreach ([
    ['value' => ['ok' => 1, 'blockers' => []], 'needle' => 'activation_result_failed', 'label' => 'truthy ok'],
    ['value' => [], 'needle' => 'activation_result_unavailable', 'label' => 'empty array'],
    ['value' => ['blockers' => []], 'needle' => 'activation_result_failed', 'label' => 'missing ok'],
    ['value' => 'ok', 'needle' => 'activation_result_unavailable', 'label' => 'scalar'],
] as $case) {
    $spy->clear();
    $spy->activationResultSet = true;
    $spy->activationResult = $case['value'];
    $activationMalformed = dispatch($spyApp, 'POST', '/admin/leigod-only-rollout/activate', activation_request());
    assert_status($activationMalformed, 422, 'activate rejects activation result ' . $case['label']);
    assert_true(str_contains($activationMalformed->body(), $case['needle']), 'activation result ' . $case['label'] . ' must fail closed: ' . $activationMalformed->body());
    assert_true(!str_contains($activationMalformed->body(), '"ok":true'), 'activation result ' . $case['label'] . ' must not report ok=true: ' . $activationMalformed->body());
    assert_true(in_array('activation.apply', $spy->mutators, true), 'activation result ' . $case['label'] . ' must record attempted activation call: ' . json_encode($spy->mutators));
}

$spy->clear();
$spy->activationResultSet = true;
$spy->activationResult = ['ok' => true, 'blockers' => ['nested' => ['not-scalar']]];
$activationMalformedBlockers = dispatch($spyApp, 'POST', '/admin/leigod-only-rollout/activate', activation_request());
assert_status($activationMalformedBlockers, 422, 'activate rejects activation result malformed blockers');
assert_true(str_contains($activationMalformedBlockers->body(), 'activation_result_failed:malformed_blockers'), 'activation result malformed blockers must fail closed: ' . $activationMalformedBlockers->body());
assert_true(!str_contains($activationMalformedBlockers->body(), '"ok":true'), 'activation malformed blockers must not report ok=true: ' . $activationMalformedBlockers->body());
assert_true(in_array('activation.apply', $spy->mutators, true), 'activation malformed blockers still records attempted activation call: ' . json_encode($spy->mutators));

$spy->clear();
$validArtifactContextFields = [
    'activation_artifact_keys' => ['client_agent.py', 'slave_agent.py'],
    'route_only_helper_keys' => ['install-client.sh'],
    'manifest_package' => ['client_agent.py' => ['sha256' => str_repeat('1', 64)]],
    'static_mirror' => ['client_agent.py' => ['sha256' => str_repeat('1', 64)]],
    'expected_pins' => ['client_agent.py' => str_repeat('1', 64)],
    'expected_artifact_pins' => ['slave_agent.py' => str_repeat('2', 64)],
    'source_build_provenance' => ['client_agent.py' => ['source_sha256' => str_repeat('3', 64), 'build_sha256' => str_repeat('4', 64)]],
    'expected_capabilities' => ['linux-bond' => ['standalone_bond' => true]],
    'capability_reports' => ['node-47' => ['standalone_bond' => true]],
    'test_mode' => 'unit',
];
$validActivate = dispatch($spyApp, 'POST', '/admin/leigod-only-rollout/activate', array_replace([
    'csrf_token' => 'csrf-task5',
    'scope' => 'global',
    'platform_family' => 'linux-bond',
    'plan_id' => 'plan-1',
    'plan_hash' => str_repeat('a', 64),
    'confirm_plan_hash' => str_repeat('a', 64),
    'artifact_snapshot_hash' => str_repeat('c', 64),
    'owner_token' => 'owner',
    'fencing_token' => 'fence',
    'forward_id' => 65,
    'runtime_row' => standalone_runtime_row(),
], $validArtifactContextFields));
assert_status($validActivate, 200, 'valid activate with required services');
foreach (['lock.requireHeld:leigod-only-activation:65', 'artifact.validate', 'runtime.assert:admin_activate'] as $read) {
    assert_true(in_array($read, $spy->reads, true), 'activate must call ' . $read . ': ' . json_encode($spy->reads));
}
foreach (['activation.apply'] as $mutator) {
    assert_true(in_array($mutator, $spy->mutators, true), 'activate must call ' . $mutator . ': ' . json_encode($spy->mutators));
}
assert_true($spy->lastRuntimeAssertRow === standalone_runtime_row(), 'activate must validate submitted runtime row, not a synthetic row: ' . json_encode($spy->lastRuntimeAssertRow));
assert_true(($spy->lastActivationPayload['runtime_row'] ?? null) === standalone_runtime_row(), 'activation payload must preserve runtime row: ' . json_encode($spy->lastActivationPayload));
assert_true(($spy->lastArtifactGateRequest['scope'] ?? '') === 'global', 'artifact gate context must include scope: ' . json_encode($spy->lastArtifactGateRequest));
assert_true(($spy->lastArtifactGateRequest['platform_family'] ?? '') === 'linux-bond', 'artifact gate context must include platform family: ' . json_encode($spy->lastArtifactGateRequest));
assert_true(($spy->lastArtifactGateRequest['artifact_snapshot_hash'] ?? '') === str_repeat('c', 64), 'artifact gate context must include artifact snapshot hash: ' . json_encode($spy->lastArtifactGateRequest));
foreach ($validArtifactContextFields as $field => $expectedValue) {
    assert_true(($spy->lastArtifactGateRequest[$field] ?? null) === $expectedValue, 'artifact gate context must forward ' . $field . ': ' . json_encode($spy->lastArtifactGateRequest));
}
assert_true(!array_key_exists('runtime_row', $spy->lastArtifactGateRequest ?? []), 'artifact gate context must not receive runtime row noise: ' . json_encode($spy->lastArtifactGateRequest));

$typedGateApp = app_with_typed_gate_activation_spy($spy);
$spy->clear();
$typedGateActivate = dispatch($typedGateApp, 'POST', '/admin/leigod-only-rollout/activate', activation_request([
    'desired_generation' => 9,
    'assignment_id' => 701,
    'assignment_hash' => str_repeat('6', 64),
    'desired_leg_set_hash' => str_repeat('7', 64),
    'write_mode' => 'activation',
    'flags' => ['activation_enabled' => true],
    'settings' => ['upload_min_mbps' => 10, 'download_min_mbps' => 10],
    'expected_artifact_pins' => ['client_agent.py' => str_repeat('1', 64)],
]));
assert_status($typedGateActivate, 200, 'valid activate with typed standalone gate service');
assert_true(in_array('activation.gate', $spy->mutators, true), 'typed gate activation service must receive LeigodActivationGateContext: ' . json_encode($spy->mutators));
assert_true(($spy->lastActivationGateContext['assignment_id'] ?? null) === 701, 'typed gate context must preserve assignment id: ' . json_encode($spy->lastActivationGateContext));
assert_true(($spy->lastActivationGateContext['runtime_row'] ?? null) === standalone_runtime_row(), 'typed gate context must preserve runtime row: ' . json_encode($spy->lastActivationGateContext));

$spy->clear();
$typedGateInvalid = dispatch($typedGateApp, 'POST', '/admin/leigod-only-rollout/activate', activation_request([
    'desired_generation' => 9,
    'assignment_hash' => str_repeat('6', 64),
    'runtime_row' => standalone_runtime_row(),
]));
assert_status($typedGateInvalid, 422, 'typed gate activation rejects missing assignment id');
assert_true(str_contains($typedGateInvalid->body(), 'missing_assignment_id'), 'typed gate context must fail closed before activation: ' . $typedGateInvalid->body());
assert_true(!in_array('activation.gate', $spy->mutators, true), 'typed gate invalid context must not call activation service body: ' . json_encode($spy->mutators));

$typedActivationApp = app_with_typed_activation_spy($spy);
$spy->clear();
$typedActivate = dispatch($typedActivationApp, 'POST', '/admin/leigod-only-rollout/activate', array_replace(retirement_activation_payload(), [
    'csrf_token' => 'csrf-task5',
    'scope' => 'global',
    'platform_family' => 'linux-bond',
    'plan_id' => 'plan-typed',
    'plan_hash' => str_repeat('a', 64),
    'confirm_plan_hash' => str_repeat('a', 64),
    'artifact_snapshot_hash' => str_repeat('c', 64),
    'owner_token' => str_repeat('4', 64),
    'fencing_token' => str_repeat('5', 64),
    'runtime_row' => standalone_runtime_row(),
]));
assert_status($typedActivate, 200, 'valid activate with typed retirement service');
assert_true(in_array('lock.requireHeld:leigod-only-retirement', $spy->reads, true), 'retirement activation must use retirement lock key: ' . json_encode($spy->reads));
assert_true(in_array('activation.typed', $spy->mutators, true), 'typed activation service must receive LeigodRetirementActivationContext: ' . json_encode($spy->mutators));
assert_true(($spy->lastActivationPayload['plan_hash'] ?? '') === str_repeat('a', 64), 'typed activation payload must preserve plan hash: ' . json_encode($spy->lastActivationPayload));

$typedNormalizationApp = app_with_typed_normalization_activation_spy($spy);
$spy->clear();
$typedNormalization = dispatch($typedNormalizationApp, 'POST', '/admin/leigod-only-rollout/activate', array_replace(normalization_activation_payload(), [
    'csrf_token' => 'csrf-task5',
    'scope' => 'global',
    'platform_family' => 'linux-bond',
    'plan_id' => 'plan-normalize-typed',
    'plan_hash' => str_repeat('a', 64),
    'confirm_plan_hash' => str_repeat('a', 64),
    'artifact_snapshot_hash' => str_repeat('c', 64),
    'owner_token' => str_repeat('4', 64),
    'fencing_token' => str_repeat('5', 64),
    'runtime_row' => standalone_runtime_row(),
]));
assert_status($typedNormalization, 200, 'valid activate with typed normalization service');
assert_true(in_array('lock.requireHeld:leigod-only-normalization', $spy->reads, true), 'normalization activation must use normalization lock key: ' . json_encode($spy->reads));
assert_true(in_array('activation.normalization.typed', $spy->mutators, true), 'typed normalization service must receive LeigodNormalizationActivationContext: ' . json_encode($spy->mutators));
assert_true(($spy->lastActivationPayload['affected_forward_ids'] ?? null) === [65], 'typed normalization payload must preserve affected forwards: ' . json_encode($spy->lastActivationPayload));

$applyOnlyNormalizationApp = app_with_apply_only_normalization_activation_spy($spy);
$spy->clear();
$applyOnlyNormalization = dispatch($applyOnlyNormalizationApp, 'POST', '/admin/leigod-only-rollout/activate', array_replace(normalization_activation_payload(), [
    'csrf_token' => 'csrf-task5',
    'scope' => 'global',
    'platform_family' => 'linux-bond',
    'plan_id' => 'plan-normalize-apply',
    'plan_hash' => str_repeat('a', 64),
    'confirm_plan_hash' => str_repeat('a', 64),
    'artifact_snapshot_hash' => str_repeat('c', 64),
    'owner_token' => str_repeat('4', 64),
    'fencing_token' => str_repeat('5', 64),
    'runtime_row' => standalone_runtime_row(),
]));
assert_status($applyOnlyNormalization, 200, 'valid activate with apply-only normalization service');
assert_true(in_array('lock.requireHeld:leigod-only-normalization', $spy->reads, true), 'apply-only normalization activation must use normalization lock key: ' . json_encode($spy->reads));
assert_true(in_array('activation.normalization.apply', $spy->mutators, true), 'apply-only normalization service must fall back to apply(): ' . json_encode($spy->mutators));

$spy->clear();
$validRollback = dispatch($spyApp, 'POST', '/admin/leigod-only-rollout/rollback', [
    'csrf_token' => 'csrf-task5',
    'scope' => 'global',
    'platform_family' => 'linux-bond',
    'batch_id' => '7',
    'confirm_batch_id' => '7',
    'batch_hash' => str_repeat('b', 64),
    'confirm_batch_hash' => str_repeat('b', 64),
    'owner_token' => str_repeat('4', 64),
    'fencing_token' => str_repeat('5', 64),
]);
assert_status($validRollback, 200, 'valid rollback with required services');
$validRollbackJson = response_json($validRollback, 'valid rollback');
assert_true(($validRollbackJson['result']['owner_token'] ?? '') === str_repeat('4', 64), 'rollback must pass 64-hex owner token to apply(): ' . $validRollback->body());
assert_true(($validRollbackJson['result']['fencing_token'] ?? '') === str_repeat('5', 64), 'rollback must pass 64-hex fencing token to apply(): ' . $validRollback->body());
assert_true(in_array('lock.requireHeld:activation-batch:7', $spy->reads, true), 'rollback must preflight activation-batch fence: ' . json_encode($spy->reads));
assert_true(!in_array('lock.requireHeld:rollback', $spy->reads, true), 'rollback must not preflight legacy rollback lock key: ' . json_encode($spy->reads));
assert_true(in_array('rollback.apply', $spy->mutators, true), 'rollback must call apply service method: ' . json_encode($spy->mutators));
assert_true(!in_array('rollback.unexpected', $spy->mutators, true), 'rollback must not call legacy rollback method: ' . json_encode($spy->mutators));

echo "ok\n";
