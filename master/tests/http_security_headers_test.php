<?php
declare(strict_types=1);

// Session cookie flags + default browser security headers (F8).

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
use Leikwan\Master\Controllers\ArtifactController;
use Leikwan\Master\Http\Request;
use Leikwan\Master\Http\Response;
use Leikwan\Master\Support\HttpSecurity;

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

// --- HTTPS detection -------------------------------------------------------
$https = [
    'HTTPS=on' => ['HTTPS' => 'on'],
    'HTTPS=1' => ['HTTPS' => '1'],
    'REQUEST_SCHEME' => ['REQUEST_SCHEME' => 'https'],
    'port 443' => ['SERVER_PORT' => '443'],
    'X-Forwarded-Proto' => ['HTTP_X_FORWARDED_PROTO' => 'https', 'SERVER_PORT' => '80'],
    'X-Forwarded-Proto list' => ['HTTP_X_FORWARDED_PROTO' => 'HTTPS, http'],
    'CF-Visitor' => ['HTTP_CF_VISITOR' => '{"scheme":"https"}', 'SERVER_PORT' => '80'],
];
foreach ($https as $label => $server) {
    if (!HttpSecurity::requestIsHttps($server)) {
        fail('expected HTTPS for ' . $label);
    }
}
$plain = [
    'empty' => [],
    'HTTPS=off' => ['HTTPS' => 'off', 'SERVER_PORT' => '80'],
    'local dev php -S' => ['SERVER_PORT' => '8080', 'HTTP_HOST' => '127.0.0.1:8080'],
    'X-Forwarded-Proto http' => ['HTTP_X_FORWARDED_PROTO' => 'http'],
    'CF-Visitor http' => ['HTTP_CF_VISITOR' => '{"scheme":"http"}'],
];
foreach ($plain as $label => $server) {
    if (HttpSecurity::requestIsHttps($server)) {
        fail('expected plain HTTP for ' . $label);
    }
}

// --- Cookie params ---------------------------------------------------------
$secureParams = HttpSecurity::sessionCookieParams(['HTTPS' => 'on']);
$expected = ['lifetime' => 0, 'path' => '/', 'domain' => '', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax'];
if ($secureParams !== $expected) {
    fail('unexpected HTTPS cookie params: ' . var_export($secureParams, true));
}
$devParams = HttpSecurity::sessionCookieParams(['SERVER_PORT' => '8080']);
if ($devParams['secure'] !== false || $devParams['httponly'] !== true || $devParams['samesite'] !== 'Lax' || $devParams['path'] !== '/') {
    fail('plain-HTTP dev cookie must drop only Secure: ' . var_export($devParams, true));
}
$ini = HttpSecurity::sessionIniSettings();
if (($ini['session.use_strict_mode'] ?? null) !== '1' || ($ini['session.use_only_cookies'] ?? null) !== '1') {
    fail('strict mode and cookie-only sessions must be enabled');
}

// startSession() really applies them (nothing has been printed yet, so headers are not sent).
session_save_path(sys_get_temp_dir());
if (!HttpSecurity::startSession(['HTTPS' => 'on'])) {
    fail('startSession failed');
}
$applied = session_get_cookie_params();
if ($applied['secure'] !== true || $applied['httponly'] !== true || $applied['samesite'] !== 'Lax' || $applied['path'] !== '/') {
    fail('startSession did not apply cookie params: ' . var_export($applied, true));
}
if (ini_get('session.use_strict_mode') !== '1' || ini_get('session.use_only_cookies') !== '1') {
    fail('startSession did not enable strict/cookie-only sessions');
}
if (!HttpSecurity::startSession()) {
    fail('startSession must be idempotent on an active session');
}
session_destroy();

// --- Security headers ------------------------------------------------------
$headers = HttpSecurity::securityHeaders();
$expectedHeaders = [
    'X-Frame-Options' => 'DENY',
    'Content-Security-Policy' => "frame-ancestors 'none'",
    'X-Content-Type-Options' => 'nosniff',
    'Referrer-Policy' => 'same-origin',
];
if ($headers !== $expectedHeaders) {
    fail('unexpected security headers: ' . var_export($headers, true));
}
if (str_contains(implode(';', $headers), 'script-src')) {
    fail('no script-src CSP: server-rendered pages use inline scripts');
}

$html = HttpSecurity::withSecurityHeaders(Response::html(200, '<p>x</p>'))->headers();
foreach ($expectedHeaders as $name => $value) {
    if (($html[$name] ?? null) !== $value) {
        fail('HTML response missing ' . $name);
    }
}
if ($html['Content-Type'] !== 'text/html; charset=utf-8') {
    fail('Content-Type must be preserved');
}
// A controller-chosen header wins (WHMCS SSO sets Referrer-Policy: no-referrer).
$sso = HttpSecurity::withSecurityHeaders(Response::redirect('/tenant')->withHeaders(['referrer-policy' => 'no-referrer']))->headers();
if (($sso['referrer-policy'] ?? null) !== 'no-referrer' || isset($sso['Referrer-Policy'])) {
    fail('existing header must not be overridden or duplicated');
}

// Artifact downloads keep their explicit content types under nosniff.
$envPath = tempnam(sys_get_temp_dir(), 'lw-security-headers-');
if ($envPath === false) {
    fail('failed to create temp env');
}
file_put_contents($envPath, "DB_DRIVER=sqlite\nDB_NAME=:memory:\nAPP_URL=https://panel.example.com\n");
$controller = new ArtifactController(new Config($envPath));
foreach ([
    'installClient' => 'text/plain; charset=utf-8',
    'installClientAgent' => 'text/plain; charset=utf-8',
    'installClientPowerShell' => 'text/plain; charset=utf-8',
    'clientAgent' => 'text/x-python; charset=utf-8',
] as $method => $contentType) {
    $response = HttpSecurity::withSecurityHeaders($controller->{$method}(new Request('GET', '/artifacts/x', [], [], [], ''), []));
    $artifactHeaders = $response->headers();
    if ($response->status() !== 200) {
        fail($method . ' returned ' . $response->status());
    }
    if (($artifactHeaders['Content-Type'] ?? '') !== $contentType || ($artifactHeaders['X-Content-Type-Options'] ?? '') !== 'nosniff') {
        fail($method . ' headers changed: ' . var_export($artifactHeaders, true));
    }
}
@unlink($envPath);

// --- Wiring ----------------------------------------------------------------
$index = (string) file_get_contents(__DIR__ . '/../public/index.php');
$install = (string) file_get_contents(__DIR__ . '/../public/install.php');
foreach ([
    'HttpSecurity::removeServerBanner();',
    'HttpSecurity::startSession();',
    'HttpSecurity::withSecurityHeaders($response)->send();',
] as $needle) {
    if (!str_contains($index, $needle)) {
        fail('public/index.php missing ' . $needle);
    }
}
foreach (['HttpSecurity::removeServerBanner();', 'HttpSecurity::startSession();', "frame-ancestors 'none'"] as $needle) {
    if (!str_contains($install, $needle)) {
        fail('public/install.php missing ' . $needle);
    }
}

// No bare session_start() may bypass the hardened cookie params.
$roots = [__DIR__ . '/../src', __DIR__ . '/../public'];
foreach ($roots as $root) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        if (str_ends_with(str_replace('\\', '/', $file->getPathname()), 'Support/HttpSecurity.php')) {
            continue;
        }
        if (preg_match('/(?<![\w:>])session_start\s*\(/', $source) === 1) {
            fail('bare session_start() in ' . $file->getPathname() . ' - use HttpSecurity::startSession()');
        }
    }
}

echo "http_security_headers_test: ok\n";
