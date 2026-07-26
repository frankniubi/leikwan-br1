<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Support/PrecisionGridUi.php';

use Leikwan\Master\Support\PrecisionGridUi;

/**
 * The three-way status encoding, tested by binding each token to the element
 * that consumes it.
 *
 * An earlier version of this file matched `data-shape` and `aria-hidden`
 * anywhere in the output. Three behaviour-changing mutations survived it:
 * removing the shape from the dot (geometry dies, colour survives, so it still
 * looks right in a screenshot), returning a known shape as the default (an
 * unrecognised state becomes indistinguishable from a genuine failure), and
 * moving `aria-hidden` onto the wrapper (every status in the console vanishes
 * from the accessibility tree). All three now fail.
 *
 * "The token appears somewhere in the output" is the same shape of assertion as
 * the ones this project exists to replace.
 */

// The vocabulary the production call sites actually pass. AdminController and
// TenantController emit only these three, so the test states the system rather
// than a generic ok/warn/down aspiration.
$production = ['ok' => 'circle', 'warning' => 'triangle', 'error' => 'square'];

foreach ($production as $state => $shape) {
    $label = 'Node ' . $state;
    $html = PrecisionGridUi::status($label, $state);

    if (!str_contains($html, 'data-state="' . $state . '"')) {
        throw new RuntimeException('status must expose its state: ' . $state);
    }
    if (!str_contains($html, '>' . $label . '<')) {
        throw new RuntimeException('status must carry a text label: ' . $state);
    }

    // The wrapper's shape drives the colour, which the label inherits through
    // currentColor. The dot's drives the geometry. Both are load-bearing, so
    // both are asserted against their own element.
    if (!preg_match('/<span class="pg-status"[^>]*data-shape="' . $shape . '"/', $html)) {
        throw new RuntimeException('the wrapper carries the colour and needs the shape: ' . $state);
    }
    if (!preg_match('/<i class="pg-status-dot"[^>]*data-shape="' . $shape . '"/', $html)) {
        throw new RuntimeException('the dot draws the geometry and needs the shape: ' . $state);
    }

    // The glyph is decorative; the label is the accessible name. Hiding the
    // wrapper instead would remove the whole status from the a11y tree, which
    // is why this is asserted in both directions.
    if (!preg_match('/<i [^>]*aria-hidden="true"/', $html)) {
        throw new RuntimeException('the decorative glyph must be hidden from screen readers: ' . $state);
    }
    if (preg_match('/<span[^>]*aria-hidden/', $html)) {
        throw new RuntimeException('the labelled wrapper must stay in the a11y tree: ' . $state);
    }
}

// Distinct states must get distinct shapes, or the shape encoding is decorative.
// Read back from the rendered output rather than from the table above, so this
// asserts the implementation and not the fixture.
$shapes = [];
foreach (array_keys($production) as $state) {
    preg_match('/pg-status-dot[^>]*data-shape="([a-z]+)"/', PrecisionGridUi::status('X', $state), $m);
    $shapes[] = $m[1] ?? '';
}
if (count(array_unique($shapes)) !== 3) {
    throw new RuntimeException('each state needs its own shape, got: ' . implode(',', $shapes));
}

// An unrecognised state must be visibly unrecognised. Not healthy -- and not
// borrowing a known shape either, which would make it read as a real failure
// and hide the fact that a call site is emitting something nobody handled.
$unknown = PrecisionGridUi::status('Mystery', 'banana');
if (preg_match('/data-shape="(circle|triangle|square)"/', $unknown)) {
    throw new RuntimeException('an unrecognised state must not borrow a known shape: ' . $unknown);
}
if (!str_contains($unknown, 'data-shape="unknown"')) {
    throw new RuntimeException('an unrecognised state must still be encoded: ' . $unknown);
}

// data-state is attacker-reachable in principle, so it must be escaped.
$injected = PrecisionGridUi::status('Label', 'ok" onmouseover="alert(1)');
if (str_contains($injected, 'onmouseover="alert(1)"')) {
    throw new RuntimeException('state must be escaped into the attribute');
}
if (str_contains(PrecisionGridUi::status('<script>alert(1)</script>', 'ok'), '<script>')) {
    throw new RuntimeException('label must be escaped into the text node');
}

echo "ok\n";
