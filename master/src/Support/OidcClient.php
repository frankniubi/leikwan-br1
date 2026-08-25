<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Config;
use RuntimeException;
use Throwable;

final class OidcClient
{
    private Config $config;

    /** @var callable(string, string, array{headers?:array<string,string>,form?:array<string,string>}):array{status:int,headers?:array<string,string|list<string>>,body:string|array<string,mixed>} */
    private $httpClient;

    /** @var callable():int */
    private $clock;

    /** @var array<string, mixed>|null */
    private ?array $metadata = null;

    /**
     * The injected HTTP client receives method, URL, and request options. It must return a
     * response array with integer `status`, optional headers, and a JSON `body` string or decoded array.
     * It must not throw provider response bodies into exception text.
     *
     * @param callable(string, string, array{headers?:array<string,string>,form?:array<string,string>}):array{status:int,headers?:array<string,string|list<string>>,body:string|array<string,mixed>}|null $httpClient
     * @param callable():int|null $clock
     */
    public function __construct(Config $config, ?callable $httpClient = null, ?callable $clock = null)
    {
        $this->config = $config;
        $this->httpClient = $httpClient ?? fn(string $method, string $url, array $options): array => $this->defaultHttpClient($method, $url, $options);
        $this->clock = $clock ?? static fn(): int => time();
    }

    public function isConfigured(): bool
    {
        $tokenAuthMethod = strtolower((string) $this->config->get('OIDC_TOKEN_AUTH_METHOD', 'client_secret_basic'));
        $userinfoUrl = trim((string) $this->config->get('OIDC_USERINFO_URL', ''));
        try {
            $this->configuredMetadata();
        } catch (RuntimeException) {
            return false;
        }
        return $this->config->getBool('OIDC_ENABLED')
            && $this->httpsUrl($this->issuer())
            && $this->httpsUrl((string) $this->config->get('OIDC_REDIRECT_URI', ''))
            && $this->clientId() !== ''
            && trim((string) $this->config->get('OIDC_CLIENT_SECRET', '')) !== ''
            && ($userinfoUrl === '' || $this->httpsUrl($userinfoUrl))
            && in_array($tokenAuthMethod, ['client_secret_basic', 'client_secret_post'], true);
    }

    public function providerName(): string
    {
        $name = trim((string) $this->config->get('OIDC_PROVIDER_NAME', 'OpenID Connect'));
        return $name === '' ? 'OpenID Connect' : $name;
    }

    public function usesPkce(): bool
    {
        return $this->config->getBool('OIDC_USE_PKCE', true);
    }

    /** @param array<string, mixed> $flow */
    public function authorizationUrl(array $flow): string
    {
        $metadata = $this->metadata();
        $state = $this->requiredFlowString($flow, 'state');
        $nonce = $this->requiredFlowString($flow, 'nonce');
        $query = [
            'response_type' => 'code',
            'client_id' => $this->clientId(),
            'redirect_uri' => (string) $this->config->get('OIDC_REDIRECT_URI', ''),
            'scope' => $this->scopes(),
            'state' => $state,
            'nonce' => $nonce,
        ];
        if ($this->usesPkce()) {
            $query['code_challenge'] = $this->requiredFlowString($flow, 'code_challenge');
            $query['code_challenge_method'] = 'S256';
        }

        return (string) $metadata['authorization_endpoint'] . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param array<string, mixed> $flow A freshly consumed OIDC session flow.
     * @return array{issuer:string, subject:string, email:string, email_verified:bool, name:string}
     */
    public function authenticateCallback(string $code, array $flow): array
    {
        if ($code === '') {
            throw new RuntimeException('oidc_callback_invalid');
        }
        $metadata = $this->metadata();
        $nonce = $this->requiredFlowString($flow, 'nonce');
        $tokenResponse = $this->tokenResponse((string) $metadata['token_endpoint'], $code, $flow);
        $idToken = $tokenResponse['id_token'] ?? null;
        $accessToken = $tokenResponse['access_token'] ?? null;
        if (!is_string($idToken) || $idToken === '' || !is_string($accessToken) || $accessToken === '') {
            throw new RuntimeException('oidc_callback_invalid');
        }

        $claims = $this->verifyIdToken($idToken, $nonce, (string) $metadata['jwks_uri']);
        $userinfo = $this->userinfo((string) $metadata['userinfo_endpoint'], $accessToken);
        if (!isset($userinfo['sub']) || !is_string($userinfo['sub']) || !hash_equals((string) $claims['sub'], $userinfo['sub'])) {
            throw new RuntimeException('oidc_callback_invalid');
        }

        $email = $userinfo['email'] ?? null;
        $emailVerified = $userinfo['email_verified'] ?? null;
        $name = $userinfo['name'] ?? ($claims['name'] ?? '');
        if (!is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false || !is_bool($emailVerified) || !is_string($name)) {
            throw new RuntimeException('oidc_callback_invalid');
        }
        if ($this->config->getBool('OIDC_REQUIRE_EMAIL_VERIFIED', true) && $emailVerified !== true) {
            throw new RuntimeException('oidc_callback_invalid');
        }

        return [
            'issuer' => $this->issuer(),
            'subject' => (string) $claims['sub'],
            'email' => strtolower($email),
            'email_verified' => $emailVerified,
            'name' => $name,
        ];
    }

    /** @param array<string, mixed> $flow @return array<string, mixed> */
    private function tokenResponse(string $endpoint, string $code, array $flow): array
    {
        $form = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => (string) $this->config->get('OIDC_REDIRECT_URI', ''),
        ];
        if ($this->usesPkce()) {
            $form['code_verifier'] = $this->requiredFlowString($flow, 'code_verifier');
        }

        $headers = ['Accept' => 'application/json'];
        $method = strtolower((string) $this->config->get('OIDC_TOKEN_AUTH_METHOD', 'client_secret_basic'));
        if ($method === 'client_secret_post') {
            $secret = (string) $this->config->get('OIDC_CLIENT_SECRET', '');
            if ($secret === '') {
                throw new RuntimeException('oidc_configuration_invalid');
            }
            $form['client_id'] = $this->clientId();
            $form['client_secret'] = $secret;
        } elseif ($method === 'client_secret_basic') {
            $secret = (string) $this->config->get('OIDC_CLIENT_SECRET', '');
            if ($secret === '') {
                throw new RuntimeException('oidc_configuration_invalid');
            }
            $headers['Authorization'] = 'Basic ' . base64_encode($this->clientId() . ':' . $secret);
        } else {
            throw new RuntimeException('oidc_configuration_invalid');
        }

        return $this->providerJson('oidc_token_request_failed', 'POST', $endpoint, ['headers' => $headers, 'form' => $form]);
    }

    /** @return array<string, mixed> */
    private function verifyIdToken(string $token, string $expectedNonce, string $jwksUri): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new RuntimeException('oidc_callback_invalid');
        }
        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
        $header = $this->jsonObject($this->base64UrlDecode($encodedHeader));
        $claims = $this->jsonObject($this->base64UrlDecode($encodedPayload));
        $signature = $this->base64UrlDecode($encodedSignature);
        if (($header['alg'] ?? null) !== 'RS256' || !is_string($header['kid'] ?? null) || !$this->allowsRs256()) {
            throw new RuntimeException('oidc_callback_invalid');
        }

        $jwks = $this->providerJson('oidc_jwks_request_failed', 'GET', $jwksUri, ['headers' => ['Accept' => 'application/json']]);
        $key = $this->matchingJwk($jwks, $header['kid']);
        $pem = $this->jwkToPem($key);
        if (!function_exists('openssl_verify') || openssl_verify($encodedHeader . '.' . $encodedPayload, $signature, $pem, OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('oidc_callback_invalid');
        }

        $this->validateClaims($claims, $expectedNonce);
        return $claims;
    }

    /** @return array<string, mixed> */
    private function userinfo(string $endpoint, string $accessToken): array
    {
        return $this->providerJson('oidc_userinfo_request_failed', 'GET', $endpoint, ['headers' => [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $accessToken,
        ]]);
    }

    /** @return array<string, mixed> */
    private function metadata(): array
    {
        if ($this->metadata !== null) {
            return $this->metadata;
        }
        if (!$this->isConfigured()) {
            throw new RuntimeException('oidc_configuration_invalid');
        }
        $configured = $this->configuredMetadata();
        if ($configured !== null) {
            return $this->metadata = $configured;
        }
        $discoveryUrl = (string) $this->config->get('OIDC_DISCOVERY_URL', '');
        if ($discoveryUrl === '') {
            $discoveryUrl = rtrim($this->issuer(), '/') . '/.well-known/openid-configuration';
        }
        if (!$this->httpsUrl($discoveryUrl)) {
            throw new RuntimeException('oidc_configuration_invalid');
        }
        $metadata = $this->providerJson('oidc_discovery_request_failed', 'GET', $discoveryUrl, ['headers' => ['Accept' => 'application/json']]);
        if (($metadata['issuer'] ?? null) !== $this->issuer()) {
            throw new RuntimeException('oidc_configuration_invalid');
        }
        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri', 'userinfo_endpoint'] as $field) {
            if (!isset($metadata[$field]) || !is_string($metadata[$field]) || !$this->httpsUrl($metadata[$field])) {
                throw new RuntimeException('oidc_configuration_invalid');
            }
        }
        $userinfoUrl = trim((string) $this->config->get('OIDC_USERINFO_URL', ''));
        if ($userinfoUrl !== '') {
            if (!$this->httpsUrl($userinfoUrl)) {
                throw new RuntimeException('oidc_configuration_invalid');
            }
            $metadata['userinfo_endpoint'] = $userinfoUrl;
        }
        return $this->metadata = $metadata;
    }

    /** @return array<string, mixed>|null */
    private function configuredMetadata(): ?array
    {
        $endpoints = [
            'authorization_endpoint' => trim((string) $this->config->get('OIDC_AUTHORIZATION_URL', '')),
            'token_endpoint' => trim((string) $this->config->get('OIDC_TOKEN_URL', '')),
            'jwks_uri' => trim((string) $this->config->get('OIDC_JWKS_URL', '')),
            'userinfo_endpoint' => trim((string) $this->config->get('OIDC_USERINFO_URL', '')),
        ];
        $explicitCount = count(array_filter([
            $endpoints['authorization_endpoint'],
            $endpoints['token_endpoint'],
            $endpoints['jwks_uri'],
        ], static fn(string $value): bool => $value !== ''));
        if ($explicitCount === 0) {
            $derived = $this->whmcsMetadata($endpoints['userinfo_endpoint']);
            if ($derived === null) {
                return null;
            }
            $endpoints = $derived;
        } elseif ($explicitCount !== 3 || $endpoints['userinfo_endpoint'] === '') {
            throw new RuntimeException('oidc_configuration_invalid');
        }
        foreach ($endpoints as $endpoint) {
            if (!$this->httpsUrl($endpoint)) {
                throw new RuntimeException('oidc_configuration_invalid');
            }
        }
        return ['issuer' => $this->issuer()] + $endpoints;
    }

    /** @return array<string, string>|null */
    private function whmcsMetadata(string $userinfoUrl): ?array
    {
        if ($userinfoUrl === '') {
            return null;
        }
        $issuer = rtrim($this->issuer(), '/');
        $discoveryUrl = trim((string) $this->config->get('OIDC_DISCOVERY_URL', ''));
        $issuerParts = parse_url($issuer);
        $discoveryParts = parse_url($discoveryUrl);
        if (
            !is_array($issuerParts)
            || !is_array($discoveryParts)
            || ($issuerParts['scheme'] ?? null) !== 'https'
            || ($discoveryParts['scheme'] ?? null) !== 'https'
            || !isset($issuerParts['host'], $discoveryParts['host'])
            || strcasecmp((string) $issuerParts['host'], (string) $discoveryParts['host']) !== 0
            || (int) ($issuerParts['port'] ?? 443) !== (int) ($discoveryParts['port'] ?? 443)
            || !in_array((string) ($issuerParts['path'] ?? ''), ['', '/'], true)
            || ($discoveryParts['path'] ?? null) !== '/oauth/openid-configuration.php'
            || isset($issuerParts['query'])
            || isset($issuerParts['fragment'])
            || isset($issuerParts['user'])
            || isset($issuerParts['pass'])
            || isset($discoveryParts['query'])
            || isset($discoveryParts['fragment'])
            || isset($discoveryParts['user'])
            || isset($discoveryParts['pass'])
        ) {
            return null;
        }
        return [
            'authorization_endpoint' => $issuer . '/oauth/authorize.php',
            'token_endpoint' => $issuer . '/oauth/token.php',
            'jwks_uri' => $issuer . '/oauth/certs.php',
            'userinfo_endpoint' => $userinfoUrl,
        ];
    }

    /** @param array<string, mixed> $claims */
    private function validateClaims(array $claims, string $expectedNonce): void
    {
        $now = (int) ($this->clock)();
        $skew = max(0, $this->config->getInt('OIDC_CLOCK_SKEW_SECONDS', 60));
        if (($claims['iss'] ?? null) !== $this->issuer() || !is_string($claims['sub'] ?? null) || $claims['sub'] === '' || !isset($claims['nonce']) || !is_string($claims['nonce']) || !hash_equals($expectedNonce, $claims['nonce'])) {
            throw new RuntimeException('oidc_callback_invalid');
        }
        $audience = $claims['aud'] ?? null;
        $audiences = is_string($audience) ? [$audience] : (is_array($audience) ? $audience : []);
        if (!in_array($this->clientId(), $audiences, true)) {
            throw new RuntimeException('oidc_callback_invalid');
        }
        $azp = $claims['azp'] ?? null;
        if ((count($audiences) > 1 && $azp !== $this->clientId()) || ($azp !== null && $azp !== $this->clientId())) {
            throw new RuntimeException('oidc_callback_invalid');
        }
        foreach (['exp', 'iat'] as $claim) {
            if (!isset($claims[$claim]) || !is_int($claims[$claim]) && !is_float($claims[$claim])) {
                throw new RuntimeException('oidc_callback_invalid');
            }
        }
        if (isset($claims['nbf']) && !is_int($claims['nbf']) && !is_float($claims['nbf'])) {
            throw new RuntimeException('oidc_callback_invalid');
        }
        if (
            $now > (int) $claims['exp'] + $skew
            || (isset($claims['nbf']) && $now + $skew < (int) $claims['nbf'])
            || $now + $skew < (int) $claims['iat']
        ) {
            throw new RuntimeException('oidc_callback_invalid');
        }
    }

    /** @param array<string, mixed> $jwks @return array<string, mixed> */
    private function matchingJwk(array $jwks, string $kid): array
    {
        $keys = $jwks['keys'] ?? null;
        if (!is_array($keys)) {
            throw new RuntimeException('oidc_callback_invalid');
        }
        foreach ($keys as $key) {
            if (is_array($key) && ($key['kid'] ?? null) === $kid && ($key['kty'] ?? null) === 'RSA' && is_string($key['n'] ?? null) && is_string($key['e'] ?? null)) {
                return $key;
            }
        }
        throw new RuntimeException('oidc_callback_invalid');
    }

    /** @param array<string, mixed> $jwk */
    private function jwkToPem(array $jwk): string
    {
        $modulus = $this->base64UrlDecode((string) $jwk['n']);
        $exponent = $this->base64UrlDecode((string) $jwk['e']);
        if ($modulus === '' || $exponent === '') {
            throw new RuntimeException('oidc_callback_invalid');
        }
        $rsa = $this->derSequence($this->derInteger($modulus) . $this->derInteger($exponent));
        $algorithm = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
        $spki = $this->derSequence($algorithm . "\x03" . $this->derLength(strlen($rsa) + 1) . "\x00" . $rsa);
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private function derSequence(string $value): string
    {
        return "\x30" . $this->derLength(strlen($value)) . $value;
    }

    private function derInteger(string $value): string
    {
        $value = ltrim($value, "\x00");
        if ($value === '') {
            $value = "\x00";
        }
        if ((ord($value[0]) & 0x80) !== 0) {
            $value = "\x00" . $value;
        }
        return "\x02" . $this->derLength(strlen($value)) . $value;
    }

    private function derLength(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }
        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xff) . $bytes;
            $length >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private function allowsRs256(): bool
    {
        $configured = trim((string) $this->config->get('OIDC_ALLOWED_SIGNING_ALGS', 'RS256'));
        $algorithms = preg_split('/[\\s,]+/', $configured, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return in_array('RS256', $algorithms, true);
    }

    /** @return array<string, mixed> */
    private function jsonResponse(string $method, string $url, array $options): array
    {
        if (!$this->httpsUrl($url)) {
            throw new RuntimeException('oidc_configuration_invalid');
        }
        $startedAt = microtime(true);
        try {
            $response = ($this->httpClient)($method, $url, $options);
        } catch (Throwable $exception) {
            throw new OidcProviderException(
                'oidc_provider_request_failed',
                $this->providerContext($method, $url, [], $startedAt, [
                    'transport_error' => $exception::class,
                ]),
                $exception
            );
        }
        if (!is_array($response) || !isset($response['status']) || (int) $response['status'] < 200 || (int) $response['status'] >= 300) {
            throw new OidcProviderException(
                'oidc_provider_request_failed',
                $this->providerContext($method, $url, is_array($response) ? $response : [], $startedAt)
            );
        }
        $body = $response['body'] ?? null;
        if (is_array($body)) {
            return $body;
        }
        if (!is_string($body)) {
            throw new RuntimeException('oidc_provider_response_invalid');
        }
        return $this->jsonObject($body);
    }

    /** @return array<string, mixed> */
    private function providerJson(string $failureCode, string $method, string $url, array $options): array
    {
        $normalizedMethod = strtoupper($method);
        // A DNS lookup can fail before a POST reaches the provider. Retry only that
        // pre-request transport failure; never replay an authorization code after an
        // HTTP response or an ambiguous connection timeout.
        $maxAttempts = 2;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return $this->jsonResponse($method, $url, $options);
            } catch (OidcProviderException $exception) {
                $context = array_merge($exception->context(), ['attempts' => $attempt]);
                if ($attempt < $maxAttempts && $this->isRetryableProviderContext($context, $normalizedMethod)) {
                    usleep(100_000);
                    continue;
                }
                throw new OidcProviderException($failureCode, $context, $exception);
            } catch (RuntimeException $exception) {
                if ($exception->getMessage() === 'oidc_provider_request_failed') {
                    throw new OidcProviderException($failureCode, ['attempts' => $attempt], $exception);
                }
                throw $exception;
            }
        }

        throw new OidcProviderException($failureCode, ['attempts' => $maxAttempts]);
    }

    /** @param array<string, mixed> $context */
    private function isRetryableProviderContext(array $context, string $method): bool
    {
        $status = (int) ($context['status'] ?? 0);
        if ($status === 0) {
            if ($method === 'GET') {
                return true;
            }
            $curlErrno = (int) ($context['curl_errno'] ?? 0);
            $curlError = (string) ($context['curl_error'] ?? '');
            return in_array($curlErrno, [CURLE_COULDNT_RESOLVE_HOST, CURLE_OPERATION_TIMEDOUT], true)
                && preg_match('/\bresolve|resolv/i', $curlError) === 1;
        }
        if ($method !== 'GET') {
            return false;
        }
        return $status === 408 || $status === 425 || $status === 429 || $status >= 500;
    }

    /** @param array<string, mixed> $response @param array<string, int|string> $extra @return array<string, int|string> */
    private function providerContext(string $method, string $url, array $response, float $startedAt, array $extra = []): array
    {
        $parts = parse_url($url);
        $context = [
            'method' => strtoupper($method),
            'host' => is_array($parts) && isset($parts['host']) ? (string) $parts['host'] : '',
            'status' => (int) ($response['status'] ?? 0),
            'duration_ms' => max(0, (int) round((microtime(true) - $startedAt) * 1000)),
        ];
        $meta = $response['_meta'] ?? null;
        if (is_array($meta)) {
            foreach (['curl_errno', 'curl_error'] as $key) {
                if (isset($meta[$key]) && (is_int($meta[$key]) || is_string($meta[$key]))) {
                    $context[$key] = $meta[$key];
                }
            }
        }
        $body = $response['body'] ?? null;
        $providerError = is_array($body) ? ($body['error'] ?? null) : null;
        if ($providerError === null && is_string($body) && strlen($body) <= 4096) {
            $decoded = json_decode($body, true);
            $providerError = is_array($decoded) ? ($decoded['error'] ?? null) : null;
        }
        if (is_string($providerError) && preg_match('/^[A-Za-z0-9._-]{1,64}$/', $providerError) === 1) {
            $context['provider_error'] = $providerError;
        }
        return array_merge($context, $extra);
    }

    /** @return array<string, mixed> */
    private function jsonObject(string $body): array
    {
        try {
            $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('oidc_provider_response_invalid');
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('oidc_provider_response_invalid');
        }
        return $decoded;
    }

    private function base64UrlDecode(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            throw new RuntimeException('oidc_callback_invalid');
        }
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        if ($decoded === false) {
            throw new RuntimeException('oidc_callback_invalid');
        }
        return $decoded;
    }

    /** @param array<string, mixed> $flow */
    private function requiredFlowString(array $flow, string $key): string
    {
        $value = $flow[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('oidc_callback_invalid');
        }
        return $value;
    }

    private function issuer(): string
    {
        return (string) $this->config->get('OIDC_ISSUER_URL', '');
    }

    private function clientId(): string
    {
        return (string) $this->config->get('OIDC_CLIENT_ID', '');
    }

    private function scopes(): string
    {
        $configured = trim((string) $this->config->get('OIDC_SCOPES', 'openid email profile'));
        $scopes = preg_split('/\s+/', $configured) ?: [];
        $scopes = array_values(array_unique(array_filter($scopes, static fn(string $scope): bool => $scope !== '')));
        if (!in_array('openid', $scopes, true)) {
            array_unshift($scopes, 'openid');
        }
        return implode(' ', $scopes);
    }

    private function httpsUrl(string $url): bool
    {
        $parts = parse_url($url);
        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && isset($parts['host'])
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['fragment']);
    }

    /** @return array{status:int,headers:array<string,list<string>>,body:string,_meta?:array<string,int|string>} */
    private function defaultHttpClient(string $method, string $url, array $options): array
    {
        $headers = $options['headers'] ?? [];
        $body = isset($options['form']) ? http_build_query($options['form'], '', '&', PHP_QUERY_RFC3986) : '';
        if ($body !== '') {
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        }
        if (function_exists('curl_init')) {
            $handle = curl_init($url);
            if ($handle === false) {
                throw new RuntimeException('oidc_provider_request_failed');
            }
            $headerLines = [];
            foreach ($headers as $name => $value) {
                $headerLines[] = $name . ': ' . $value;
            }
            curl_setopt_array($handle, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headerLines,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                CURLOPT_POSTFIELDS => $method === 'POST' ? $body : null,
            ]);
            $responseBody = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $curlError = curl_errno($handle);
            $curlErrorMessage = curl_error($handle);
            curl_close($handle);
            return [
                'status' => $status,
                'headers' => [],
                'body' => is_string($responseBody) ? $responseBody : '',
                '_meta' => array_filter([
                    'curl_errno' => $curlError,
                    'curl_error' => $this->safeTransportError($curlErrorMessage),
                ], static fn(mixed $value): bool => $value !== '' && $value !== null),
            ];
        }

        $headerLines = '';
        foreach ($headers as $name => $value) {
            $headerLines .= $name . ': ' . $value . "\r\n";
        }
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => $headerLines,
            'content' => $body,
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
            'timeout' => 15,
        ]]);
        $responseBody = @file_get_contents($url, false, $context);
        $statusLine = $http_response_header[0] ?? '';
        if (!is_string($responseBody) || preg_match('/\\s(\\d{3})\\s/', $statusLine, $matches) !== 1) {
            throw new RuntimeException('oidc_provider_request_failed');
        }
        return ['status' => (int) $matches[1], 'headers' => [], 'body' => $responseBody];
    }

    private function safeTransportError(string $error): string
    {
        $error = trim($error);
        if ($error === '') {
            return '';
        }
        $error = preg_replace('/https?:\/\/\S+/i', '[url]', $error) ?? $error;
        return substr($error, 0, 160);
    }
}
