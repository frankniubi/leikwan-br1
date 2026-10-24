<?php
declare(strict_types=1);

// WHMCS server module contract: routes, module entry points, and the
// single-use, client-bound SSO tokens behind ServiceSingleSignOn.

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Support\ApiException;
use Leikwan\Master\Support\WhmcsSsoService;

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Database/Database.php';
require_once __DIR__ . '/../src/Support/ApiException.php';
require_once __DIR__ . '/../src/Support/LocalRedirectPath.php';
require_once __DIR__ . '/../src/Support/WhmcsSsoService.php';

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fail($message);
    }
}

$bootstrap = (string) file_get_contents(__DIR__ . '/../src/Bootstrap.php');
foreach ([
    "'GET', '/api/whmcs/ping'",
    "'GET', '/api/whmcs/plans'",
    "'POST', '/api/whmcs/unsuspend'",
    "'POST', '/api/whmcs/sso'",
    "'GET', '/auth/whmcs/sso'",
] as $route) {
    assertTrue(str_contains($bootstrap, $route), 'route missing: ' . $route);
}

$module = (string) file_get_contents(__DIR__ . '/../integrations/whmcs/modules/servers/leikwan/leikwan.php');
foreach (['MetaData', 'ConfigOptions', 'TestConnection', 'CreateAccount', 'SuspendAccount', 'UnsuspendAccount', 'TerminateAccount', 'Renew', 'ChangePackage', 'ServiceSingleSignOn', 'ClientArea'] as $fn) {
    assertTrue(str_contains($module, 'function leikwan_' . $fn . '('), 'module entry point missing: ' . $fn);
}
assertTrue(str_contains($module, "unset(\$loggedBody['initial_password'])"), 'module must not log the initial password');

$envPath = tempnam(sys_get_temp_dir(), 'lw-whmcs-sso-');
file_put_contents($envPath, "DB_DRIVER=sqlite\nDB_NAME=:memory:\n");
$db = new Database(new Config($envPath));
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->setPdo($pdo);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, role TEXT, status TEXT, whmcs_client_id INTEGER)');
$pdo->exec('CREATE TABLE services (id INTEGER PRIMARY KEY, user_id INTEGER, status TEXT, whmcs_service_id INTEGER)');
$pdo->exec('CREATE TABLE whmcs_sso_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, token_hash TEXT UNIQUE, user_id INTEGER, service_id INTEGER, redirect_path TEXT, expires_at TEXT, used_at TEXT, created_at TEXT)');
$pdo->exec("INSERT INTO users VALUES (1, 'buyer@example.test', 'tenant', 'active', 501), (2, 'admin@example.test', 'admin', 'active', 502), (3, 'unbound@example.test', 'tenant', 'active', NULL)");
$pdo->exec("INSERT INTO services VALUES (10, 1, 'active', 9001), (11, 2, 'active', 9002), (12, 3, 'active', 9003), (13, 1, 'terminated', 9004)");

$sso = new WhmcsSsoService($db);
$link = $sso->issue(['whmcs_service_id' => 9001, 'whmcs_client_id' => 501]);
assertTrue(preg_match('/^[0-9a-f]{64}$/', $link['token']) === 1, 'token must be 32 random bytes hex');
assertTrue($link['redirect_path'] === '/tenant/services/10', 'default redirect is the service page');
$stored = $pdo->query('SELECT token_hash FROM whmcs_sso_tokens')->fetchColumn();
assertTrue($stored === hash('sha256', $link['token']), 'only the token hash is stored');

$consumed = $sso->consume($link['token']);
assertTrue(($consumed['user']['id'] ?? 0) === 1 || ($consumed['user']['id'] ?? 0) === '1', 'token signs in the bound tenant');
assertTrue($sso->consume($link['token']) === null, 'token is single-use');

$expired = $sso->issue(['whmcs_service_id' => 9001, 'whmcs_client_id' => 501]);
$pdo->exec("UPDATE whmcs_sso_tokens SET expires_at = '2000-01-01 00:00:00' WHERE used_at IS NULL");
assertTrue($sso->consume($expired['token']) === null, 'expired token is refused');
assertTrue($sso->consume('not-a-token') === null, 'malformed token is refused');

$offsite = $sso->issue(['whmcs_service_id' => 9001, 'whmcs_client_id' => 501, 'redirect_path' => '//evil.example/']);
assertTrue($offsite['redirect_path'] === '/tenant/services/10', 'off-site redirect falls back to the service page');

foreach ([
    'another client' => ['whmcs_service_id' => 9001, 'whmcs_client_id' => 999],
    'admin account' => ['whmcs_service_id' => 9002, 'whmcs_client_id' => 502],
    'unbound account' => ['whmcs_service_id' => 9003, 'whmcs_client_id' => 503],
] as $label => $payload) {
    try {
        $sso->issue($payload);
        fail('SSO must be refused for ' . $label);
    } catch (ApiException $e) {
        assertTrue($e->getMessage() === 'whmcs_sso_not_allowed', 'wrong refusal for ' . $label . ': ' . $e->getMessage());
    }
}
try {
    $sso->issue(['whmcs_service_id' => 9004, 'whmcs_client_id' => 501]);
    fail('SSO must be refused for a terminated service');
} catch (ApiException $e) {
    assertTrue($e->getMessage() === 'service_not_found', 'terminated service must look absent');
}

@unlink($envPath);
echo "ok\n";
