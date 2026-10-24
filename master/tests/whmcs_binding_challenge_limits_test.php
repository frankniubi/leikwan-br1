<?php
declare(strict_types=1);

// Needs pdo_sqlite: wsl -d Debian -- php master/tests/whmcs_binding_challenge_limits_test.php

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
use Leikwan\Master\Database\Database;
use Leikwan\Master\Support\ApiException;
use Leikwan\Master\Support\AuthRateLimiter;
use Leikwan\Master\Support\WhmcsClient;
use Leikwan\Master\Support\WhmcsIdentityService;

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fail($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

/** @param callable(): mixed $callback */
function expectApiError(callable $callback, string $error, int $status, string $message): ApiException
{
    try {
        $callback();
    } catch (ApiException $exception) {
        if ($exception->getMessage() !== $error || $exception->statusCode() !== $status) {
            fail($message . ': expected ' . $error . '/' . $status . ', got ' . $exception->getMessage() . '/' . $exception->statusCode());
        }
        return $exception;
    }
    fail($message . ': expected ' . $error);
}

$envPath = tempnam(sys_get_temp_dir(), 'lw-whmcs-challenge-');
file_put_contents($envPath, "DB_DRIVER=sqlite\nDB_NAME=:memory:\nWHMCS_BASE_URL=https://buy.example.test\nWHMCS_API_IDENTIFIER=test\nWHMCS_API_SECRET=test\nWHMCS_SUPPORT_DEPARTMENT_ID=1\n");
$config = new Config($envPath);
$db = new Database($config);
$pdo = $db->pdo();
$pdo->exec('CREATE TABLE auth_rate_limits (
    bucket_key TEXT NOT NULL PRIMARY KEY,
    window_started_at INTEGER NOT NULL,
    hits INTEGER NOT NULL DEFAULT 0,
    expires_at INTEGER NOT NULL
)');
$pdo->exec('CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT, email TEXT, role TEXT, status TEXT, email_verified_at TEXT,
    wallet_balance TEXT DEFAULT \'0.00\', wallet_currency TEXT DEFAULT \'CNY\',
    whmcs_client_id INTEGER NULL, whmcs_user_id INTEGER NULL, whmcs_email TEXT NULL,
    whmcs_full_name TEXT NULL, whmcs_currency_code TEXT NULL,
    whmcs_bound_at TEXT NULL, whmcs_last_synced_at TEXT NULL, updated_at TEXT NULL
)');
$pdo->exec('CREATE TABLE whmcs_login_challenges (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_token TEXT NOT NULL UNIQUE,
    user_id INTEGER NOT NULL,
    whmcs_client_id INTEGER NOT NULL,
    whmcs_user_id INTEGER NULL,
    whmcs_email TEXT NOT NULL,
    verification_code_hash TEXT NOT NULL,
    ticket_id INTEGER NULL,
    ticket_tid TEXT NULL,
    ticket_access_code TEXT NULL,
    expires_at TEXT NOT NULL,
    verified_at TEXT NULL,
    closed_at TEXT NULL,
    failed_attempts INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
)');

$verified = gmdate('Y-m-d H:i:s');
foreach (['one', 'two', 'three', 'four', 'five', 'six', 'seven'] as $name) {
    $db->insert('users', ['name' => $name, 'email' => $name . '@panel.test', 'role' => 'tenant', 'status' => 'active', 'email_verified_at' => $verified]);
}

// Fake Buy station: buy@example.test is client 789; tickets carry the code.
$lastCode = null;
$tickets = 0;
$whmcs = new WhmcsClient($config, static function (string $action, array $params) use (&$lastCode, &$tickets): array {
    $client = ['id' => 789, 'owner_user_id' => 456, 'email' => 'buy@example.test', 'firstname' => 'Buy', 'lastname' => 'User', 'currency_code' => 'CNY'];
    if ($action === 'GetClientsDetails') {
        if (($params['email'] ?? null) === 'buy@example.test' || (int) ($params['clientid'] ?? 0) === 789) {
            return ['result' => 'success', 'client' => $client];
        }
        return ['result' => 'success'];
    }
    if ($action === 'OpenTicket') {
        $tickets++;
        if (preg_match('/Verification code: (\d{6})/', (string) $params['message'], $matches) !== 1) {
            throw new \RuntimeException('ticket without code');
        }
        $lastCode = $matches[1];
        return ['result' => 'success', 'id' => 1000 + $tickets, 'tid' => 'T' . $tickets, 'c' => 'access'];
    }
    if ($action === 'GetClientsProducts') {
        return ['result' => 'success', 'products' => ['product' => []]];
    }
    if ($action === 'GetSupportStatuses') {
        return ['result' => 'success', 'statuses' => ['status' => [['title' => 'Closed']]]];
    }
    if ($action === 'UpdateTicket') {
        return ['result' => 'success'];
    }
    throw new \RuntimeException('unexpected WHMCS action ' . $action);
});
$service = new WhmcsIdentityService($config, $db, $whmcs, new AuthRateLimiter($db, 1));
$wrongCode = static fn(string $code): string => str_pad((string) (((int) $code + 1) % 1000000), 6, '0', STR_PAD_LEFT);

// 1) Burned after MAX_CHALLENGE_ATTEMPTS wrong codes, even for the right code afterwards.
$first = $service->requestBinding(1, 'buy@example.test');
assertSame('challenge', $first['mode'], 'another email needs a ticket challenge');
$code = (string) $lastCode;
for ($i = 1; $i < WhmcsIdentityService::MAX_CHALLENGE_ATTEMPTS; $i++) {
    $error = expectApiError(static fn() => $service->verifyLoginChallenge($first['public_token'], $wrongCode($code)), 'whmcs_verification_code_invalid', 422, 'wrong code ' . $i);
    assertSame(WhmcsIdentityService::MAX_CHALLENGE_ATTEMPTS - $i, $error->details()['attempts_remaining'] ?? null, 'remaining attempts after ' . $i);
}
expectApiError(static fn() => $service->verifyLoginChallenge($first['public_token'], $wrongCode($code)), 'whmcs_challenge_locked', 410, 'the last wrong code burns the challenge');
expectApiError(static fn() => $service->verifyLoginChallenge($first['public_token'], $code), 'whmcs_challenge_locked', 410, 'a burned challenge refuses even the right code');
expectApiError(static fn() => $service->challengeDetails($first['public_token']), 'whmcs_challenge_locked', 410, 'the verify page reports a burned challenge');
assertSame(null, $db->fetchOne('SELECT whmcs_client_id FROM users WHERE id = 1')['whmcs_client_id'], 'a burned challenge binds nothing');

// 2) A fresh challenge still verifies after a couple of mistakes (spaces tolerated).
$second = $service->requestBinding(1, 'buy@example.test');
$code = (string) $lastCode;
expectApiError(static fn() => $service->verifyLoginChallenge($second['public_token'], $wrongCode($code)), 'whmcs_verification_code_invalid', 422, 'one mistake');
$bound = $service->verifyLoginChallenge($second['public_token'], ' ' . substr($code, 0, 3) . ' ' . substr($code, 3) . ' ');
assertSame(789, (int) $bound['whmcs_client_id'], 'the right code binds the account');
$row = $db->fetchOne('SELECT failed_attempts, verified_at FROM whmcs_login_challenges WHERE public_token = :t', ['t' => $second['public_token']]);
assertSame(1, (int) $row['failed_attempts'], 'only the wrong code is recorded as failed');
expectApiError(static fn() => $service->verifyLoginChallenge($second['public_token'], $code), 'whmcs_challenge_used', 410, 'a verified challenge cannot be replayed');

// 3) Unknown emails and emails linked to another panel account get a decoy
//    challenge that looks the same and can never verify.
$ticketsBefore = $tickets;
$unknown = $service->requestBinding(2, 'nobody@example.test');
assertSame('challenge', $unknown['mode'], 'an unknown email answers like a real one');
assertSame(true, strlen((string) $unknown['public_token']) === strlen((string) $first['public_token']), 'decoy token looks the same');
$taken = $service->requestBinding(3, 'buy@example.test'); // client 789 is linked to user 1 now
assertSame('challenge', $taken['mode'], 'an email linked elsewhere answers like a free one');
assertSame($ticketsBefore, $tickets, 'decoys open no ticket at the Buy station');
foreach ([$unknown, $taken] as $decoy) {
    for ($i = 1; $i < WhmcsIdentityService::MAX_CHALLENGE_ATTEMPTS; $i++) {
        expectApiError(static fn() => $service->verifyLoginChallenge($decoy['public_token'], str_pad((string) $i, 6, '0', STR_PAD_LEFT)), 'whmcs_verification_code_invalid', 422, 'decoy guess ' . $i);
    }
    expectApiError(static fn() => $service->verifyLoginChallenge($decoy['public_token'], '000000'), 'whmcs_challenge_locked', 410, 'decoys burn like real challenges');
}

// 4) Challenge creation is limited per panel user ...
$pdo->exec('DELETE FROM auth_rate_limits');
for ($i = 0; $i < AuthRateLimiter::BINDING_REQUEST_USER_LIMIT; $i++) {
    $service->requestBinding(4, 'probe' . $i . '@example.test');
}
$error = expectApiError(static fn() => $service->requestBinding(4, 'probe-next@example.test'), 'rate_limited', 429, 'per-user creation limit');
if ((int) ($error->details()['retry_after'] ?? 0) <= 0 || !str_contains((string) ($error->details()['message'] ?? ''), 'Too many')) {
    fail('the creation limit explains when to retry');
}

// ... and per target email across panel users.
$pdo->exec('DELETE FROM auth_rate_limits');
$users = [5, 6, 7, 5, 6];
foreach ($users as $userId) {
    $service->requestBinding($userId, 'victim@example.test');
}
expectApiError(static fn() => $service->requestBinding(7, 'victim@example.test'), 'rate_limited', 429, 'per-email creation limit');
$service->requestBinding(7, 'someone-else@example.test'); // other emails unaffected

// Window reset: once the hour is over the tenant may ask again.
$pdo->exec('UPDATE auth_rate_limits SET expires_at = ' . (time() - 1));
$service->requestBinding(7, 'victim@example.test');

expectApiError(static fn() => $service->requestBinding(7, 'not-an-email'), 'whmcs_binding_email_invalid', 422, 'malformed emails are refused before the lookup');

@unlink($envPath);
echo "ok\n";
