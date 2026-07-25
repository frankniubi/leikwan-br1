<?php
declare(strict_types=1);

use Leikwan\Master\Bootstrap;
use Leikwan\Master\Http\Request;
use Leikwan\Master\Http\Response;
use Leikwan\Master\Support\ApiException;

require_once __DIR__ . '/../src/Bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!is_file(__DIR__ . '/../.env')) {
    header('Location: /install.php');
    exit;
}

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

try {
    $bootstrap = new Bootstrap(__DIR__ . '/../.env');
    $response = $bootstrap->handle(Request::fromGlobals());
} catch (ApiException $exception) {
    $response = Response::json($exception->statusCode(), [
        'error' => $exception->getMessage(),
        'details' => $exception->details(),
    ]);
} catch (Throwable $exception) {
    $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Application Error</title><link rel="stylesheet" href="/assets/precision-grid.css?v=20260725.3"></head><body class="pg-public"><main class="landing-stack"><div class="pg-form-section"><h1>Application Error</h1><p>The app failed during bootstrap. If this is a fresh install or a new deployment, open <a href="/install.php">/install.php</a> and run the installer/update flow.</p><pre>'
        . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8')
        . '</pre></div></main></body></html>';
    $response = Response::html(500, $html);
}

$response->send();
