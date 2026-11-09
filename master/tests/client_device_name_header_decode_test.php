<?php
declare(strict_types=1);

// The desktop client sends a non-ASCII device name as an RFC 2047 UTF-8 base64
// encoded word, because HTTP header values must be ASCII. The panel must decode
// it back to the original name, and leave every other value exactly as sent.

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

use Leikwan\Master\Database\Database;
use Leikwan\Master\Http\Request;
use Leikwan\Master\Support\ClientOnlineSessionService;

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function deviceNameFromHeader(ClientOnlineSessionService $service, string $headerValue): string
{
    $request = new Request(
        'POST',
        '/api/client/sessions',
        [],
        [],
        ['x-device-name' => $headerValue],
        '',
        '203.0.113.7',
    );

    return $service->deviceMetadataFromRequest($request)['device_name'];
}

// The constructor only stores the handle; device metadata never queries it.
$service = new ClientOnlineSessionService(
    (new ReflectionClass(Database::class))->newInstanceWithoutConstructor(),
);

$chineseName = 'Leikwan Bridge on 办公机';
$encodedChinese = '=?UTF-8?B?' . base64_encode($chineseName) . '?=';
if (deviceNameFromHeader($service, $encodedChinese) !== $chineseName) {
    fail('encoded non-ASCII device name must decode to the original text');
}

$plainName = 'Leikwan Bridge on DEVBOX';
if (deviceNameFromHeader($service, $plainName) !== $plainName) {
    fail('plain ASCII device name must pass through unchanged');
}

$malformed = '=?UTF-8?B?!!!?=';
if (deviceNameFromHeader($service, $malformed) !== $malformed) {
    fail('malformed encoded word must be kept as sent');
}

$notUtf8 = '=?UTF-8?B?' . base64_encode("\xFF\xFE") . '?=';
if (deviceNameFromHeader($service, $notUtf8) !== $notUtf8) {
    fail('encoded word that is not valid UTF-8 must be kept as sent');
}

$request = new Request(
    'POST',
    '/api/client/sessions',
    [],
    ['device_name' => 'Body Device'],
    [],
    '',
    '203.0.113.7',
);
if ($service->deviceMetadataFromRequest($request)['device_name'] !== 'Body Device') {
    fail('device_name from the body must still be used when the header is absent');
}

echo "ok\n";
