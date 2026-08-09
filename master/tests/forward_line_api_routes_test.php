<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Bootstrap.php');
if (!is_string($source) || $source === '') {
    fail('failed to read Bootstrap.php');
}

foreach ([
    'ForwardLineApiController',
    "use Leikwan\\Master\\Controllers\\ForwardLineApiController;",
    "'/api/v1/user/services/{id}/forward-options'",
    "'/api/v1/user/services/{id}/forwards'",
    "'/api/v1/user/forwards/{id}'",
    "'GET', '/api/v1/user/forwards/{id}'",
    "'PATCH', '/api/v1/user/forwards/{id}'",
    "'DELETE', '/api/v1/user/forwards/{id}'",
] as $required) {
    if (!str_contains($source, $required)) {
        fail('missing v1 forward API route snippet: ' . $required);
    }
}

echo "ok\n";
