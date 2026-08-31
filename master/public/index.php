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
    $path = $request?->path() ?? (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
    $clientSafeCodes = [
        'location_check_required',
        'direct_location_check_required',
        'location_report_invalid',
        'location_check_unavailable',
        'access_blacklisted',
        'geo_lock_violation',
        'device_temporarily_banned',
        'device_kicked',
        'device_banned',
        'device_untrusted',
        'account_banned',
        'debug_collection_invalid',
        'debug_collection_not_found',
        'debug_collection_expired',
        'debug_collection_replay',
        'debug_chunk_replay',
        'debug_chunk_invalid',
        'debug_chunk_too_large',
        'debug_chunk_hash_mismatch',
        'debug_collection_quota_exceeded',
    ];
    if ($exception instanceof ApiException
        && in_array($exception->getMessage(), $clientSafeCodes, true)
        && str_starts_with($path, '/api/client/')) {
        if ($status < 400 || $status > 599) {
            $status = 500;
        }
        $details = $exception->safeDetails();
        $payload = [
            'ok' => false,
            'error' => (string) $exception->getMessage(),
            'protocol_version' => 1,
        ];
        $challenge = $details['challenge'] ?? null;
        if (is_array($challenge)) {
            $payload['challenge_token'] = (string) ($challenge['token'] ?? '');
            $payload['challenge_expires_at'] = (string) ($challenge['expires_at'] ?? '');
            $payload['attempt_id'] = (string) ($challenge['attempt_id'] ?? '');
        }
        foreach ([
            'challenge_token', 'challenge_expires_at', 'attempt_id', 'required_collection_mode',
            'retry_after_seconds', 'blocked_until', 'next_location_check_at', 'matched_entity_type',
        ] as $field) {
            if (array_key_exists($field, $details)) {
                $payload[$field] = $details[$field];
            }
        }
        if (isset($details['violation_count'])) {
            $payload['remaining_geo_violations'] = max(0, 3 - (int) $details['violation_count']);
        }
        $command = $details['command'] ?? null;
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
    if ($status < 400 || $status >= 500) {
        $status = 500;
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
