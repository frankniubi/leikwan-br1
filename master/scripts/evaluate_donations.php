<?php
declare(strict_types=1);

use Leikwan\Master\Bootstrap;

require_once __DIR__ . '/../src/Bootstrap.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\Master\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

try {
    $bootstrap = new Bootstrap(__DIR__ . '/../.env');
    $db = $bootstrap->db();
    $provisioning = $bootstrap->provisioning();
    $rows = $db->fetchAll('SELECT id FROM forward_combinations WHERE allow_public_self_leg_donation = 1 ORDER BY id ASC');
    foreach ($rows as $row) {
        $provisioning->evaluateDonationStateForCombination((int) ($row['id'] ?? 0));
    }
    echo "leikwan-donation-worker ok combinations=" . count($rows) . "
";
} catch (Throwable $throwable) {
    fwrite(STDERR, 'leikwan-donation-worker failed: ' . $throwable->getMessage() . "
");
    exit(1);
}
