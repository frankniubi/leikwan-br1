<?php
declare(strict_types=1);

function failSeed(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertSeedSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        failSeed($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertSeedThrows(string $expected, callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable $throwable) {
        assertSeedSame($expected, $throwable->getMessage(), $message);
        return;
    }
    failSeed($message . ': expected exception ' . $expected);
}

final class SeedFakeConfig
{
    /** @param array<string, string> $values */
    public function __construct(private array $values)
    {
    }

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->values[$key] ?? $default;
    }
}

final class SeedFakeDatabase
{
    /** @var array<string, array<string, mixed>> */
    public array $combinations = [];
    /** @var array<string, array<string, mixed>> */
    public array $routes = [];
    /** @var array<int, array<string, mixed>> */
    public array $servicePlans = [];
    /** @var array<int, array<string, mixed>> */
    public array $services = [];
    /** @var array<int, array<string, mixed>> */
    public array $purchases = [];
    /** @var array<int, array<string, mixed>> */
    public array $tenantBindings = [];

    /** @param array<string, mixed> $params */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $name = (string) ($params['name'] ?? '');
        if (str_contains($sql, 'FROM forward_combinations')) {
            return $this->combinations[$name] ?? null;
        }
        if (str_contains($sql, 'FROM forward_routes')) {
            return $this->routes[$name] ?? null;
        }
        return null;
    }
}

final class SeedFakeProvisioning
{
    /** @var array<int, array<string, mixed>> */
    public array $lines = [];
    /** @var array<int, string> */
    public array $calledMethods = [];
    private int $nextLineId = 1;
    private int $nextCombinationId = 101;
    private int $nextRouteId = 201;

    public function __construct(private SeedFakeDatabase $db)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function listLeigodLines(): array
    {
        $this->calledMethods[] = __FUNCTION__;
        return array_values($this->lines);
    }

    /** @param array<string, mixed> $payload */
    public function saveLeigodLine(array $payload): array
    {
        $this->calledMethods[] = __FUNCTION__;
        $lineId = (int) $payload['line_id'];
        $id = (int) ($payload['id'] ?? ($this->lines[$lineId]['id'] ?? 0));
        if ($id <= 0) {
            $id = $this->nextLineId++;
        }
        return $this->lines[$lineId] = $payload + [
            'id' => $id,
            'configuration_state' => 'ready',
        ];
    }

    /** @param array<string, mixed> $payload */
    public function saveForwardCombination(array $payload): array
    {
        $this->calledMethods[] = __FUNCTION__;
        $name = (string) $payload['name'];
        $id = (int) ($payload['id'] ?? ($this->db->combinations[$name]['id'] ?? 0));
        if ($id <= 0) {
            $id = $this->nextCombinationId++;
        }
        return $this->db->combinations[$name] = $payload + ['id' => $id];
    }

    /** @param array<string, mixed> $payload */
    public function saveForwardRoute(array $payload): array
    {
        $this->calledMethods[] = __FUNCTION__;
        $name = (string) $payload['name'];
        $id = (int) ($payload['id'] ?? ($this->db->routes[$name]['id'] ?? 0));
        if ($id <= 0) {
            $id = $this->nextRouteId++;
        }
        $this->db->routes[$name] = $payload + ['id' => $id];
        return ['id' => $id];
    }
}

$cliPath = __DIR__ . '/../cli/seed-flexible-leigod-routes.php';
if (!is_file($cliPath)) {
    failSeed('seed CLI is missing');
}
define('FLEXIBLE_LEIGOD_SEED_LIBRARY_ONLY', true);
require_once $cliPath;

$lineIds = [451, 453, 456, 455, 447];
$env = [];
$tomls = [];
foreach ($lineIds as $lineId) {
    $toml = "profile = \"test-{$lineId}\"\n";
    $tomls[$lineId] = $toml;
    $env['LEIGOD_LINE_TOML_' . $lineId] = base64_encode($toml);
    $env['LEIGOD_LINE_SHA256_' . $lineId] = hash('sha256', $toml);
}

$defaults = flexibleLeigodSeedParseArguments(['seed-flexible-leigod-routes.php']);
assertSeedSame(false, $defaults['apply'], 'default is dry-run');
assertSeedSame(false, $defaults['activate'], 'default does not activate routes');
assertSeedSame('shanghai-alive', $defaults['node_group'], 'default node group');

$options = flexibleLeigodSeedParseArguments([
    'seed-flexible-leigod-routes.php',
    '--node-group=test-live',
    '--tcp-probe-host=198.51.100.20',
    '--tcp-probe-port=8080',
    '--udp-probe-host=198.51.100.53',
    '--udp-probe-port=53',
    '--udp-probe-method=dns',
]);
$config = new SeedFakeConfig($env);
$db = new SeedFakeDatabase();
$provisioning = new SeedFakeProvisioning($db);

$dryRun = flexibleLeigodSeedRun($options, $config, $db, $provisioning);
assertSeedSame('dry-run', $dryRun['mode'], 'dry-run summary mode');
assertSeedSame(5, $dryRun['readiness']['ready'], 'all five configs ready');
assertSeedSame(5, $dryRun['desired']['catalog_rows'], 'five catalog rows proposed');
assertSeedSame(5, $dryRun['desired']['combinations'], 'five combinations proposed');
assertSeedSame(5, $dryRun['desired']['single_routes'], 'five single routes proposed');
assertSeedSame(1, $dryRun['desired']['race_routes'], 'one race route proposed');
assertSeedSame([], $provisioning->lines, 'dry-run writes no catalog rows');
assertSeedSame([], $db->combinations, 'dry-run writes no combinations');
assertSeedSame([], $db->routes, 'dry-run writes no routes');
assertSeedSame(['listLeigodLines'], $provisioning->calledMethods, 'dry-run only reads public catalog state');

$badEnv = $env;
$badEnv['LEIGOD_LINE_SHA256_451'] = str_repeat('f', 64);
assertSeedThrows(
    'leigod_line_451_hash_mismatch',
    static fn(): array => flexibleLeigodSeedRun($options, new SeedFakeConfig($badEnv), new SeedFakeDatabase(), new SeedFakeProvisioning(new SeedFakeDatabase())),
    'exact expected hash is required'
);
$missingEnv = $env;
unset($missingEnv['LEIGOD_LINE_TOML_447']);
assertSeedThrows(
    'leigod_line_447_config_missing',
    static fn(): array => flexibleLeigodSeedRun($options, new SeedFakeConfig($missingEnv), new SeedFakeDatabase(), new SeedFakeProvisioning(new SeedFakeDatabase())),
    'all five configs are required'
);

$applyOptions = $options;
$applyOptions['apply'] = true;
$firstApply = flexibleLeigodSeedRun($applyOptions, $config, $db, $provisioning);
assertSeedSame('apply', $firstApply['mode'], 'apply summary mode');
assertSeedSame(5, count($provisioning->lines), 'first apply creates five catalog rows');
assertSeedSame(5, count($db->combinations), 'first apply creates five combinations');
assertSeedSame(6, count($db->routes), 'first apply creates five single and one race route');

$secondApply = flexibleLeigodSeedRun($applyOptions, $config, $db, $provisioning);
assertSeedSame(5, count($provisioning->lines), 'second apply creates no duplicate catalog rows');
assertSeedSame(5, count($db->combinations), 'second apply creates no duplicate combinations');
assertSeedSame(6, count($db->routes), 'second apply creates no duplicate routes');
assertSeedSame(5, $secondApply['changes']['catalog_rows']['updated'], 'second apply updates catalog by stable identity');
assertSeedSame(5, $secondApply['changes']['combinations']['updated'], 'second apply updates combinations by stable name');
assertSeedSame(6, $secondApply['changes']['routes']['updated'], 'second apply updates routes by stable name');

foreach ($provisioning->lines as $line) {
    assertSeedSame('198.51.100.20', $line['tcp_probe_host'], 'TCP probe host override');
    assertSeedSame(8080, $line['tcp_probe_port'], 'TCP probe port override');
    assertSeedSame('198.51.100.53', $line['udp_probe_host'], 'UDP probe host override');
    assertSeedSame(53, $line['udp_probe_port'], 'UDP probe port override');
    assertSeedSame('dns', $line['udp_probe_method'], 'UDP probe method override');
}
foreach ($db->combinations as $combination) {
    assertSeedSame('test-live', $combination['node_group_key'], 'node group override');
    assertSeedSame('leigod_mode', $combination['backend_mode'], 'dedicated LeiGod backend');
    assertSeedSame('single', $combination['client_mode'], 'single client ingress');
    assertSeedSame(1, count($combination['leigod_lines']), 'each initial combination is pinned to one line');
}
foreach ($db->routes as $route) {
    assertSeedSame(0, (int) $route['is_active'], 'apply without activate leaves every route inactive');
}
$race = $db->routes['flex-leigod-race-all-directions'] ?? null;
if (!is_array($race)) {
    failSeed('all-direction race route missing');
}
assertSeedSame(1, (int) $race['race_enabled'], 'all-direction route races');
assertSeedSame(5, count($race['combinations']), 'all-direction race contains all five combinations');

$activateOptions = $applyOptions;
$activateOptions['activate'] = true;
flexibleLeigodSeedRun($activateOptions, $config, $db, $provisioning);
assertSeedSame(6, count($db->routes), 'activation remains idempotent');
foreach ($db->routes as $route) {
    assertSeedSame(1, (int) $route['is_active'], 'activate explicitly enables routes');
}

assertSeedThrows(
    'activate_requires_apply',
    static fn(): array => flexibleLeigodSeedRun(array_replace($options, ['activate' => true]), $config, $db, $provisioning),
    'activate cannot write without apply'
);

assertSeedSame([], $db->servicePlans, 'seeder creates no service plans');
assertSeedSame([], $db->services, 'seeder creates no services');
assertSeedSame([], $db->purchases, 'seeder creates no purchases');
assertSeedSame([], $db->tenantBindings, 'seeder creates no tenant bindings');

$summaryJson = flexibleLeigodSeedSummaryJson($firstApply);
foreach ($tomls as $toml) {
    if (str_contains($summaryJson, $toml) || str_contains($summaryJson, base64_encode($toml))) {
        failSeed('summary exposes TOML material');
    }
}
foreach ($env as $key => $value) {
    if (str_starts_with($key, 'LEIGOD_LINE_SHA256_') && str_contains($summaryJson, $value)) {
        failSeed('summary exposes a configuration hash');
    }
}

echo "ok\n";
