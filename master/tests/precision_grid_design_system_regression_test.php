<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$base = (string) file_get_contents($root . '/src/Controllers/BaseController.php');

// v2 is the only skin — leikwan-v2.css must exist.
if (!is_file($root . '/public/assets/leikwan-v2.css')) {
    throw new RuntimeException('leikwan-v2.css is missing');
}

// Legacy files must be gone.
foreach (['leikwan-ui.css', 'operator-console.css', 'signal-chamber.css', 'signal-chamber.js'] as $legacy) {
    if (is_file($root . '/public/assets/' . $legacy)) {
        throw new RuntimeException('legacy asset was not deleted: ' . $legacy);
    }
}

// Token contract: v2 design tokens must be present.
$css     = (string) file_get_contents($root . '/public/assets/leikwan-v2.css');
$compact = preg_replace('/\s+/', '', $css) ?: '';
foreach (['--ink-0:', '--paper-0:', '--state-ok:', '--state-warn:', '--state-down:', '--radius:', '--row-h:'] as $token) {
    if (!str_contains($compact, $token)) {
        throw new RuntimeException('v2 token missing from leikwan-v2.css: ' . $token);
    }
}

// Responsive / accessibility rules must survive the migration.
foreach ([':focus-visible', '.mobile-nav-toggle', '@media(max-width:900px)'] as $required) {
    if (!str_contains($compact, str_replace(' ', '', $required))) {
        throw new RuntimeException('missing responsive/accessibility rule: ' . $required);
    }
}

// BaseController must reference v2 CSS, not the legacy stylesheets.
if (!str_contains($base, '/assets/leikwan-v2.css?v=')) {
    throw new RuntimeException('BaseController shell does not load leikwan-v2.css');
}
foreach (['/assets/leikwan-ui.css', '/assets/operator-console.css', '/assets/signal-chamber.css', '/assets/signal-chamber.js'] as $legacy) {
    if (str_contains($base, $legacy)) {
        throw new RuntimeException('BaseController shell still references legacy asset: ' . $legacy);
    }
}
if (str_contains($base, 'precision-grid.css') || is_file($root . '/public/assets/precision-grid.css')) {
    throw new RuntimeException('retired precision-grid stylesheet remains');
}

echo "ok\n";
