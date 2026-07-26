<?php
declare(strict_types=1);

$root = dirname(__DIR__);

function readable_fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function readable_contains(string $haystack, string $needle, string $context): void
{
    if (!str_contains($haystack, $needle)) {
        readable_fail('missing ' . $context . ': ' . $needle);
    }
}

function readable_not_contains(string $haystack, string $needle, string $context): void
{
    if (str_contains($haystack, $needle)) {
        readable_fail('forbidden ' . $context . ': ' . $needle);
    }
}

function readable_walk_dictionary(mixed $value, string $path, string $file): void
{
    if (is_string($value)) {
        if (str_contains($value, "\u{200B}")) {
            readable_fail('zero-width space in ' . $file . ' at ' . $path);
        }
        return;
    }

    if (!is_array($value)) {
        return;
    }

    foreach ($value as $key => $child) {
        $keyString = (string) $key;
        if (preg_match('/[\x{FFFD}\x{951F}\x{9227}]/u', $keyString) === 1) {
            readable_fail('mojibake key in ' . $file . ': ' . $keyString);
        }
        readable_walk_dictionary($child, $path . '.' . $keyString, $file);
    }
}

$css = (string) file_get_contents($root . '/public/assets/leikwan-v2.css');
$patch = (string) file_get_contents($root . '/public/i18n/patch.js');
$base = (string) file_get_contents($root . '/src/Controllers/BaseController.php');
$grid = (string) file_get_contents($root . '/src/Support/PrecisionGridUi.php');
$index = (string) file_get_contents($root . '/public/index.php');
$install = (string) file_get_contents($root . '/public/install.php');
require_once $root . '/src/Support/PrecisionGridUi.php';

readable_contains($css, '--body-size: 14px', 'body typography token');
readable_contains($css, '--row-h: 54px', 'primary row token');
readable_not_contains($css, 'font-size: 7.5px', 'compressed micro typography');
readable_not_contains($css, 'letter-spacing: -', 'negative tracking');
readable_contains($base, "'offline' => 'error'", 'offline status severity');
readable_not_contains($base, 'if (false && $allowBond)', 'dead client-mode branch');
readable_not_contains($base, 'return match ($this->canonicalClientMode($mode))', 'unreachable client-mode return');
readable_contains($grid, 'pg-status-description', 'status explanation markup');
$statusHtml = \Leikwan\Master\Support\PrecisionGridUi::status('Pending', 'warning', 'A change is in progress.');
readable_contains($statusHtml, 'aria-label="Pending: A change is in progress."', 'status accessible explanation');
readable_contains($statusHtml, 'class="pg-status-description"', 'status visible explanation');
readable_contains($index, '/assets/leikwan-v2.css', 'bootstrap error skin');
readable_contains($install, '/assets/leikwan-v2.css', 'installer skin');
foreach (['leikwan-ui.css', 'operator-console.css', 'signal-chamber.css', 'signal-chamber.js'] as $legacyAsset) {
    readable_not_contains($index, $legacyAsset, 'bootstrap legacy asset');
    readable_not_contains($install, $legacyAsset, 'installer legacy asset');
}
readable_contains($patch, 'document.documentElement.lang', 'document locale synchronization');
readable_contains($patch, 'data-i18n-static', 'form value protection');
readable_contains($patch, 'dictionary.patterns', 'dynamic locale patterns');

foreach (['zh-Hans.json', 'zh-TW.json'] as $localeFile) {
    $path = $root . '/public/i18n/' . $localeFile;
    $dictionary = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    readable_walk_dictionary($dictionary, '$', $localeFile);
    if (!isset($dictionary['patterns']) || !is_array($dictionary['patterns'])) {
        readable_fail('missing patterns section in ' . $localeFile);
    }
}

fwrite(STDOUT, "readable web panel repair contract: PASS\n");
