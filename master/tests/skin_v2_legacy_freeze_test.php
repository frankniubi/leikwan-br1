<?php
declare(strict_types=1);

/**
 * The legacy stylesheets are frozen.
 *
 * Three design systems accumulated because each redesign was added as a new
 * layer with higher specificity rather than replacing the last one. Freezing the
 * old files means new work cannot be added to them, so v2 is the only place a
 * change can land -- and the two skins cannot drift apart during the migration.
 *
 * These files are deleted entirely in the teardown commit (Task 16), which also
 * deletes this test.
 */

$root = dirname(__DIR__);

$frozen = [
    'leikwan-ui.css'       => 'e4e96ec31e2f6ea73f6db9285b44934bebbb43bcf01c6c65e45cde660e2df844',
    'operator-console.css' => 'ddf094cc5b27fc4116a1063cdfc9992dbd9496948cf41fa293b37b8165569fb3',
    'signal-chamber.css'   => 'f18f1b84725a388ef9689e1415d2009bf8d59c753dcd0bdb72e85e47b3a86d0f',
];

foreach ($frozen as $name => $expected) {
    $path = $root . '/public/assets/' . $name;
    if (!is_file($path)) {
        throw new RuntimeException('frozen stylesheet is missing: ' . $name
            . ' -- if this is the teardown commit, delete this test too');
    }
    $actual = hash_file('sha256', $path);
    if ($actual !== $expected) {
        throw new RuntimeException(
            $name . ' is frozen and must not be edited.' . PHP_EOL
            . '  expected ' . $expected . PHP_EOL
            . '  actual   ' . $actual . PHP_EOL
            . 'Put the change in leikwan-v2.css instead.'
        );
    }
}

echo "ok\n";
