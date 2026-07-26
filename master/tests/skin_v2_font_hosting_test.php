<?php
declare(strict_types=1);

/**
 * Latin fonts must be self-hosted.
 *
 * Google Fonts is not reliably reachable from mainland China, where end users
 * are. The three woff2 files must exist on disk, be referenced by the
 * stylesheet, and no remote CDN URL may appear. CJK uses a system stack and
 * needs no download.
 */

$root = dirname(__DIR__);
$css = (string) file_get_contents($root . '/public/assets/leikwan-v2.css');

foreach ([
    'fonts/archivo-variable.woff2',
    'fonts/spline-sans-mono-400.woff2',
    'fonts/spline-sans-mono-600.woff2',
] as $rel) {
    if (!is_file($root . '/public/assets/' . $rel)) {
        throw new RuntimeException('font is not self-hosted: ' . $rel);
    }
    $size = filesize($root . '/public/assets/' . $rel);
    if ($size < 10_000) {
        throw new RuntimeException('font file looks truncated (' . $size . ' bytes): ' . $rel);
    }
    if (!str_contains($css, $rel)) {
        throw new RuntimeException('stylesheet does not reference ' . $rel);
    }
}

// No remote font CDN — unreachable behind the GFW.
foreach (['fonts.googleapis.com', 'fonts.gstatic.com', '@import url(http'] as $forbidden) {
    if (str_contains($css, $forbidden)) {
        throw new RuntimeException('remote font dependency is forbidden: ' . $forbidden);
    }
}

// Latin @font-face blocks must declare unicode-range so the browser falls
// through to the system CJK stack for Chinese characters rather than
// attempting to render them in Archivo or Spline Sans Mono.
if (!str_contains($css, 'unicode-range')) {
    throw new RuntimeException('@font-face must declare unicode-range to avoid blocking CJK fallback');
}

// Both Archivo and Spline Sans Mono must be declared.
foreach (['Archivo', 'Spline Sans Mono'] as $family) {
    if (!str_contains($css, "'" . $family . "'") && !str_contains($css, '"' . $family . '"')) {
        throw new RuntimeException('@font-face missing for family: ' . $family);
    }
}

echo "ok\n";
