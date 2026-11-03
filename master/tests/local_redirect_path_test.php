<?php
declare(strict_types=1);

// Post-login "return to" validation (OIDC start/callback, OIDC flow store,
// WHMCS SSO). Browsers strip tab/CR/LF and read "\" as "/", so any of those
// can turn a local path into a protocol-relative off-site redirect.

require_once __DIR__ . '/../src/Support/LocalRedirectPath.php';

use Leikwan\Master\Support\LocalRedirectPath;

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$rejected = [
    'empty' => '',
    'absolute url' => 'https://evil.example/',
    'scheme relative' => '//evil.example',
    'slash backslash' => '/\\evil.example',
    'backslash anywhere' => '/tenant\\..\\x',
    'tab after slash (live payload /%09/evil.example)' => rawurldecode('/%09/evil.example'),
    'newline after slash' => "/\n/evil.example",
    'carriage return after slash' => "/\r/evil.example",
    'nul byte' => "/ten\0ant",
    'space inside' => '/ /evil.example',
    'del byte' => "/\x7f/evil.example",
    'vertical tab' => "/\x0b/evil.example",
    'form feed' => "/\x0c/evil.example",
    'double-encoded tab' => '/%09/evil.example',
    'encoded slash prefix' => '/%2F/evil.example',
    'encoded slash prefix lowercase' => '/%2f%2fevil.example',
    'encoded backslash' => '/%5Cevil.example',
    'encoded newline' => '/tenant%0d%0aSet-Cookie:x=1',
    'relative path' => 'tenant',
    'javascript scheme' => 'javascript:alert(1)',
    'data scheme' => 'data:text/html,hi',
    'oversized' => '/' . str_repeat('a', 4096),
];
foreach ($rejected as $label => $path) {
    if (LocalRedirectPath::isSafe($path)) {
        fail('expected rejection for ' . $label . ': ' . var_export($path, true));
    }
    if (LocalRedirectPath::sanitize($path, '/fallback') !== '/fallback') {
        fail('sanitize must fall back for ' . $label);
    }
}

$accepted = [
    '/',
    '/tenant',
    '/tenant/services/12',
    '/tenant/services/12?tab=forwards&x=1',
    '/admin/knowledgebase#top',
    '/tenant?q=a%20b',
];
foreach ($accepted as $path) {
    if (!LocalRedirectPath::isSafe($path)) {
        fail('expected local path to be accepted: ' . $path);
    }
    if (LocalRedirectPath::sanitize($path, '/fallback') !== $path) {
        fail('sanitize must keep local path: ' . $path);
    }
}
if (LocalRedirectPath::sanitize("  /tenant  ") !== '/tenant') {
    fail('surrounding whitespace is trimmed before validation');
}
if (LocalRedirectPath::sanitize("\t//evil.example") !== '/') {
    fail('trimmed scheme-relative path must still be rejected');
}

// Every post-login redirect sink must use the shared validator.
foreach ([
    'src/Controllers/OidcController.php' => "LocalRedirectPath::sanitize(\$returnTo, '/')",
    'src/Support/OidcSessionStore.php' => "LocalRedirectPath::sanitize(\$returnTo, '/')",
    'src/Support/WhmcsSsoService.php' => 'LocalRedirectPath::sanitize($path, $fallback)',
] as $file => $needle) {
    $source = (string) file_get_contents(__DIR__ . '/../' . $file);
    if (!str_contains($source, $needle)) {
        fail($file . ' must validate return paths with LocalRedirectPath');
    }
}

// Behavioural check through the OIDC flow store (it stores the return path
// that the callback later redirects to).
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
session_save_path(sys_get_temp_dir());
$store = new \Leikwan\Master\Support\OidcSessionStore(static fn(): int => 1_700_000_000);
foreach (['/%09/evil.example' => '/', "/\t/evil.example" => '/', '/tenant/services/3' => '/tenant/services/3'] as $input => $expected) {
    $flow = $store->create($input, false);
    if ($flow['return_to'] !== $expected) {
        fail('OIDC flow store kept unsafe return path ' . var_export($input, true) . ' as ' . var_export($flow['return_to'], true));
    }
}
session_destroy();

echo "local_redirect_path_test: ok\n";
