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

use Leikwan\Master\Support\NativeSocks5Probe;

function failNativeProbe(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$invalid = NativeSocks5Probe::verify('', 0, '', '', 'example.com', 443);
if ($invalid->succeeded || $invalid->tcpSucceeded || $invalid->udpSucceeded || $invalid->errorCode !== 'probe_input_invalid') {
    failNativeProbe('invalid probe input was not rejected');
}

$source = (string) file_get_contents(__DIR__ . '/../src/Support/NativeSocks5Probe.php');
foreach (['AUTH_USERNAME_PASSWORD', 'CMD_CONNECT', 'CMD_UDP_ASSOCIATE', 'udpEnvelope', 'tcpSucceeded', 'udpSucceeded'] as $needle) {
    if (!str_contains($source, $needle)) {
        failNativeProbe('probe implementation boundary missing: ' . $needle);
    }
}

echo "ok\n";
