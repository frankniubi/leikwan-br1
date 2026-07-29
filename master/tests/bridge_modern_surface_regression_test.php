<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$root = dirname(__DIR__);
$base = (string) file_get_contents($root . '/src/Controllers/BaseController.php');
$install = (string) file_get_contents($root . '/public/install.php');
$index = (string) file_get_contents($root . '/public/index.php');
$css = (string) file_get_contents($root . '/public/assets/bridge-modern.css');
$link = '/assets/bridge-modern.css?v=20260728a';

foreach ([$base, $install, $index] as $source) {
    if (!str_contains($source, $link)) {
        fail('modern shared skin is not loaded by every shell');
    }
}

foreach ([
    'body.pg-public:not(.bridge-home-page)',
    'body.pg-app',
    '.site-mark',
    '.brand-mark',
    '.auth-grid',
    '.kb-shell',
    '.pg-sheet',
    '@media (max-width: 900px)',
    'flex-direction: column !important',
    '--bridge-modern-page: #ffffff',
    'border-radius: 999px',
] as $required) {
    if (!str_contains($css, $required)) {
        fail('modern shared skin selector/token missing: ' . $required);
    }
}

foreach (['linear-gradient(', 'radial-gradient(', 'clamp(', 'border: 1px solid var(--bridge-modern-ink)'] as $forbidden) {
    if (str_contains($css, $forbidden)) {
        fail('modern shared skin uses forbidden primitive: ' . $forbidden);
    }
}

echo "ok\n";
