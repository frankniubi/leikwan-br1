<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Support/PrecisionGridUi.php';

use Leikwan\Master\Support\PrecisionGridUi;

// Every state must carry shape + colour + text. Colour alone fails for
// colourblind users, on low-contrast displays, and in grayscale print.
foreach (['ok' => 'Healthy', 'warn' => 'Degraded', 'down' => 'Down'] as $state => $label) {
    $html = PrecisionGridUi::status($label, $state);

    if (!str_contains($html, 'data-state="' . $state . '"')) {
        throw new RuntimeException('status must expose its state: ' . $state);
    }
    if (!str_contains($html, '>' . $label . '<')) {
        throw new RuntimeException('status must carry a text label: ' . $state);
    }
    if (!preg_match('/data-shape="(circle|triangle|square)"/', $html)) {
        throw new RuntimeException('status must carry a shape encoding: ' . $state);
    }
    if (!str_contains($html, 'aria-hidden="true"')) {
        throw new RuntimeException('the decorative glyph must be hidden from screen readers: ' . $state);
    }
}

// Distinct states must get distinct shapes, or the shape encoding is decorative.
$shapes = [];
foreach (['ok', 'warn', 'down'] as $state) {
    preg_match('/data-shape="([a-z]+)"/', PrecisionGridUi::status('X', $state), $m);
    $shapes[] = $m[1] ?? '';
}
if (count(array_unique($shapes)) !== 3) {
    throw new RuntimeException('each state needs its own shape, got: ' . implode(',', $shapes));
}

// An unknown state must not silently render as healthy.
$unknown = PrecisionGridUi::status('Mystery', 'banana');
if (str_contains($unknown, 'data-shape="circle"')) {
    throw new RuntimeException('unknown states must not render as healthy');
}

// The vocabulary the production call sites actually pass must all be encoded.
// AdminController/TenantController emit only 'ok', 'warning' and 'error'; if any
// of them fell through to 'unknown' a live console would lose its encoding.
foreach (['ok' => 'circle', 'warning' => 'triangle', 'error' => 'square'] as $state => $expected) {
    if (!str_contains(PrecisionGridUi::status('Live', $state), 'data-shape="' . $expected . '"')) {
        throw new RuntimeException('production state lost its shape: ' . $state);
    }
}

// data-state is attacker-reachable in principle, so it must be escaped.
$injected = PrecisionGridUi::status('Label', 'ok" onmouseover="alert(1)');
if (str_contains($injected, 'onmouseover="alert(1)"')) {
    throw new RuntimeException('state must be escaped into the attribute');
}

echo "ok\n";
