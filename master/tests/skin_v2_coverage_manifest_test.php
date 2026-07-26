<?php
declare(strict_types=1);

/**
 * Route coverage manifest for the v2 skin.
 *
 * Every entry in the fixture must carry one of three verdicts:
 *
 *   verified    the v2 shell ships this route; it has been viewed and passes
 *   pending     still needs a v2 body or visual review before cutover
 *   not-a-page  API endpoint, artifact download, form-POST target, or redirect
 *               -- routes that never render an HTML page in their own right
 *
 * The test fails when ANY route is still 'pending'. Fix a page, flip its
 * verdict to 'verified', and re-run. Once all entries are 'verified' or
 * 'not-a-page' this test passes and the LEIKWAN_SKIN=v2 default is unblocked.
 *
 * Paths use the same pattern syntax as Bootstrap::registerRoutes(), e.g.
 * /tenant/services/{id}. Comments start with ' # ' (space-hash-space).
 */

$root    = dirname(__DIR__);
$fixture = $root . '/tests/fixtures/skin-v2-coverage.txt';

$routes = [];
foreach (file($fixture, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $raw) {
    $line = trim($raw);
    if ($line === '' || str_starts_with($line, '#')) {
        continue;
    }
    // allow-dynamic and require-class belong to the vocabulary test; skip them.
    if (str_starts_with($line, 'allow-dynamic ') || str_starts_with($line, 'require-class ')) {
        continue;
    }
    if (!str_starts_with($line, 'route ')) {
        throw new RuntimeException("unknown fixture directive: '$line'\nValid: allow-dynamic, require-class, route, # (comment)");
    }

    // Strip optional trailing comment: "route verified /path # why"
    $body  = trim(substr($line, strlen('route ')));
    $space = strpos($body, ' # ');
    if ($space !== false) {
        $body = rtrim(substr($body, 0, $space));
    }

    $parts = preg_split('/\s+/', $body, 3);
    if (count($parts) < 2) {
        throw new RuntimeException("malformed route directive (need: route <verdict> <path>): '$line'");
    }
    [$verdict, $path] = $parts;

    if (!in_array($verdict, ['verified', 'pending', 'not-a-page'], true)) {
        throw new RuntimeException("unknown verdict '$verdict' in: '$line'\nAllowed: verified | pending | not-a-page");
    }
    if (array_key_exists($path, $routes)) {
        throw new RuntimeException("duplicate route entry for '$path'");
    }
    $routes[$path] = $verdict;
}

if ($routes === []) {
    throw new RuntimeException('no route directives found in ' . $fixture . "\nAdd 'route <verdict> <path>' lines.");
}

$pending = array_filter($routes, static fn(string $v): bool => $v === 'pending');
if ($pending !== []) {
    ksort($pending);
    $n = count($pending);
    throw new RuntimeException(
        $n . ' route' . ($n === 1 ? '' : 's') . ' still pending v2 coverage'
        . ' -- resolve before flipping LEIKWAN_SKIN=v2:' . "\n  "
        . implode("\n  ", array_keys($pending))
    );
}

$nVerified   = count(array_filter($routes, static fn(string $v): bool => $v === 'verified'));
$nNotAPage   = count(array_filter($routes, static fn(string $v): bool => $v === 'not-a-page'));
echo 'ok (' . $nVerified . ' verified, ' . $nNotAPage . ' not-a-page)' . PHP_EOL;
