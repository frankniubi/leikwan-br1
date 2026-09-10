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

$controller = (new ReflectionClass(Leikwan\Master\Controllers\ClientAgentController::class))
    ->newInstanceWithoutConstructor();
$method = new ReflectionMethod($controller, 'scrubUltraClientForward');
$method->setAccessible(true);

$result = $method->invoke($controller, [
    'name' => '雷神 Leigod TYO',
    'tenant_label' => 'Leigod Ultra',
    'tenant_description' => 'Provider route',
    'tenant_badge' => 'recommended',
    'forward_id' => 158,
]);

if (($result['name'] ?? '') !== 'Ultra Ultra TYO') {
    fwrite(STDERR, "provider name was not neutralized\n");
    exit(1);
}
if (($result['tenant_label'] ?? '') !== 'Ultra Ultra') {
    fwrite(STDERR, "provider label was not neutralized\n");
    exit(1);
}
if (($result['tenant_description'] ?? '') !== 'Provider route') {
    fwrite(STDERR, "non-provider description changed unexpectedly\n");
    exit(1);
}

echo "ok\n";
