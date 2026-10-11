<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @param array<int, array<string, mixed>> $rows */
        public function __construct(private array $rows)
        {
        }

        /** @return array<int, array<string, mixed>> */
        public function fetchAll(string $sql, array $params = []): array
        {
            $allowed = array_map('intval', array_values($params));
            return array_values(array_filter(
                $this->rows,
                static fn(array $row): bool => in_array((int) ($row['service_id'] ?? 0), $allowed, true)
            ));
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

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Support\ProvisioningService;
use Leikwan\Master\Support\UltraMode;

function failNativePull(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$envPath = tempnam(sys_get_temp_dir(), 'lw-native-pull-');
if ($envPath === false) {
    failNativePull('failed to create env file');
}
file_put_contents($envPath, "DB_DRIVER=sqlite\nDB_NAME=:memory:\n");
$config = new Config($envPath);
$db = new Database([
    ['service_id' => 11, 'plan_feature_flags_json' => '{"allow_native_outbound":false}'],
    ['service_id' => 12, 'plan_feature_flags_json' => '{"allow_native_outbound":true}'],
]);

$provisioning = new ProvisioningService($config, $db);
$denied = $provisioning->nativeOutboundCapabilityForAssignedForwards([[
    'service_id' => 11,
    'forward_id' => 158,
    'backend_mode' => UltraMode::BACKEND_MODE,
    'service_status' => 'active',
    'transport' => 'tcp+udp',
    'native_outbound_enabled' => 1,
    'forward_combination_direction' => 'Hong Kong to Mainland China',
    'endpoints' => [],
]]);
if ($denied !== null) {
    failNativePull('denied plan exposed native capability');
}

$allowed = $provisioning->nativeOutboundCapabilityForAssignedForwards([[
    'service_id' => 12,
    'forward_id' => 159,
    'backend_mode' => UltraMode::BACKEND_MODE,
    'service_status' => 'active',
    'transport' => 'tcp+udp',
    'native_outbound_enabled' => 1,
    'forward_combination_direction' => 'Leigod Hong Kong to Mainland China',
    'endpoints' => [],
], [
    'service_id' => 12,
    'forward_id' => 160,
    'backend_mode' => UltraMode::BACKEND_MODE,
    'service_status' => 'active',
    'transport' => 'tcp+udp',
    'native_outbound_enabled' => 1,
    'forward_combination_direction' => 'Shanghai to Japan',
    'endpoints' => [],
], [
    'service_id' => 12,
    'forward_id' => 161,
    'backend_mode' => UltraMode::BACKEND_MODE,
    'service_status' => 'active',
    'transport' => 'tcp+udp',
    'native_outbound_enabled' => 1,
    'forward_combination_direction' => 'Mainland China -> International',
    'endpoints' => [],
]]);
if (!is_array($allowed) || ($allowed['enabled'] ?? false) !== true) {
    failNativePull('allowed plan did not expose native capability');
}
if (($allowed['default_forward_id'] ?? 0) !== 159 || ($allowed['forward_ids'] ?? []) !== [159, 160, 161]) {
    failNativePull('native carrier ordering/default is unstable');
}
$first = $allowed['forwards'][0] ?? [];
$second = $allowed['forwards'][1] ?? [];
$third = $allowed['forwards'][2] ?? [];
if (($first['route_scopes'] ?? []) !== ['cn_only', 'global']
    || ($first['default_route_scope'] ?? '') !== 'global'
    || ($second['route_scopes'] ?? []) !== ['intl_only', 'global']
    || ($third['route_scopes'] ?? []) !== ['intl_only', 'global']) {
    failNativePull('native route scopes do not follow carrier direction');
}
if (!in_array('intl_only', (array) ($allowed['route_scopes'] ?? []), true)) {
    failNativePull('top-level native scopes omitted intl_only');
}
$expectedDigest = UltraMode::nativeScopeDigest(159, 'Ultra Hong Kong to Mainland China', 'tcp+udp', 'global');
if (($first['scope_digest'] ?? '') !== $expectedDigest) {
    failNativePull('default native scope digest mismatch');
}
$serialized = strtolower((string) json_encode($allowed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
foreach (['leigod', 'source_id', 'line_id', 'credential', 'password', 'endpoint'] as $forbidden) {
    if (str_contains($serialized, $forbidden)) {
        failNativePull('native capability exposed forbidden field: ' . $forbidden);
    }
}

$controllerSource = file_get_contents(__DIR__ . '/../src/Controllers/ClientAgentController.php');
if (!is_string($controllerSource)
    || !str_contains($controllerSource, "['native_outbound']")
    || !str_contains($controllerSource, 'nativeOutboundCapabilityForAssignedForwards')) {
    failNativePull('pull controller does not conditionally publish native_outbound');
}

@unlink($envPath);
echo "ok\n";
}
