<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$base = (string) file_get_contents($root . '/src/Controllers/BaseController.php');
$asset = $root . '/public/assets/brand/leikwan-mark.png';

if (!is_file($asset)) {
    throw new RuntimeException('the repaired brand mark is missing');
}

// The original was 1.2 MB for a 40px render. Keep it small.
$bytes = (int) filesize($asset);
if ($bytes > 60000) {
    throw new RuntimeException('brand mark regressed to ' . $bytes . ' bytes; keep it under 60 KB');
}

// It must be a real PNG.
if (substr((string) file_get_contents($asset, false, null, 0, 8), 1, 3) !== 'PNG') {
    throw new RuntimeException('brand mark is not a PNG');
}

// Served from this host: a cross-host fetch is a needless dependency for users
// behind the GFW.
if (!str_contains($base, "'BRAND_LOGO_URL', '/assets/brand/leikwan-mark.png'")) {
    throw new RuntimeException('brandLogoUrl must default to the local asset');
}
if (str_contains($base, 'buy.leikwanhost.com/assets/img/logo.png')) {
    throw new RuntimeException('the remote logo default must be gone');
}

echo "ok\n";
