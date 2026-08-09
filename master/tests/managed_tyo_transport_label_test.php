<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$root = __DIR__ . '/..';

$admin = file_get_contents($root . '/src/Controllers/AdminController.php') ?: '';
foreach ([
    '>Managed TYO transport</option>',
    '<legend>Managed TYO line pool</legend>',
    '<label>Managed TYO carrier</label>',
    'Managed TYO transport = direct ingress (like current_mode, no Cloudflare) + an accelerated self-leg.',
] as $snippet) {
    if (!str_contains($admin, $snippet)) {
        fail('AdminController missing neutral managed TYO label snippet: ' . $snippet);
    }
}

foreach ([
    '<label>LeiGod line id</label>',
    '<label>LeiGod UoT carrier</label>',
    '>leigod_mode</option>',
    'LeiGod self-leg',
    '&harr; leigod',
] as $snippet) {
    if (str_contains($admin, $snippet)) {
        fail('AdminController still leaks provider wording in visible UI: ' . $snippet);
    }
}

foreach ([
    'zh-Hans' => $root . '/public/i18n/zh-Hans.json',
    'zh-TW' => $root . '/public/i18n/zh-TW.json',
] as $locale => $path) {
    $content = file_get_contents($path) ?: '';
    if ($content === '') {
        fail('Failed to read locale file: ' . $locale);
    }
    if (!str_contains($content, '"leigod_mode": "Managed TYO transport"')) {
        fail('Locale missing neutral leigod_mode label: ' . $locale);
    }
    if (str_contains($content, '"leigod_mode": "leigod_mode"')) {
        fail('Locale still exposes provider wording for leigod_mode: ' . $locale);
    }
}

echo "OK\n";
exit(0);
