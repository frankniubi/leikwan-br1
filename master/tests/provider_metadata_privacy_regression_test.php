<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Controllers/ServiceController.php');
if (!is_string($source) || $source === '') {
    fail('failed to read ServiceController.php');
}

$start = strpos($source, 'public function showService(');
$end = strpos($source, 'public function createForward(', $start === false ? 0 : $start);
if ($start === false || $end === false || $end <= $start) {
    fail('failed to isolate showService method');
}
$method = substr($source, $start, $end - $start);

if (!str_contains($method, 'ForwardLineApiContract::presentService')) {
    fail('showService must use explicit safe service presenter');
}
foreach (['serviceSummary($serviceId)', 'client_token', 'forward_combination', 'sidecar', 'node', 'config'] as $forbidden) {
    if (str_contains($method, $forbidden)) {
        fail('showService exposes broad provider/topology metadata: ' . $forbidden);
    }
}

echo "ok\n";
