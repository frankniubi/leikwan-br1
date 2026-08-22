<?php
declare(strict_types=1);

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
use Leikwan\Master\Support\OidcClient;

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

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fail($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertThrows(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        assertTrue(!str_contains(strtolower($error->getMessage()), 'super-secret'), 'exceptions must not expose client secret');
        return;
    }
    fail($message);
}

function assertThrowsWithMessage(callable $callback, string $expectedMessage, string $message): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        assertSame($expectedMessage, $error->getMessage(), $message);
        return;
    }
    fail($message . ': no exception');
}

function base64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function signedJwt(array $claims, OpenSSLAsymmetricKey $privateKey): string
{
    $header = base64UrlEncode(json_encode(['alg' => 'RS256', 'kid' => 'test-kid', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
    $payload = base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR));
    $input = $header . '.' . $payload;
    if (!openssl_sign($input, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        fail('failed to sign test JWT');
    }
    return $input . '.' . base64UrlEncode($signature);
}

$envPath = tempnam(sys_get_temp_dir(), 'lw-oidc-');
if ($envPath === false) {
    fail('failed to create config file');
}
file_put_contents($envPath, implode("\n", [
    'OIDC_ENABLED=true',
    'OIDC_PROVIDER_NAME=Example Identity',
    'OIDC_ISSUER_URL=https://issuer.example',
    'OIDC_DISCOVERY_URL=https://issuer.example/.well-known/openid-configuration',
    'OIDC_USERINFO_URL=https://issuer.example/userinfo-adapter',
    'OIDC_CLIENT_ID=client-123',
    'OIDC_CLIENT_SECRET=super-secret',
    'OIDC_REDIRECT_URI=https://panel.example/auth/oidc/callback',
    'OIDC_SCOPES=openid email profile',
    'OIDC_TOKEN_AUTH_METHOD=client_secret_basic',
    'OIDC_USE_PKCE=true',
    'OIDC_ALLOWED_SIGNING_ALGS=RS256',
    'OIDC_CLOCK_SKEW_SECONDS=30',
    'OIDC_REQUIRE_EMAIL_VERIFIED=true',
]));

$privateKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
if (!$privateKey instanceof OpenSSLAsymmetricKey) {
    fail('failed to generate RSA key');
}
$details = openssl_pkey_get_details($privateKey);
if (!is_array($details) || !isset($details['rsa']['n'], $details['rsa']['e'])) {
    fail('failed to read RSA key details');
}
$now = 1_700_000_000;
$flow = [
    'state' => 'state-value',
    'nonce' => 'nonce-value',
    'code_verifier' => 'verifier-value',
];
$idToken = signedJwt([
    'iss' => 'https://issuer.example',
    'sub' => 'subject-123',
    'aud' => ['client-123', 'another-audience'],
    'azp' => 'client-123',
    'exp' => $now + 300,
    'nbf' => $now - 1,
    'iat' => $now - 1,
    'nonce' => 'nonce-value',
    'email' => 'Person@Example.COM',
    'email_verified' => true,
    'name' => 'Example Person',
], $privateKey);

$calls = [];
$userinfoPayload = [
    'sub' => 'subject-123',
    'email' => 'Person@Example.COM',
    'email_verified' => true,
    'name' => 'Example Person',
];
$httpClient = static function (string $method, string $url, array $options) use (&$calls, &$userinfoPayload, &$idToken, $details): array {
    $calls[] = [$method, $url, $options];
    if ($url === 'https://issuer.example/.well-known/openid-configuration') {
        return ['status' => 200, 'body' => json_encode([
            'issuer' => 'https://issuer.example',
            'authorization_endpoint' => 'https://issuer.example/authorize',
            'token_endpoint' => 'https://issuer.example/token',
            'jwks_uri' => 'https://issuer.example/keys',
            'userinfo_endpoint' => 'https://issuer.example/userinfo',
        ], JSON_THROW_ON_ERROR)];
    }
    if ($url === 'https://issuer.example/token') {
        assertSame('POST', $method, 'token request method');
        assertSame('authorization_code', $options['form']['grant_type'] ?? null, 'token request grant type');
        assertSame('verifier-value', $options['form']['code_verifier'] ?? null, 'token request PKCE verifier');
        assertSame('Basic ' . base64_encode('client-123:super-secret'), $options['headers']['Authorization'] ?? null, 'basic token authorization');
        return ['status' => 200, 'body' => json_encode(['access_token' => 'access-token', 'id_token' => $idToken], JSON_THROW_ON_ERROR)];
    }
    if ($url === 'https://issuer.example/keys') {
        return ['status' => 200, 'body' => json_encode(['keys' => [[
            'kty' => 'RSA',
            'kid' => 'test-kid',
            'n' => base64UrlEncode($details['rsa']['n']),
            'e' => base64UrlEncode($details['rsa']['e']),
        ]]], JSON_THROW_ON_ERROR)];
    }
    if ($url === 'https://issuer.example/userinfo-adapter') {
        assertSame('Bearer access-token', $options['headers']['Authorization'] ?? null, 'userinfo bearer token');
        return ['status' => 200, 'body' => json_encode($userinfoPayload, JSON_THROW_ON_ERROR)];
    }
    fail('unexpected HTTP request to ' . $url);
};

$client = new OidcClient(new Config($envPath), $httpClient, static function () use ($now): int {
    return $now;
});
assertTrue($client->isConfigured(), 'configured OIDC client must report configured');
assertSame('Example Identity', $client->providerName(), 'provider name');
assertTrue($client->usesPkce(), 'PKCE flag');

$authorizationUrl = $client->authorizationUrl($flow + ['code_challenge' => 'challenge-value']);
$query = [];
parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $query);
assertSame('client-123', $query['client_id'] ?? null, 'authorization URL client ID');
assertSame('state-value', $query['state'] ?? null, 'authorization URL state');
assertSame('nonce-value', $query['nonce'] ?? null, 'authorization URL nonce');
assertSame('challenge-value', $query['code_challenge'] ?? null, 'authorization URL PKCE challenge');
assertSame('S256', $query['code_challenge_method'] ?? null, 'authorization URL PKCE method');

$identity = $client->authenticateCallback('authorization-code', $flow);
assertSame([
    'issuer' => 'https://issuer.example',
    'subject' => 'subject-123',
    'email' => 'person@example.com',
    'email_verified' => true,
    'name' => 'Example Person',
], $identity, 'callback must return normalized verified identity');
assertSame(4, count($calls), 'callback must use discovery, token, JWKS, and userinfo');

// WHMCS emits standards-compliant ID tokens without the optional nbf claim.
$idToken = signedJwt([
    'iss' => 'https://issuer.example',
    'sub' => 'subject-123',
    'aud' => 'client-123',
    'exp' => $now + 300,
    'iat' => $now - 1,
    'nonce' => 'nonce-value',
], $privateKey);
$userinfoPayload = [
    'sub' => 'subject-123',
    'email' => 'Person@Example.COM',
    'email_verified' => true,
    'name' => 'Example Person',
];
$withoutNbf = new OidcClient(new Config($envPath), $httpClient, static fn(): int => $now);
assertSame('subject-123', $withoutNbf->authenticateCallback('authorization-code', $flow)['subject'], 'optional nbf claim may be absent');

$userinfoPayload = ['sub' => 'subject-123'];
$missingUserinfoIdentity = new OidcClient(new Config($envPath), $httpClient, static function () use ($now): int {
    return $now;
});
assertThrows(static fn() => $missingUserinfoIdentity->authenticateCallback('authorization-code', $flow), 'email and verification must come from userinfo, not ID token claims');

$badConfigPath = tempnam(sys_get_temp_dir(), 'lw-oidc-bad-');
if ($badConfigPath === false) {
    fail('failed to create bad config');
}
file_put_contents($badConfigPath, "OIDC_ENABLED=true\nOIDC_ISSUER_URL=https://issuer.example\nOIDC_CLIENT_ID=client-123\nOIDC_CLIENT_SECRET=super-secret\nOIDC_REDIRECT_URI=https://panel.example/callback\nOIDC_TOKEN_AUTH_METHOD=client_secret_basic\n");
$badClient = new OidcClient(new Config($badConfigPath), static function (): array {
    return ['status' => 200, 'body' => json_encode([
        'issuer' => 'https://other-issuer.example',
        'authorization_endpoint' => 'https://issuer.example/authorize',
        'token_endpoint' => 'https://issuer.example/token',
        'jwks_uri' => 'https://issuer.example/keys',
        'userinfo_endpoint' => 'https://issuer.example/userinfo',
    ], JSON_THROW_ON_ERROR)];
});
assertTrue($badClient->isConfigured(), 'mismatched-discovery test client must otherwise be configured');
assertThrows(static fn() => $badClient->authorizationUrl(['state' => 'x', 'nonce' => 'y']), 'mismatched discovery issuer must be rejected');

$missingSecretPath = tempnam(sys_get_temp_dir(), 'lw-oidc-secret-');
if ($missingSecretPath === false) {
    fail('failed to create missing-secret config');
}
file_put_contents($missingSecretPath, "OIDC_ENABLED=true\nOIDC_ISSUER_URL=https://issuer.example\nOIDC_CLIENT_ID=client-123\nOIDC_REDIRECT_URI=https://panel.example/callback\nOIDC_TOKEN_AUTH_METHOD=client_secret_post\n");
$missingSecretClient = new OidcClient(new Config($missingSecretPath));
assertTrue(!$missingSecretClient->isConfigured(), 'confidential OIDC client without a secret is not configured');

$scopeConfigPath = tempnam(sys_get_temp_dir(), 'lw-oidc-scope-');
if ($scopeConfigPath === false) {
    fail('failed to create scope config');
}
file_put_contents($scopeConfigPath, str_replace('OIDC_SCOPES=openid email profile', 'OIDC_SCOPES=email profile', (string) file_get_contents($envPath)));
$scopeClient = new OidcClient(new Config($scopeConfigPath), $httpClient, static fn(): int => $now);
$scopeQuery = [];
parse_str((string) parse_url($scopeClient->authorizationUrl($flow + ['code_challenge' => 'challenge-value']), PHP_URL_QUERY), $scopeQuery);
assertTrue(in_array('openid', preg_split('/\s+/', (string) ($scopeQuery['scope'] ?? '')) ?: [], true), 'authorization scope must always include openid');

$invalidUserinfoPath = tempnam(sys_get_temp_dir(), 'lw-oidc-userinfo-');
if ($invalidUserinfoPath === false) {
    fail('failed to create userinfo override config');
}
file_put_contents(
    $invalidUserinfoPath,
    str_replace('OIDC_USERINFO_URL=https://issuer.example/userinfo-adapter', 'OIDC_USERINFO_URL=http://issuer.example/userinfo', (string) file_get_contents($envPath))
);
$invalidUserinfoClient = new OidcClient(new Config($invalidUserinfoPath));
assertTrue(!$invalidUserinfoClient->isConfigured(), 'userinfo override must use HTTPS');

$stageConfigPath = tempnam(sys_get_temp_dir(), 'lw-oidc-stage-');
if ($stageConfigPath === false) {
    fail('failed to create stage config');
}
file_put_contents($stageConfigPath, implode("\n", [
    'OIDC_ENABLED=true',
    'OIDC_ISSUER_URL=https://issuer.example',
    'OIDC_DISCOVERY_URL=https://issuer.example/.well-known/openid-configuration',
    'OIDC_CLIENT_ID=client-123',
    'OIDC_CLIENT_SECRET=super-secret',
    'OIDC_REDIRECT_URI=https://panel.example/callback',
    'OIDC_TOKEN_AUTH_METHOD=client_secret_post',
    'OIDC_USE_PKCE=false',
]) . "\n");
$stageClient = new OidcClient(new Config($stageConfigPath), static function (string $method, string $url): array {
    if ($url === 'https://issuer.example/.well-known/openid-configuration') {
        return ['status' => 200, 'body' => json_encode([
            'issuer' => 'https://issuer.example',
            'authorization_endpoint' => 'https://issuer.example/authorize',
            'token_endpoint' => 'https://issuer.example/token',
            'jwks_uri' => 'https://issuer.example/keys',
            'userinfo_endpoint' => 'https://issuer.example/userinfo',
        ], JSON_THROW_ON_ERROR)];
    }
    if ($url === 'https://issuer.example/token') {
        return ['status' => 503, 'body' => '{}'];
    }
    fail('unexpected staged HTTP request to ' . $url);
});
assertThrowsWithMessage(
    static fn() => $stageClient->authenticateCallback('authorization-code', ['nonce' => 'nonce-value']),
    'oidc_token_request_failed',
    'token transport failures must identify the failing OIDC stage'
);

$clientSource = (string) file_get_contents(__DIR__ . '/../src/Support/OidcClient.php');
assertTrue(str_contains($clientSource, "'follow_location' => 0"), 'stream HTTP fallback must reject redirects');

@unlink($envPath);
@unlink($badConfigPath);
@unlink($missingSecretPath);
@unlink($scopeConfigPath);
@unlink($invalidUserinfoPath);
@unlink($stageConfigPath);
echo "ok\n";
