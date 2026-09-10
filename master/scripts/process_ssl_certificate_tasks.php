<?php
declare(strict_types=1);

use Leikwan\Master\Bootstrap;
use Leikwan\Master\Support\SslCertificateService;

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

$args = [];
for ($index = 1; $index < $argc; $index += 2) {
    $key = $argv[$index] ?? '';
    $value = $argv[$index + 1] ?? '';
    if (str_starts_with($key, '--')) {
        $args[substr($key, 2)] = $value;
    }
}

$limit = max(1, (int) ($args['limit'] ?? 5));

try {
    $bootstrap = new Bootstrap(__DIR__ . '/../.env');
    $service = new SslCertificateService($bootstrap->config(), $bootstrap->db());
    $summary = $service->processPendingTasks($limit);

    if ((int) $summary['processed'] > 0) {
        echo sprintf(
            "processed=%d completed=%d failed=%d\n",
            (int) $summary['processed'],
            (int) $summary['completed'],
            (int) $summary['failed']
        );
    }
} catch (Throwable $throwable) {
    fwrite(STDERR, "ssl task worker failed: {$throwable->getMessage()}\n");
    exit(1);
}
