<?php
declare(strict_types=1);

/**
 * The brand mark, tested through behaviour.
 *
 * The first version of this file asserted the source text of brandLogoUrl():
 * str_contains($base, "'BRAND_LOGO_URL', '/assets/brand/leikwan-mark.png'").
 * Swapping the two arguments, lifting the path to a constant, wrapping the
 * return across lines, or appending the `?v=` cache-buster this repo already
 * puts on every sibling stylesheet would each turn that red while changing
 * nothing a user can see -- and none of it checked what the method returns.
 * String-locking tests are why three design systems stacked up while the suite
 * stayed green, so this file calls the method instead of reading it.
 *
 * No database is needed. Database stores its config and connects lazily via
 * pdo(), and brandLogoUrl() never queries -- which is why this does not join
 * the pre-existing "could not find driver" failures.
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

function brandFail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

// A stray warning would be written into the response buffer ahead of the page,
// so treat one as a failure rather than letting it scroll past.
set_error_handler(static function (int $no, string $message, string $file, int $line): bool {
    brandFail('brandLogoUrl() emitted a diagnostic: ' . $message . ' at ' . $file . ':' . $line);
});

final class BrandMarkProbe extends BaseController
{
    public function __construct(Config $config)
    {
        parent::__construct($config, new Database($config));
    }

    public function logoUrl(): string
    {
        return $this->brandLogoUrl();
    }
}

$brandEnvPath = tempnam(sys_get_temp_dir(), 'lw-brand-');

/**
 * Each case builds a fresh Config so nothing leaks between them.
 *
 * $env is the process environment variable (null unsets it) and $file is an
 * extra literal .env line. Both paths are exercised because Config::get()
 * consults getenv() first and the .env values second, and the empty-value trap
 * this file guards exists on both.
 */
function brandLogo(?string $env, string $file = ''): string
{
    global $brandEnvPath;

    file_put_contents($brandEnvPath, "DB_DRIVER=sqlite\nDB_NAME=:memory:\n" . $file);
    if ($env === null) {
        putenv('BRAND_LOGO_URL');
    } else {
        putenv('BRAND_LOGO_URL=' . $env);
    }

    return (new BrandMarkProbe(new Config($brandEnvPath)))->logoUrl();
}

function brandIs(string $expected, string $actual, string $what): void
{
    if ($actual !== $expected) {
        brandFail($what . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

// --- The default ---------------------------------------------------------

$default = brandLogo(null);
if ($default === '') {
    brandFail('brandLogoUrl() returned an empty string, which renders <img src=""> on every page');
}

// Served from this host. The logo used to be fetched from buy.leikwanhost.com,
// which is a needless third-party dependency for the users this product exists
// for: behind the GFW a cross-host image is a blank mark at best and a stalled
// page at worst. Asserted on the returned value, so no phrasing of the default
// can slip past -- an absolute URL, a protocol-relative //host/path, and a
// data: URI all fail here.
if (parse_url($default, PHP_URL_HOST) !== null || parse_url($default, PHP_URL_SCHEME) !== null) {
    brandFail('the default brand mark must be host-relative, not fetched cross-host: ' . $default);
}
if (!str_starts_with($default, '/')) {
    brandFail('the default brand mark must be root-relative so it resolves the same on every page depth: ' . $default);
}

// --- The asset the default actually points at ----------------------------
// Derived from the return value rather than hardcoded, so the size and format
// checks below cannot drift away from whatever the method really serves. The
// `?v=` cache-buster convention is tolerated: only the path is resolved.

$publicDir = (string) realpath(__DIR__ . '/../public');
$assetPath = (string) parse_url($default, PHP_URL_PATH);
$asset = realpath($publicDir . $assetPath);

if ($asset === false || !is_file($asset)) {
    brandFail('the default brand mark is not on disk: ' . $default . ' resolves to nothing under ' . $publicDir);
}
if (!str_starts_with($asset, $publicDir . DIRECTORY_SEPARATOR)) {
    brandFail('the default brand mark must live under public/ to be servable: ' . $asset);
}

// The original was 1.2 MB for a 40px render. Keep it small.
$bytes = (int) filesize($asset);
if ($bytes > 60000) {
    brandFail('brand mark regressed to ' . $bytes . ' bytes; keep it under 60 KB');
}

// It must be a real PNG.
if (substr((string) file_get_contents($asset, false, null, 0, 8), 1, 3) !== 'PNG') {
    brandFail('brand mark is not a PNG: ' . $asset);
}

// --- An empty configured value must not win ------------------------------
//
// Config::get() returns `$this->values[$key] ?? $default`, so a key that is
// *present but empty* beats the default -- it does not miss it. install.php
// writes `$values[$key] ?? ''` for every key it finds in .env.example, so a
// single bare `BRAND_LOGO_URL=` line in that template would ship
// <img src=""> on every page of every new install, with nothing logged
// anywhere. This is the exact scenario, spelled out as an .env body:

brandIs($default, brandLogo(null, "BRAND_LOGO_URL=\n"), 'a bare BRAND_LOGO_URL= line in .env must fall back to the local mark, not render an empty src');
brandIs($default, brandLogo(null, "BRAND_LOGO_URL=''\n"), 'an empty quoted value must fall back too');

// The same trap on the getenv() path: an exported-but-empty variable. Some
// platforms delete a variable set to "" instead of emptying it, which would
// make the assertion below pass for the wrong reason, so check the premise.
brandLogo('');
if (getenv('BRAND_LOGO_URL') !== '') {
    brandFail('this platform cannot represent an exported-but-empty variable, so the getenv() half of the empty-value trap is untested here');
}
brandIs($default, brandLogo(''), 'an exported-but-empty BRAND_LOGO_URL must fall back to the local mark');

// Whitespace renders just as broken as empty and is what a hand-edited .env
// produces. getenv() values are not trimmed by Config, so only the method can
// catch this one.
brandIs($default, brandLogo('   '), 'a whitespace-only BRAND_LOGO_URL must fall back to the local mark');
brandIs($default, brandLogo("\t\n"), 'a BRAND_LOGO_URL of stray control whitespace must fall back to the local mark');

// --- A real override must still win --------------------------------------
// The hardening rejects empty, not everything: a deployment pointing at its
// own asset must be unaffected, including a cross-host CDN if that is what the
// operator chose. Only the *default* is required to stay local.

brandIs('https://cdn.example.com/brand.svg', brandLogo('https://cdn.example.com/brand.svg'), 'a configured logo URL must be honoured verbatim');
brandIs('/assets/brand/custom.png?v=7', brandLogo(null, "BRAND_LOGO_URL=/assets/brand/custom.png?v=7\n"), 'a configured logo from .env must be honoured, cache-buster included');
brandIs('/assets/brand/custom.png', brandLogo('  /assets/brand/custom.png  '), 'a padded configured value must be trimmed, not spliced into src with its whitespace');

// --- The one thing behaviour cannot show ---------------------------------
// brandLogoUrl() being clean does not stop some other render site hardcoding
// the old cross-host logo in its own <img> tag. This looks for a known-bad
// string rather than the shape of an implementation, so no refactor of
// brandLogoUrl() can turn it red.

$remote = 'buy.leikwanhost.com/assets/img/logo.png';
$srcDir = (string) realpath(__DIR__ . '/../src');
/** @var SplFileInfo $file */
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir)) as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    if (str_contains((string) file_get_contents($file->getPathname()), $remote)) {
        brandFail('the remote logo is back in ' . $file->getPathname() . ': ' . $remote);
    }
}

putenv('BRAND_LOGO_URL');
restore_error_handler();
unlink($brandEnvPath);

echo "ok\n";
