<?php
declare(strict_types=1);

use WHMCS\Database\Capsule;

require dirname(__DIR__) . '/init.php';

header('Cache-Control: no-store');
header('Pragma: no-cache');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/** @param array<string, mixed> $body */
function v3OidcRespond(int $status, array $body): never
{
    http_response_code($status);
    $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    echo is_string($json) ? $json : '{"error":"server_error"}';
    exit;
}

function v3OidcAuthorizationHeader(): string
{
    $authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if ($authorization === '' && function_exists('getallheaders')) {
        foreach ((array) getallheaders() as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') === 0) {
                $authorization = trim((string) $value);
                break;
            }
        }
    }

    if (
        strlen($authorization) > 4096
        || preg_match('/\ABearer[ \t]+[^\s]+\z/i', $authorization) !== 1
    ) {
        v3OidcRespond(401, ['error' => 'invalid_token']);
    }

    return $authorization;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        header('Allow: GET');
        v3OidcRespond(405, ['error' => 'method_not_allowed']);
    }

    $authorization = v3OidcAuthorizationHeader();
    $systemUrl = rtrim((string) WHMCS\Config\Setting::getValue('SystemURL'), '/');
    $parts = parse_url($systemUrl);
    if (
        !is_array($parts)
        || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
        || !is_string($parts['host'] ?? null)
        || $parts['host'] === ''
    ) {
        throw new RuntimeException('invalid_system_url');
    }

    $host = (string) $parts['host'];
    $port = max(1, (int) ($parts['port'] ?? 443));
    $upstream = $systemUrl . '/oauth/userinfo.php';
    $handle = curl_init($upstream);
    if ($handle === false) {
        throw new RuntimeException('curl_init_failed');
    }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: ' . $authorization,
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_RESOLVE => [$host . ':' . $port . ':127.0.0.1'],
    ]);
    $raw = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $curlError = curl_errno($handle);
    curl_close($handle);
    if (!is_string($raw) || $curlError !== 0) {
        throw new RuntimeException('upstream_request_failed');
    }

    $userinfo = json_decode($raw, true);
    if ($status !== 200 || !is_array($userinfo)) {
        v3OidcRespond($status >= 400 && $status < 500 ? $status : 502, ['error' => 'invalid_token']);
    }

    $userinfo['email_verified'] = false;
    $subject = is_string($userinfo['sub'] ?? null) ? strtolower(trim($userinfo['sub'])) : '';
    $email = is_string($userinfo['email'] ?? null) ? strtolower(trim($userinfo['email'])) : '';
    if (
        preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $subject) === 1
        && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
    ) {
        $client = Capsule::table('tblclients')->where('uuid', $subject)->first();
        if (is_object($client) && (int) ($client->id ?? 0) > 0) {
            $ownerId = (int) Capsule::table('tblusers_clients')
                ->where('client_id', (int) $client->id)
                ->where('owner', 1)
                ->value('auth_user_id');
            $clientModel = WHMCS\User\Client::find((int) $client->id);
            $owner = $ownerId > 0 ? WHMCS\User\User::find($ownerId) : null;
            $clientEmail = strtolower(trim((string) ($client->email ?? '')));
            $ownerEmail = is_object($owner) ? strtolower(trim((string) ($owner->email ?? ''))) : '';
            if (
                is_object($clientModel)
                && is_object($owner)
                && $clientEmail !== ''
                && $ownerEmail !== ''
                && hash_equals($clientEmail, $email)
                && hash_equals($ownerEmail, $email)
                && $clientModel->isEmailAddressVerified()
                && $owner->emailVerified()
            ) {
                $userinfo['email_verified'] = true;
            }
        }
    }

    v3OidcRespond(200, $userinfo);
} catch (Throwable $throwable) {
    error_log(sprintf('V3 OIDC userinfo adapter failed: %s (%s)', $throwable->getMessage(), $throwable::class));
    v3OidcRespond(502, ['error' => 'server_error']);
}
