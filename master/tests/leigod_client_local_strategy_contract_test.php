<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    if ($relative === false) {
        return;
    }
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use Leikwan\Master\Support\ProvisioningService;

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function call_private_without_constructor(string $method, mixed ...$args): mixed
{
    $class = new ReflectionClass(ProvisioningService::class);
    $service = $class->newInstanceWithoutConstructor();
    $reflection = $class->getMethod($method);
    $reflection->setAccessible(true);
    return $reflection->invoke($service, ...$args);
}

$cap = call_private_without_constructor('reconcileCombinationSelectorCap', [
    'backend_mode' => 'leigod_mode',
    'client_mode' => 'bond',
], [
    'helper_group_cap' => 4,
]);
if ($cap !== 8) {
    fail('LeiGod bond must publish up to eight eligible Slaves, got cap ' . var_export($cap, true));
}

$candidates = [];
for ($nodeId = 11; $nodeId <= 19; $nodeId++) {
    $candidates[] = [
        'candidate_source' => 'rented_node',
        'row' => ['id' => $nodeId, 'public_ip' => '192.0.2.' . $nodeId],
    ];
}
$candidates[] = [
    'candidate_source' => 'rented_node',
    'row' => ['id' => 11, 'public_ip' => '192.0.2.111'],
];
$candidates[] = [
    'candidate_source' => 'self_leg_donor',
    'row' => ['id' => 99, 'public_ip' => '192.0.2.99'],
];

$nodes = call_private_without_constructor('reconcileRentedNodes', $candidates, 8);
$nodeIds = array_map('intval', array_column($nodes, 'id'));
if ($nodeIds !== [11, 12, 13, 14, 15, 16, 17, 18]) {
    fail('eligible Slave selection must deduplicate and cap at eight: ' . json_encode($nodeIds));
}

$source = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php') ?: '';
$buildStart = strpos($source, 'public function buildClientConfig(int $forwardId): array');
$buildEnd = strpos($source, 'public function ', ($buildStart === false ? 0 : $buildStart + 1));
if ($buildStart === false) {
    fail('buildClientConfig source not found');
}
$buildSource = substr($source, $buildStart, $buildEnd === false ? null : $buildEnd - $buildStart);
foreach (['client_strategy', 'strategy_mode', 'latency_ms_by_node'] as $forbidden) {
    if (str_contains($buildSource, $forbidden)) {
        fail('panel config leaks client-local field: ' . $forbidden);
    }
}

echo "ok\n";
