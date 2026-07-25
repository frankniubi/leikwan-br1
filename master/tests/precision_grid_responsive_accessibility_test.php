<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function requireSource(string $source, string $needle, string $message): void
{
    if (!str_contains($source, $needle)) {
        fail($message . ': ' . $needle);
    }
}

$css = (file_get_contents(__DIR__ . '/../public/assets/leikwan-ui.css') ?: '')
    . (file_get_contents(__DIR__ . '/../public/assets/operator-console.css') ?: '');
$base = file_get_contents(__DIR__ . '/../src/Controllers/BaseController.php') ?: '';
$install = file_get_contents(__DIR__ . '/../public/install.php') ?: '';
$bootstrap = file_get_contents(__DIR__ . '/../public/index.php') ?: '';

foreach ([$base, $install, $bootstrap] as $source) {
    requireSource(
        $source,
        'name="viewport" content="width=device-width, initial-scale=1"',
        'responsive viewport metadata missing'
    );
}

foreach ([
    ':focus-visible',
    '.table-scroll{max-width:100%;overflow:auto',
    '@media(max-width:900px)',
    '.sidebar[data-open="true"]{display:block}',
    '.content-card{min-width:0',
    'overflow-wrap:anywhere',
] as $needle) {
    requireSource($css, $needle, 'responsive or keyboard guard missing');
}

foreach ([
    'class="mobile-nav-toggle"',
    'aria-controls="workspace-navigation"',
    'aria-expanded="false"',
    "setAttribute('aria-expanded', open ? 'true' : 'false')",
    'id="workspace-navigation"',
] as $needle) {
    requireSource($base, $needle, 'mobile navigation accessibility contract missing');
}

if (preg_match('/letter-spacing\s*:\s*-/i', $css) === 1) {
    fail('negative letter spacing is forbidden');
}

echo "ok\n";
