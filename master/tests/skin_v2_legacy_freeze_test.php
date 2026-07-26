<?php
declare(strict_types=1);

/**
 * The legacy skin is frozen.
 *
 * Three design systems accumulated because each redesign was added as a new
 * layer with higher specificity rather than replacing the last one. Freezing the
 * old files means new work cannot be added to them, so v2 is the only place a
 * change can land -- and the two skins cannot drift apart during the migration.
 *
 * signal-chamber.js is frozen for the same reason as the stylesheets, not as an
 * afterthought: it is loaded by every public page of the legacy skin, so leaving
 * it out would leave one door open for new legacy behaviour to land as script
 * instead of style -- the freeze would read as complete while not being it.
 *
 * Freezing a file by sha256 only works if its bytes are the same everywhere, so
 * this file also checks each frozen path is pinned to `text eol=lf` in
 * .gitattributes. Without the pin a checkout with core.autocrlf=true hashes CRLF
 * and fails the freeze with a mismatch that is not evidence of an edit. The
 * check exists because adding a file here and forgetting the pin is one action,
 * not two, and the resulting red test accuses the wrong person.
 *
 * These files are deleted entirely in the teardown commit (Task 16), which also
 * deletes this test and the .gitattributes block that pins them.
 */

$root = dirname(__DIR__);
$repoRoot = dirname($root);

$frozen = [
    'leikwan-ui.css'       => 'e4e96ec31e2f6ea73f6db9285b44934bebbb43bcf01c6c65e45cde660e2df844',
    'operator-console.css' => 'ddf094cc5b27fc4116a1063cdfc9992dbd9496948cf41fa293b37b8165569fb3',
    'signal-chamber.css'   => 'f18f1b84725a388ef9689e1415d2009bf8d59c753dcd0bdb72e85e47b3a86d0f',
    'signal-chamber.js'    => 'd1c9c01266119866d6c785fd5550c5187266efc0f53d14b8d1026ec83731f0f6',
];

// --- The LF pins ---------------------------------------------------------

$attributesPath = $repoRoot . '/.gitattributes';
if (!is_file($attributesPath)) {
    throw new RuntimeException('.gitattributes is gone, so nothing pins the frozen files to LF and every'
        . ' hash below is at the mercy of the checking-out machine');
}

/** @var array<int, string> $lfPatterns */
$lfPatterns = [];
foreach (file($attributesPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $trimmed = trim($line);
    if ($trimmed === '' || str_starts_with($trimmed, '#')) {
        continue;
    }
    $fields = preg_split('/\s+/', $trimmed) ?: [];
    $pattern = array_shift($fields);
    if ($pattern === null || !in_array('eol=lf', $fields, true)) {
        continue;
    }
    $lfPatterns[] = $pattern;
}

/**
 * Does a .gitattributes pattern cover this repo-relative path?
 *
 * Follows the two rules that matter here: a pattern containing a slash is
 * anchored at the repo root, one without it matches the basename at any depth,
 * and `*` does not cross a directory separator. Written out rather than handed
 * to fnmatch() because fnmatch()'s availability is platform-dependent and a
 * silently-skipped pin check is worse than no pin check.
 */
function freezePatternCovers(string $pattern, string $relPath): bool
{
    $pattern = ltrim($pattern, '/');
    $subject = str_contains($pattern, '/') ? $relPath : basename($relPath);
    $regex = '#^' . str_replace(
        ['\*\*', '\*', '\?'],
        ['.*', '[^/]*', '[^/]'],
        preg_quote($pattern, '#')
    ) . '$#';

    return preg_match($regex, $subject) === 1;
}

// --- The freeze ----------------------------------------------------------

foreach ($frozen as $name => $expected) {
    $relPath = 'master/public/assets/' . $name;
    $path = $root . '/public/assets/' . $name;

    if (!is_file($path)) {
        throw new RuntimeException('frozen legacy asset is missing: ' . $name
            . ' -- if this is the teardown commit, delete this test too');
    }

    $pinned = false;
    foreach ($lfPatterns as $pattern) {
        if (freezePatternCovers($pattern, $relPath)) {
            $pinned = true;
            break;
        }
    }
    if (!$pinned) {
        throw new RuntimeException(
            $name . ' is frozen by sha256 but nothing in .gitattributes pins it to LF.' . PHP_EOL
            . 'A checkout with core.autocrlf=true would hash CRLF bytes and fail the' . PHP_EOL
            . 'freeze below for a file nobody edited. Add a line covering ' . $relPath . PHP_EOL
            . 'to the frozen-asset block in .gitattributes, e.g.:' . PHP_EOL
            . '  /' . dirname($relPath) . '/*.' . pathinfo($name, PATHINFO_EXTENSION) . ' text eol=lf'
        );
    }

    $actual = hash_file('sha256', $path);
    if ($actual === $expected) {
        continue;
    }

    // The line-ending case is the one that wastes an afternoon: `git status` is
    // clean because Git normalises on read, so the file looks untouched while
    // the bytes on disk are not the bytes in the blob. Reading the endings for
    // the reader saves them working out which of the two situations they are in.
    $verdict = str_contains((string) file_get_contents($path), "\r\n")
        ? 'This copy has CRLF line endings, so it is most likely the checkout'
            . ' case, not an edit.'
        : 'This copy has LF line endings, so it is most likely a real edit'
            . ' rather than the checkout case.';

    throw new RuntimeException(
        $name . ' is frozen and must not be edited.' . PHP_EOL
        . '  expected ' . $expected . PHP_EOL
        . '  actual   ' . $actual . PHP_EOL
        . 'Put the change in leikwan-v2.css instead.' . PHP_EOL
        . PHP_EOL
        . $verdict . PHP_EOL
        . PHP_EOL
        . 'The checkout case: if you did not touch this file and `git status` is' . PHP_EOL
        . 'clean, your working tree predates the eol=lf pin in .gitattributes --' . PHP_EOL
        . 'the bytes on disk are CRLF while the committed blob is LF, and Git' . PHP_EOL
        . 'hides the difference because it normalises on read. Force a fresh copy' . PHP_EOL
        . 'of the blob:' . PHP_EOL
        . '  rm ' . $relPath . ' && git checkout -- ' . $relPath . PHP_EOL
        . 'Do NOT run `git add --renormalize`: that rewrites the committed blob' . PHP_EOL
        . 'and so changes the very hash this test pins -- it would turn the freeze' . PHP_EOL
        . 'green by editing the thing being frozen.'
    );
}

echo "ok\n";
