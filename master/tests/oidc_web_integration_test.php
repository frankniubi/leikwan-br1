<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertContains(string $needle, string $haystack, string $message): void
{
    if (!str_contains($haystack, $needle)) {
        fail($message . ': missing ' . $needle);
    }
}

function assertOrdered(string $first, string $second, string $source, string $message): void
{
    $firstOffset = strpos($source, $first);
    $secondOffset = strpos($source, $second);
    if ($firstOffset === false || $secondOffset === false || $firstOffset >= $secondOffset) {
        fail($message);
    }
}

function source(string $relativePath): string
{
    $source = file_get_contents(__DIR__ . '/../' . $relativePath);
    if (!is_string($source) || $source === '') {
        fail('failed to read ' . $relativePath);
    }
    return $source;
}

$bootstrap = source('src/Bootstrap.php');
$auth = source('src/Controllers/AuthController.php');
$oidc = source('src/Controllers/OidcController.php');
$session = source('src/Support/SessionAuth.php');
$env = source('.env.example');

assertContains('use Leikwan\\Master\\Controllers\\OidcController;', $bootstrap, 'bootstrap must import OIDC controller');
assertContains("'GET', '/auth/oidc/start'", $bootstrap, 'OIDC start route must be registered');
assertContains("'GET', '/auth/oidc/callback'", $bootstrap, 'OIDC callback route must be registered');
assertContains('new OidcController(', $bootstrap, 'bootstrap must construct OIDC controller');
assertContains('new OidcClient(', $bootstrap, 'bootstrap must share an OIDC client');
assertContains('new OidcSessionStore(', $bootstrap, 'bootstrap must share an OIDC session store');
assertContains('new OidcIdentityService(', $bootstrap, 'bootstrap must share an OIDC identity service');
assertContains('new WhmcsIdentityService(', $bootstrap, 'bootstrap must share WHMCS identity service');
assertContains('new AuthController($this->config, $this->db, $oidcClient)', $bootstrap, 'login page must receive the shared OIDC client');

assertContains('final class OidcController extends BaseController', $oidc, 'OIDC controller must extend BaseController');
assertContains('public function start(', $oidc, 'OIDC start action missing');
assertContains('public function callback(', $oidc, 'OIDC callback action missing');
assertContains('private function localReturnPath(', $oidc, 'OIDC return path sanitizer missing');
assertContains("str_starts_with(\$returnTo, '/')", $oidc, 'return path must be an absolute local path');
assertContains("str_starts_with(\$returnTo, '//')", $oidc, 'scheme-relative return path must be rejected');
assertContains("str_contains(\$returnTo, '\\\\')", $oidc, 'backslash return path must be rejected');
assertContains("preg_match('/[\\r\\n]/'", $oidc, 'CRLF return path must be rejected');
assertContains('return \'/\';', $oidc, 'unsafe return path must fall back to root');
assertContains("\$request->input('return', '/')", $oidc, 'start must read optional return target');
assertContains('$this->sessionStore->create(', $oidc, 'start must create OIDC flow state');
assertContains('$this->oidcClient->authorizationUrl($flow)', $oidc, 'start must redirect to authorization URL');
assertContains("\$request->input('error', '')", $oidc, 'callback must reject provider errors');
assertContains("\$request->input('code', '')", $oidc, 'callback must require code');
assertContains("\$request->input('state', '')", $oidc, 'callback must require state');
assertOrdered("\$request->input('state', '')", "\$request->input('error', '')", $oidc, 'provider errors must consume their state before returning');
assertOrdered('$this->sessionStore->consume($state)', '$this->oidcClient->authenticateCallback($code, $flow)', $oidc, 'state must be consumed before code exchange');
assertOrdered('$this->oidcClient->authenticateCallback($code, $flow)', '$this->identityService->login($claims)', $oidc, 'identity must be resolved after authentication');
assertContains('$this->whmcsIdentityService->tryDirectBindForUser(', $oidc, 'OIDC login must attempt WHMCS direct binding');
assertContains('$this->whmcsIdentityService->isConfigured()', $oidc, 'WHMCS direct binding must be conditional on configuration');
assertOrdered('$this->whmcsIdentityService->isConfigured()', '$this->whmcsIdentityService->tryDirectBindForUser(', $oidc, 'WHMCS configuration must be checked before direct binding');
assertOrdered('$this->whmcsIdentityService->tryDirectBindForUser(', 'SessionAuth::login($user)', $oidc, 'WHMCS binding attempt must complete before panel session login');
assertOrdered('$this->identityService->login($claims)', 'SessionAuth::login($user)', $oidc, 'session login must use resolved identity');
assertContains('catch (Throwable)', $oidc, 'callback must contain a safe failure path');
assertContains("SessionAuth::flash('error', 'Unable to sign in with the identity provider. Please try again.')", $oidc, 'failure must use a safe friendly message');
assertContains("Response::redirect('/login')", $oidc, 'failure must return to login');
if (str_contains($oidc, 'super-secret') || preg_match('/(?:token|code|body)\\s*=>\\s*\\$?(?:token|code|body)/i', $oidc) === 1) {
    fail('OIDC controller must not expose provider tokens, codes, or response bodies');
}

assertContains('private ?OidcClient $oidcClient', $auth, 'auth controller must accept optional OIDC client');
assertContains('public function __construct(', $auth, 'auth controller constructor missing');
assertContains('parent::__construct($config, $db)', $auth, 'auth controller must initialize base controller');
assertContains('$this->oidcClient->isConfigured()', $auth, 'OIDC action must be conditional on configuration');
assertContains('$this->escape($this->oidcClient->providerName())', $auth, 'provider name must be escaped');
assertContains('href="/auth/oidc/start"', $auth, 'login page must expose OIDC start action');

assertContains('session_regenerate_id(true)', $session, 'session login must regenerate active session ID');
assertOrdered('self::boot()', 'session_regenerate_id(true)', $session, 'session must be active before regeneration');
assertOrdered('session_regenerate_id(true)', "\$_SESSION['auth']", $session, 'identity must be written after session regeneration');

require_once __DIR__ . '/../src/Support/SessionAuth.php';
session_id('oidc-web-test-' . bin2hex(random_bytes(8)));
session_start();
$sessionBeforeLogin = session_id();
\Leikwan\Master\Support\SessionAuth::login(['id' => 42, 'role' => 'tenant']);
if (session_id() === $sessionBeforeLogin) {
    fail('session login must rotate the active session ID');
}
if (\Leikwan\Master\Support\SessionAuth::identity() !== ['user_id' => 42, 'role' => 'tenant']) {
    fail('session login must preserve the authenticated identity after rotation');
}
session_destroy();

foreach ([
    'OIDC_ENABLED=false',
    'OIDC_PROVIDER_NAME=',
    'OIDC_ISSUER_URL=',
    'OIDC_DISCOVERY_URL=',
    'OIDC_CLIENT_ID=',
    'OIDC_CLIENT_SECRET=',
    'OIDC_REDIRECT_URI=',
    'OIDC_SCOPES=openid email profile',
    'OIDC_TOKEN_AUTH_METHOD=client_secret_post',
    'OIDC_USE_PKCE=false',
    'OIDC_ALLOWED_SIGNING_ALGS=RS256',
    'OIDC_CLOCK_SKEW_SECONDS=120',
    'OIDC_REQUIRE_EMAIL_VERIFIED=true',
] as $key) {
    assertContains($key, $env, 'missing OIDC environment template key');
}

if (preg_match('/OIDC_CLIENT_SECRET=(?!$|change_me|example|your_)/mi', $env) === 1) {
    fail('environment example must not contain an OIDC client secret literal');
}

echo "ok\n";
