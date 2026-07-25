<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$root = dirname(__DIR__);
$auth = (string) file_get_contents($root . '/src/Controllers/AuthController.php');
$css = (string) file_get_contents($root . '/public/assets/signal-chamber.css');
$zhTw = json_decode((string) file_get_contents($root . '/public/i18n/zh-TW.json'), true);
$zhHans = json_decode((string) file_get_contents($root . '/public/i18n/zh-Hans.json'), true);

if (!is_array($zhTw) || !is_array($zhHans)) {
    fail('failed to load locale dictionaries');
}

foreach ([
    '&#21033;&#32676;&#26371;&#32858; LeiKwan Bridge',
    'Multi-Slave Transport',
    'Speed has more than one way through.',
    'One service, distributed transport.',
    'Every slave runs the same service.',
    'Client &rarr; Slave &rarr; Sidecar &rarr; Target',
    'Active Slaves',
    'Sidecar Endpoints',
] as $snippet) {
    if (!str_contains($auth, $snippet)) {
        fail('missing homepage positioning snippet: ' . $snippet);
    }
}

foreach ([
    'client strategy',
    'client latency',
    'client RTT',
    'Speed max',
    'Latency Best',
    'Random',
] as $privateTerm) {
    if (stripos($auth, $privateTerm) !== false) {
        fail('homepage exposes client-local selection detail: ' . $privateTerm);
    }
}

foreach (['.signal-hero', '.signal-canvas', '.signal-summary', '.signal-light-detail', '.signal-architecture'] as $selector) {
    if (!str_contains($css, $selector)) {
        fail('missing homepage style selector: ' . $selector);
    }
}

foreach ([
    'Multi-Slave Transport',
    'Speed has more than one way through.',
    'Open Console',
    'View Network',
    'Active Slaves',
    'Regions',
    'Sidecar Endpoints',
    'One service, distributed transport.',
] as $localeKey) {
    foreach (['zh-TW' => $zhTw, 'zh-Hans' => $zhHans] as $locale => $dictionary) {
        $value = (string) (($dictionary['text'] ?? [])[$localeKey] ?? '');
        if ($value === '') {
            fail('missing homepage locale key for ' . $locale . ': ' . $localeKey);
        }
        if (str_contains($value, "\u{FFFD}")) {
            fail('corrupted homepage locale value for ' . $locale . ': ' . $localeKey);
        }
    }
}

echo "ok\n";
