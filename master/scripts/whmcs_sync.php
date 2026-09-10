<?php
declare(strict_types=1);

use Leikwan\Master\Bootstrap;
use Leikwan\Master\Support\ApiException;

require_once __DIR__ . '/../src/Bootstrap.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

$bootstrap = new Bootstrap(__DIR__ . '/../.env');
$db = $bootstrap->db();
$provisioning = $bootstrap->provisioning();
$whmcs = $bootstrap->whmcsClient();

$services = $db->fetchAll('SELECT id, whmcs_service_id FROM services ORDER BY id ASC');

foreach ($services as $service) {
    try {
        $response = $whmcs->request('GetClientsProducts', [
            'serviceid' => $service['whmcs_service_id'],
        ]);
        $products = $response['products']['product'] ?? [];
        if (!is_array($products) || $products === []) {
            continue;
        }
        $product = is_array($products[0] ?? null) ? $products[0] : $products;
        $status = strtolower((string) ($product['status'] ?? ''));
        $expireAt = (string) ($product['nextduedate'] ?? '');
        $payload = [
            'whmcs_service_id' => (string) $service['whmcs_service_id'],
            'expire_at' => $expireAt !== '' ? $expireAt . ' 00:00:00' : gmdate('Y-m-d H:i:s', strtotime('+30 days')),
        ];

        if ($status === 'active') {
            $provisioning->renewService($payload);
            echo "renewed service {$service['whmcs_service_id']}\n";
        } elseif ($status === 'suspended') {
            $payload['reason'] = 'billing';
            $provisioning->suspendService($payload);
            echo "suspended service {$service['whmcs_service_id']}\n";
        } elseif (in_array($status, ['terminated', 'cancelled'], true)) {
            $provisioning->terminateService($payload);
            echo "terminated service {$service['whmcs_service_id']}\n";
        }
    } catch (ApiException $exception) {
        fwrite(STDERR, "sync failed for {$service['whmcs_service_id']}: {$exception->getMessage()}\n");
    } catch (Throwable $exception) {
        fwrite(STDERR, "unexpected failure for {$service['whmcs_service_id']}: {$exception->getMessage()}\n");
    }
}
