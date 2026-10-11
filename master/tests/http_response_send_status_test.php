<?php
declare(strict_types=1);

// Response::send() runs under a real SAPI here because the bug only exists
// there: PHP rewrites any non-201/3xx status to 302 when a Location header is
// sent after http_response_code(), so the 200 idempotent replay reached
// tenants as 302 Found (ticket ZZN-514526).

function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }

$dir = sys_get_temp_dir() . '/lk-response-send-' . bin2hex(random_bytes(4));
mkdir($dir);
$responseFile = var_export(realpath(__DIR__ . '/../src/Http/Response.php'), true);
file_put_contents($dir . '/router.php', <<<PHP
<?php
require {$responseFile};
use Leikwan\Master\Http\Response;
\$status = (int) (\$_GET['status'] ?? 200);
Response::json(\$status, ['ok' => true])->withHeaders(['Location' => '/api/v1/user/forwards/296'])->send();
PHP);

$probe = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr((string) stream_socket_get_name($probe, false), strlen('127.0.0.1:'));
fclose($probe);
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $dir . '/router.php'], [1 => ['file', $dir . '/out.log', 'w'], 2 => ['file', $dir . '/err.log', 'w']], $pipes);
if (!is_resource($server)) {
    fail('could not start php -S');
}

$statusFor = static function (int $status) use ($port): int {
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1.0);
        if ($socket === false) {
            usleep(100000);
            continue;
        }
        fwrite($socket, "GET /?status={$status} HTTP/1.0\r\nHost: 127.0.0.1\r\n\r\n");
        $line = (string) fgets($socket);
        fclose($socket);
        return (int) (explode(' ', $line)[1] ?? 0);
    }
    fail('php -S never accepted connections');
};

try {
    foreach ([200 => 200, 201 => 201, 302 => 302] as $requested => $expected) {
        $actual = $statusFor($requested);
        if ($actual !== $expected) {
            fail("status {$requested} with a Location header was sent as {$actual}");
        }
    }
} finally {
    proc_terminate($server);
    proc_close($server);
    array_map('unlink', glob($dir . '/*') ?: []);
    rmdir($dir);
}

echo "http_response_send_status_test: ok\n";
