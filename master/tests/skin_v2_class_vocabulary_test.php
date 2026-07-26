<?php
declare(strict_types=1);

/**
 * Every CSS class the PHP emits must have a rule in the v2 stylesheet.
 *
 * This is a floor, not a proof. Class attributes are built three ways:
 *
 *   1. fully static       class="pg-sheet"
 *   2. static + runtime   class="badge {$tone}"   class="nav-link' . $x . '"
 *   3. entirely runtime   class="' . $this->escape($class) . '"
 *
 * Only (1) and the static prefix of (2) can be read off the source. Everything
 * past the first PHP boundary is an expression, not class names -- an earlier
 * version of this file tried to harvest tokens out of that residue and produced
 * requirements like `===` and `implode(` that no stylesheet could ever satisfy.
 * So the extractor stops at the boundary and makes the fixture carry the slack.
 *
 *   allow-dynamic <prefix>   this runtime-composed attribute has been read by a
 *                            human who confirmed what it can emit
 *   require-class <name>     a class only ever emitted at runtime; invisible to
 *                            the extractor, but it still has to be styled
 */

$root = dirname(__DIR__);
$fixture = $root . '/tests/fixtures/skin-v2-coverage.txt';
$cssPath = $root . '/public/assets/leikwan-v2.css';

if (!is_file($cssPath)) {
    throw new RuntimeException('leikwan-v2.css does not exist yet');
}

// A class name mentioned in a comment is not a rule.
$css = preg_replace('!/\*.*?\*/!s', '', (string) file_get_contents($cssPath)) ?? '';

$sources = [
    '/src/Controllers/BaseController.php',
    '/src/Controllers/AdminController.php',
    '/src/Controllers/TenantController.php',
    '/src/Controllers/AuthController.php',
    '/src/Controllers/HealthController.php',
    '/src/Support/PrecisionGridUi.php',
    '/src/Support/UptimeTrace.php',
    '/public/index.php',
    '/public/install.php',
];

$allowDynamic = [];
$required = [];
foreach (file($fixture, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) {
        continue;
    }
    if (str_starts_with($line, 'allow-dynamic ')) {
        $entry = trim(substr($line, strlen('allow-dynamic ')));
        if (strlen($entry) < 3) {
            throw new RuntimeException(
                "allow-dynamic '" . $entry . "' is too short to identify anything: it "
                . 'would attribute unrelated attributes and silently disable this check'
            );
        }
        $allowDynamic[] = $entry;
        continue;
    }
    if (str_starts_with($line, 'require-class ')) {
        $required[] = trim(substr($line, strlen('require-class ')));
    }
}

$classes = [];
$dynamic = [];
$strange = [];
foreach ($sources as $rel) {
    $src = (string) file_get_contents($root . $rel);
    preg_match_all('/class="([^"]*)"/', $src, $m);
    foreach ($m[1] as $attr) {
        $cut = strcspn($attr, '$\'{');
        if ($cut < strlen($attr)) {
            $dynamic[] = trim($attr);
        }
        foreach (preg_split('/\s+/', trim(substr($attr, 0, $cut))) ?: [] as $cls) {
            if ($cls === '') {
                continue;
            }
            if (!preg_match('/^-?[A-Za-z_][A-Za-z0-9_-]*$/', $cls)) {
                $strange[$cls] = true;
                continue;
            }
            $classes[$cls] = true;
        }
    }
}

// The extractor read something in a static position that is not a class name,
// which means it is misparsing an attribute rather than finding a real class.
if ($strange !== []) {
    ksort($strange);
    throw new RuntimeException(
        "not class names, so the extractor is misreading an attribute:\n  "
        . implode("\n  ", array_keys($strange))
    );
}

$unattributed = [];
foreach (array_unique($dynamic) as $frag) {
    $ok = false;
    foreach ($allowDynamic as $allowed) {
        if (str_starts_with($frag, $allowed)) {
            $ok = true;
            break;
        }
    }
    if (!$ok) {
        $unattributed[] = $frag;
    }
}
if ($unattributed !== []) {
    throw new RuntimeException(
        "runtime-composed class attributes not declared in the fixture:\n  "
        . implode("\n  ", array_slice($unattributed, 0, 20))
        . "\nRead each one, then add 'allow-dynamic <static prefix>' to "
        . "tests/fixtures/skin-v2-coverage.txt, plus a 'require-class <name>' line "
        . 'for every class it can emit that appears nowhere statically.'
    );
}

foreach ($required as $cls) {
    $classes[$cls] = true;
}

$missing = [];
foreach (array_keys($classes) as $cls) {
    // (?![\w-]) rather than \b: "-" is a non-word character, so /\.card\b/ is
    // satisfied by a rule for .card-kicker while .card itself goes unstyled.
    if (!preg_match('/\.' . preg_quote($cls, '/') . '(?![\w-])/', $css)) {
        $missing[] = $cls;
    }
}
if ($missing !== []) {
    sort($missing);
    throw new RuntimeException(
        'leikwan-v2.css has no rule for ' . count($missing) . " emitted classes:\n  "
        . implode("\n  ", $missing)
    );
}

echo 'ok (' . count($classes) . " classes covered)\n";
