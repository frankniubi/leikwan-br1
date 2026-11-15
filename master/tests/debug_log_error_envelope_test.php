<?php
declare(strict_types=1);

use Leikwan\Master\Http\Request;
use Leikwan\Master\Support\ApiException;

spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (str_starts_with($class, $prefix)) {
        require_once __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

// Exercise the same public responder that serializes upload failures in production.
$index = (string) file_get_contents(__DIR__ . '/../public/index.php');
$start = strpos($index, 'function publicErrorResponse(');
if ($start === false) {
    throw new RuntimeException('public error responder missing');
}
$next = strpos($index, "\nfunction ", $start + 1);
$source = $next === false ? substr($index, $start) : substr($index, $start, $next - $start);
eval('use Leikwan\Master\Http\Request; use Leikwan\Master\Http\Response; use Leikwan\Master\Support\ApiException; ' . $source);

$request = new Request('POST', '/api/client/debug/log', [], [], [], '');
foreach ([
    'debug_upload_invalid' => 422,
    'debug_collection_not_found' => 404,
    'debug_chunk_replay' => 409,
    'debug_collection_expired' => 410,
    'debug_collection_quota_exceeded' => 413,
] as $code => $status) {
    $response = publicErrorResponse($request, new ApiException($code, $status));
    $body = json_decode($response->body(), true);
    if ($response->status() !== $status
        || $body !== ['ok' => false, 'error' => $code, 'protocol_version' => 1]) {
        throw new RuntimeException('upload failure lost its stable HTTP/code envelope: ' . $code);
    }
}
echo "ok\n";
