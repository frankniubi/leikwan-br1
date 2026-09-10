<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Support/WhmcsClient.php');
if (!is_string($source) || $source === '') {
    fail('failed to read WhmcsClient source');
}

foreach (['CURLOPT_IPRESOLVE', 'CURL_IPRESOLVE_V4'] as $required) {
    if (!str_contains($source, $required)) {
        fail('WHMCS client does not force IPv4: ' . $required);
    }
}

echo "ok\n";
