<?php
declare(strict_types=1);

// Renders client_online_limit_reached through the real public error responder
// so every client parses the same envelope the panel actually sends.

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

use Leikwan\Master\Http\Request;
use Leikwan\Master\Support\ApiException;

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$index = (string) file_get_contents(__DIR__ . '/../public/index.php');
$start = strpos($index, 'function publicErrorResponse(');
if ($start === false) {
    fail('publicErrorResponse not found in public/index.php');
}
$next = strpos($index, "\nfunction ", $start + 1);
$source = $next === false ? substr($index, $start) : substr($index, $start, $next - $start);
eval('use Leikwan\Master\Http\Request; use Leikwan\Master\Http\Response; use Leikwan\Master\Support\ApiException; ' . $source);

$exception = new ApiException('client_online_limit_reached', 429, [
    'online_device_limit' => 2,
    'active_online_devices' => 2,
    'client_id' => 99,
]);
$request = new Request('GET', '/api/client/configs/pull', [], [], [], '');
set_error_handler(static fn() => true);
$response = publicErrorResponse($request, $exception);
restore_error_handler();

if ($response->status() !== 429) {
    fail('expected HTTP 429, got ' . $response->status());
}
$body = json_decode($response->body(), true);
if (!is_array($body)) {
    fail('expected a JSON body');
}
$expected = [
    'ok' => false,
    'error' => 'client_online_limit_reached',
    'protocol_version' => 1,
    'online_device_limit' => 2,
    'active_online_devices' => 2,
    'details' => ['online_device_limit' => 2, 'active_online_devices' => 2],
];
if ($body !== $expected) {
    fail('unexpected envelope: ' . json_encode($body));
}
if (str_contains($response->body(), 'client_id')) {
    fail('internal details must not leak into the client envelope');
}

echo "ok\n";
