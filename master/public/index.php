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

$request = null;
try {
    $request = Request::fromGlobals();
    $bootstrap = new Bootstrap(__DIR__ . '/../.env');
    $response = $bootstrap->handle($request);
} catch (Throwable $exception) {
    $response = publicErrorResponse($request, $exception);
}

$response->send();

function publicErrorResponse(?Request $request, Throwable $exception): Response
{
    try {
        $referenceId = bin2hex(random_bytes(8));
    } catch (Throwable) {
        $referenceId = substr(hash('sha256', microtime(true) . ':' . getmypid()), 0, 16);
    }
    error_log('unhandled application exception [' . $referenceId . ']: ' . $exception::class);

    $status = $exception instanceof ApiException ? $exception->statusCode() : 500;
    if ($status < 400 || $status >= 500) {
        $status = 500;
    }
    $path = $request?->path() ?? (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
    if (in_array($exception->getMessage(), ['device_kicked', 'device_banned', 'device_untrusted'], true)
        && str_starts_with($path, '/api/client/')) {
        $payload = [
            'ok' => false,
            'error' => $exception->getMessage(),
        ];
        $command = $exception instanceof ApiException ? ($exception->details()['command'] ?? null) : null;
        if (is_array($command)) {
            $payload['command'] = [
                'version' => max(0, (int) ($command['version'] ?? 0)),
                'action' => (string) ($command['action'] ?? ''),
                'reason' => (string) ($command['reason'] ?? ''),
            ];
        }
        return Response::json($status, $payload)->withHeaders([
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
    $error = match ($status) {
        400 => ['invalid_request', 'The request could not be processed.'],
        401 => ['unauthorized', 'Authentication is required.'],
        403 => ['forbidden', 'The requested operation is not allowed.'],
        404 => ['not_found', 'The requested resource was not found.'],
        405 => ['method_not_allowed', 'The request method is not allowed.'],
        409 => ['conflict', 'The request conflicts with current resource state.'],
        413 => ['payload_too_large', 'The request payload is too large.'],
        415 => ['unsupported_media_type', 'The request content type is not supported.'],
        422 => ['validation_failed', 'The request data is invalid.'],
        428 => ['precondition_required', 'A request precondition is required.'],
        default => ['internal_error', 'The operation could not be completed.'],
    };
    $headers = [
        'Cache-Control' => 'no-store',
        'X-Content-Type-Options' => 'nosniff',
    ];

    if (str_starts_with($path, '/api/')) {
        return Response::json($status, [
            'error' => [
                'code' => $error[0],
                'message' => $error[1],
                'reference_id' => $referenceId,
            ],
        ])->withHeaders($headers);
    }

    $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Application Error</title><link rel="stylesheet" href="/assets/leikwan-ui.css?v=20260725"><link rel="stylesheet" href="/assets/signal-chamber.css?v=20260725"><link rel="stylesheet" href="/assets/bridge-modern.css?v=20260729a"></head><body class="pg-public"><main class="landing-stack"><div class="pg-form-section"><h1>Application Error</h1><p>'
        . htmlspecialchars($error[1], ENT_QUOTES, 'UTF-8')
        . '</p><pre>Reference: ' . htmlspecialchars($referenceId, ENT_QUOTES, 'UTF-8')
        . '</pre></div></main></body></html>';
    return Response::html($status, $html)->withHeaders($headers);
}
