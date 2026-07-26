<?php
declare(strict_types=1);

/**
 * The skin flag, tested through behaviour.
 *
 * The first version of this test asserted the source text of activeSkin().
 * A mutation check deleted the admin gate outright -- making ?skin=v2
 * available to every logged-in user -- and all six assertions still passed.
 * The one behaviour the test existed to protect was the one it did not check.
 *
 * That is the failure mode this whole project exists to end: string-locking
 * tests are why three stylesheets accumulated while the suite stayed green.
 * So this file calls the method instead of reading it.
 *
 * No database is needed. Database stores its config and connects lazily, and
 * activeSkin() never queries -- which is why this does not join the six
 * pre-existing "could not find driver" failures.
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use Leikwan\Master\Config;
use Leikwan\Master\Controllers\BaseController;
use Leikwan\Master\Database\Database;

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

// A stray warning would be written into the response buffer ahead of the page,
// so treat one as a failure rather than letting it scroll past.
set_error_handler(static function (int $no, string $message, string $file, int $line): bool {
    fail('activeSkin() emitted a diagnostic: ' . $message . ' at ' . $file . ':' . $line);
});

final class SkinFlagProbe extends BaseController
{
    public function __construct(Config $config)
    {
        parent::__construct($config, new Database($config));
    }

    public function skin(?array $viewer = null): string
    {
        return $this->activeSkin($viewer);
    }
}

$envPath = tempnam(sys_get_temp_dir(), 'lw-skin-');
file_put_contents($envPath, "DB_DRIVER=sqlite\nDB_NAME=:memory:\n");

$admin = ['id' => 1, 'role' => 'admin'];
$tenant = ['id' => 2, 'role' => 'tenant'];

/**
 * Each case gets a fresh controller and clean superglobals, so nothing leaks
 * between them.
 */
function probe(?string $flag, array $get = [], array $session = []): SkinFlagProbe
{
    global $envPath;

    if ($flag === null) {
        putenv('LEIKWAN_SKIN');
    } else {
        putenv('LEIKWAN_SKIN=' . $flag);
    }
    $_GET = $get;
    $_SESSION = $session;

    return new SkinFlagProbe(new Config($envPath));
}

function is(string $expected, string $actual, string $what): void
{
    if ($actual !== $expected) {
        fail($what . ': expected ' . $expected . ', got ' . $actual);
    }
}

// --- The flag ------------------------------------------------------------

is('legacy', probe(null)->skin($admin), 'an unset flag must render the legacy skin');
is('v2', probe('v2')->skin($admin), 'LEIKWAN_SKIN=v2 must render v2');
is('v2', probe(' V2 ')->skin($admin), 'the flag must tolerate case and whitespace');
is('legacy', probe('banana')->skin($admin), 'an unrecognised flag must fall back, not render an undefined skin');
is('legacy', probe('')->skin($admin), 'an empty flag must fall back');

// --- The admin gate ------------------------------------------------------
// Deleting the gate must turn this file red. That is the whole point of it.

is('legacy', probe('legacy', ['skin' => 'v2'])->skin($tenant), '?skin=v2 must be ignored for a tenant');
is('legacy', probe('legacy', ['skin' => 'v2'])->skin(null), '?skin=v2 must be ignored for an anonymous visitor');
is('legacy', probe('legacy', ['skin' => 'v2'])->skin(['id' => 3]), '?skin=v2 must be ignored for a viewer with no role');
is('legacy', probe('legacy', ['skin' => 'v2'])->skin(['role' => 'Admin']), 'the role comparison is case-sensitive everywhere else in this codebase and must stay so');

probe('legacy', ['skin' => 'v2'])->skin($tenant);
if (isset($_SESSION['skin_override'])) {
    fail('a tenant must not be able to write a skin override into the session');
}

// --- The admin preview ---------------------------------------------------

is('v2', probe('legacy', ['skin' => 'v2'])->skin($admin), 'an admin may preview v2 while the flag is off');
if (!isset($_SESSION['skin_override'])) {
    fail('the admin preview must persist for the session');
}
$stamped = (string) $_SESSION['skin_override'];

is('v2', probe('legacy', [], ['skin_override' => $stamped])->skin($admin), 'the stored preview must survive to the next request');
is('legacy', probe('legacy', [], ['skin_override' => $stamped])->skin($tenant), 'a stored preview must not leak to a tenant sharing the session store');
is('legacy', probe('v2', ['skin' => 'legacy'])->skin($admin), 'an admin may preview the legacy skin while the flag is on');
is('v2', probe('v2', ['skin' => 'banana'])->skin($admin), 'an unrecognised ?skin value must fall through to the flag');
is('v2', probe('legacy', ['skin' => ' V2 '])->skin($admin), 'the query parameter must tolerate the same case and whitespace as the flag');

// Precedence. ?skin= must outrank a stored preview: it is the only way out of
// a preview, so if the stored value wins, an admin who pins a broken skin can
// never unpin it. Swapping these two blocks leaves every other assertion in
// this file green -- no other case supplies both at once.
is('legacy', probe('legacy', ['skin' => 'legacy'], ['skin_override' => 'v2@legacy'])->skin($admin), '?skin= must outrank a stored preview -- it is the only way back');
is('v2', probe('legacy', ['skin' => 'v2'], ['skin_override' => 'legacy@legacy'])->skin($admin), '?skin= must outrank a stored preview in both directions');

// --- The kill switch -----------------------------------------------------
// Rolling the flag back must be enough on its own. Otherwise the people left
// pinned to a broken skin are exactly the admins trying to fix it.

is('legacy', probe('legacy', [], ['skin_override' => 'v2@v2'])->skin($admin), 'a preview chosen against a different flag value must be discarded');
if (isset($_SESSION['skin_override'])) {
    fail('a stale preview must be cleared, not merely ignored');
}
is('legacy', probe('legacy', [], ['skin_override' => 'v2'])->skin($admin), 'an unstamped preview from an older release must be discarded');
is('legacy', probe('legacy', [], ['skin_override' => 'banana@legacy'])->skin($admin), 'a corrupt preview must be discarded');
is('legacy', probe('legacy', [], ['skin_override' => 'v2@legacy@junk'])->skin($admin), 'an over-segmented stamp must fail closed, not have its tail truncated away');

// --- Hostile input -------------------------------------------------------

is('legacy', probe('legacy', ['skin' => ['v2']])->skin($admin), 'an array ?skin must not be coerced');
is('legacy', probe('legacy', [], ['skin_override' => ['v2@legacy']])->skin($admin), 'an array in the session must not be coerced either');
is('legacy', probe('legacy', ['skin' => '<script>'])->skin($admin), 'the return value is spliced into markup and must stay a known literal');

foreach ([null, '', 'v2', 'legacy', 'banana'] as $flag) {
    foreach ([null, $admin, $tenant] as $viewer) {
        $got = probe($flag, ['skin' => 'banana'], ['skin_override' => 'banana@banana'])->skin($viewer);
        if ($got !== 'v2' && $got !== 'legacy') {
            fail('activeSkin() must only ever return v2 or legacy, got: ' . $got);
        }
    }
}

// --- The one thing behaviour cannot show ---------------------------------
// The flag must be read in one place. Three stylesheets accumulated because
// each new layer read its own switch, and no behavioural test can observe the
// absence of a second read.

$source = (string) file_get_contents(__DIR__ . '/../src/Controllers/BaseController.php');
if (substr_count($source, "config->get('LEIKWAN_SKIN'") !== 1) {
    fail('LEIKWAN_SKIN must be read in exactly one place');
}

restore_error_handler();
unlink($envPath);

echo "ok\n";
