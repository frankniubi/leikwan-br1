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

use Leikwan\Master\Support\ApiException;
use Leikwan\Master\Support\ApiRateLimiter;

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$dir = sys_get_temp_dir() . '/lk-rate-test-' . bin2hex(random_bytes(4));
$limiter = new ApiRateLimiter($dir);
$t = 1_000_000_040; // 20 s into a 60 s window (1_000_000_020 is a window start)

for ($i = 1; $i <= 3; $i++) {
    $limiter->hit('user:1', 3, $t);
}
try {
    $limiter->hit('user:1', 3, $t);
    fail('the request past the limit must be refused');
} catch (ApiException $exception) {
    if ($exception->getMessage() !== 'rate_limited' || $exception->statusCode() !== 429) {
        fail('refusal must be 429 rate_limited, got ' . $exception->getMessage());
    }
    if (($exception->details()['retry_after'] ?? 0) !== 40) {
        fail('retry_after must count to the end of the window, got ' . json_encode($exception->details()));
    }
}

$limiter->hit('user:2', 3, $t); // other keys are independent
$limiter->hit('user:1', 3, $t + 60); // a new window starts over
$limiter->hit('user:1', 0, $t + 60); // 0 disables the key

// Fails open when the counter directory cannot be created.
$blocked = tempnam(sys_get_temp_dir(), 'lk-rate-file-');
$broken = new ApiRateLimiter($blocked . '/sub');
for ($i = 0; $i < 5; $i++) {
    $broken->hit('user:1', 1, $t);
}

array_map('unlink', glob($dir . '/*') ?: []);
@rmdir($dir);
@unlink($blocked);
echo "ok\n";
