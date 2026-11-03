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

use Leikwan\Master\Http\Request;
use Leikwan\Master\Http\Response;
use Leikwan\Master\Router;

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$router = new Router();
$ok = static fn(): Response => Response::json(200, ['ok' => true]);
$router->add('GET', '/api/v1/user/forwards/{id}', $ok);
$router->add('PATCH', '/api/v1/user/forwards/{id}', $ok);
$router->add('DELETE', '/api/v1/user/forwards/{id}', $ok);

$wrongMethod = $router->dispatch(new Request('PUT', '/api/v1/user/forwards/7', [], [], [], ''));
$body = json_decode($wrongMethod->body(), true);
if ($wrongMethod->status() !== 405
    || ($body['ok'] ?? null) !== false
    || ($body['error']['code'] ?? '') !== 'method_not_allowed'
    || ($wrongMethod->headers()['Allow'] ?? '') !== 'GET, PATCH, DELETE') {
    fail('v1 wrong method must be a 405 envelope with Allow, got ' . $wrongMethod->status() . ' ' . $wrongMethod->body());
}

$unknown = $router->dispatch(new Request('GET', '/api/v1/user/nope', [], [], [], ''));
$body = json_decode($unknown->body(), true);
if ($unknown->status() !== 404 || ($body['ok'] ?? null) !== false || ($body['error']['code'] ?? '') !== 'not_found' || isset($body['path'])) {
    fail('v1 unknown path must be a 404 envelope, got ' . $unknown->body());
}

$legacy = $router->dispatch(new Request('GET', '/api/agent/nope', [], [], [], ''));
$body = json_decode($legacy->body(), true);
if ($legacy->status() !== 404 || ($body['error'] ?? '') !== 'not_found' || ($body['path'] ?? '') !== '/api/agent/nope') {
    fail('non-v1 misses keep the existing body, got ' . $legacy->body());
}

echo "ok\n";
