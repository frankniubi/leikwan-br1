<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$auth = file_get_contents(__DIR__ . '/../src/Controllers/AuthController.php');
$base = file_get_contents(__DIR__ . '/../src/Controllers/BaseController.php');

if (!is_string($auth) || $auth === '') {
    fail('failed to read AuthController source');
}
if (!is_string($base) || $base === '') {
    fail('failed to read BaseController source');
}

foreach ([
    'protected function displayThrowableMessage(\Throwable $throwable): string',
    'if ($throwable instanceof ApiException)',
    'return $throwable->getMessage();',
] as $required) {
    if (!str_contains($base, $required)) {
        fail('missing shared throwable-message helper snippet: ' . $required);
    }
}

if (!str_contains($auth, 'SessionAuth::flash(\'error\', \'Registration failed: \' . $this->displayThrowableMessage($throwable));')) {
    fail('registration error path is not using displayThrowableMessage');
}

if (str_contains($auth, 'SessionAuth::flash(\'error\', \'Registration failed: \' . $throwable->getMessage());')) {
    fail('registration error path still leaks the raw exception code');
}

echo "ok\n";
