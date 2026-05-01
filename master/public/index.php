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
    $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Application Error</title><style>body{font-family:Segoe UI,sans-serif;background:#f8fafc;color:#172033;padding:40px;max-width:920px;margin:0 auto} .card{background:#fff;border-radius:20px;padding:24px;box-shadow:0 16px 42px rgba(15,23,42,.08)} code,pre{font-family:Consolas,monospace} pre{background:#0f172a;color:#e2e8f0;padding:16px;border-radius:16px;overflow:auto} a{color:#2563eb}</style></head><body><div class="card"><h1>Application Error</h1><p>The app failed during bootstrap. If this is a fresh install or a new deployment, open <a href="/install.php">/install.php</a> and run the installer/update flow.</p><pre>'
        . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8')
        . '</pre></div></body></html>';
    $response = Response::html(500, $html);
}

$response->send();
