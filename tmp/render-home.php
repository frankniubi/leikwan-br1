<?php
declare(strict_types=1);
require __DIR__ . '/../master/src/Support/LocaleResolver.php';
require __DIR__ . '/../master/src/Support/HomePageContent.php';

$refs = [];
foreach (['en', 'zh-Hans', 'zh-TW'] as $locale) {
    $html = \Leikwan\Master\Support\HomePageContent::render('Leikwan', '/dashboard', $locale);
    preg_match('#viewBox="([^"]+)"#', $html, $vb);
    preg_match_all('#(?:href|src)="([^"]+)"#', $html, $m);
    foreach ($m[1] as $r) { $refs[$r] = true; }
    echo str_pad($locale, 8), ' bytes=', strlen($html),
        ' nodes=', preg_match_all('/<g class="rk-node">/', $html),
        ' cities=', substr_count($html, 'class="bridge-home-city"'),
        ' carriers=', substr_count($html, 'bridge-home-carrier bridge-home-carrier--'),
        ' viewBox=', $vb[1] ?? 'NONE', PHP_EOL;
}
echo "refs:", PHP_EOL;
foreach (array_keys($refs) as $r) { echo '  ', $r, PHP_EOL; }
echo 'dark-token-scan: ', (preg_match('/#0[0-9a-f]{5}|#1[0-9a-f]{5}(?!d)|rgba\(0,/', $html ?? '') ? 'FOUND' : 'clean'), PHP_EOL;
