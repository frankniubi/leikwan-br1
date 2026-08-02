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

use Leikwan\Master\Config;
use Leikwan\Master\Controllers\ServiceController;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Support\ProvisioningService;

function failLegacyNormalization(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$env = tempnam(sys_get_temp_dir(), 'lw-legacy-normalize-');
if ($env === false) {
    failLegacyNormalization('failed to create temporary env');
}
file_put_contents($env, "DB_DRIVER=mysql\n");
$config = new Config($env);
$db = new Database($config);
$controller = new ServiceController($config, $db, new ProvisioningService($config, $db));
$method = new ReflectionMethod($controller, 'normalizeLegacyCreatePayload');
$method->setAccessible(true);

$normalized = $method->invoke($controller, [
    'forward_route_id' => '14',
    'listen_port' => '',
    'target_host' => 'backend.example.com',
    'target_port' => '443',
    'target_source' => 'manual',
    'transport_mode' => 'BOTH',
    'endpoint_count' => '99',
]);

if (($normalized['forward_route_id'] ?? null) !== 14
    || ($normalized['listen_port'] ?? null) !== 0
    || ($normalized['target_port'] ?? null) !== 443
    || ($normalized['transport_mode'] ?? null) !== 'tcp+udp'
    || array_key_exists('endpoint_count', $normalized)) {
    failLegacyNormalization('safe legacy scalars and aliases were not canonicalized: ' . json_encode($normalized));
}

@unlink($env);
echo "ok\n";
