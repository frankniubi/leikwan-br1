<?php
declare(strict_types=1);

$baseDir = __DIR__ . '/..';

spl_autoload_register(static function (string $class) use ($baseDir): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = $baseDir . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

require_once $baseDir . '/src/Bootstrap.php';

use Leikwan\Master\Bootstrap;

$envPath = $baseDir . '/.env';
if (!is_file($envPath)) {
    echo "Error: .env not found at {$envPath}\n";
    exit(1);
}

$app = new Bootstrap($envPath);
$provisioning = $app->provisioning();

echo "Republishing active endpoints for node 2...\n";
$provisioning->republishActiveEndpointsForNode(2);
echo "Republishing active endpoints for node 3...\n";
$provisioning->republishActiveEndpointsForNode(3);

// Also republish sidecar point to update its WG peer ports
// Check sidecar point ID
$db = $app->db();
$points = $db->fetchAll('SELECT id, sidecar_host FROM sidecar_points WHERE id > 0 ORDER BY id ASC');
foreach ($points as $point) {
    $pointId = (int) $point['id'];
    echo "Republishing active forwards for sidecar point {$pointId} ({$point['sidecar_host']})...\n";
    $provisioning->republishActiveForwardsForSidecarPoint($pointId);
}

echo "Done. Pending tasks queued.\n";
