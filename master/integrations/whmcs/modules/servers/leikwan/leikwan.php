<?php
/**
 * LeiKwan panel provisioning module for WHMCS.
 *
 * Install: copy this folder to <whmcs>/modules/servers/leikwan/.
 * Server (Setup -> Products/Services -> Servers):
 *   Module      = LeiKwan Panel
 *   Hostname    = panel host, e.g. panel.example.com (or a full https:// URL)
 *   Access Hash = the panel's WHMCS_WEBHOOK_TOKEN (Password is used if empty)
 *   Secure      = ticked (HTTPS)
 * Product -> Module Settings: pick the panel plan and "Automatically setup the
 * product as soon as the first payment is received".
 *
 * Flow: order paid -> CreateAccount -> panel creates/binds the tenant keyed by
 * the WHMCS client id and creates the service -> checkout-complete hook and
 * the client area send the customer through ServiceSingleSignOn into the panel.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

const LEIKWAN_HTTP_TIMEOUT = 20;

function leikwan_MetaData()
{
    return [
        'DisplayName' => 'LeiKwan Panel',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
        'DefaultNonSSLPort' => '80',
        'DefaultSSLPort' => '443',
        'ServiceSingleSignOnLabel' => 'Open LeiKwan Panel',
    ];
}

/**
 * configoption1..4, in this order. Traffic always comes from the panel plan.
 */
function leikwan_ConfigOptions()
{
    return [
        'Panel Plan' => [
            'Type' => 'text',
            'Size' => '10',
            'Loader' => 'leikwan_LoadPlans',
            'SimpleMode' => true,
            'Description' => 'Panel service plan (service_plans.id)',
        ],
        'Bandwidth (Mbps)' => [
            'Type' => 'text',
            'Size' => '6',
            'Default' => '',
            'SimpleMode' => true,
            'Description' => 'Optional override; empty = plan default',
        ],
        'Expiry grace (days)' => [
            'Type' => 'text',
            'Size' => '4',
            'Default' => '3',
            'SimpleMode' => true,
            'Description' => 'Panel expiry = next due date + grace. WHMCS suspends overdue services itself.',
        ],
        'Redirect after checkout' => [
            'Type' => 'yesno',
            'Default' => 'on',
            'SimpleMode' => true,
            'Description' => 'Send the customer straight into the panel when the order completes',
        ],
    ];
}

function leikwan_LoadPlans(array $params)
{
    $result = leikwan_call($params, 'GET', '/api/whmcs/plans');
    $plans = [];
    foreach ((array) ($result['plans'] ?? []) as $plan) {
        $plans[(string) $plan['id']] = sprintf('#%d %s (%d Mbps / %d GB)', $plan['id'], $plan['name'], $plan['bandwidth_mbps'], $plan['traffic_gb']);
    }
    if ($plans === []) {
        throw new Exception('The panel has no active plans.');
    }

    return $plans;
}

function leikwan_TestConnection(array $params)
{
    try {
        leikwan_call($params, 'GET', '/api/whmcs/ping');
        return ['success' => true, 'error' => ''];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function leikwan_CreateAccount(array $params)
{
    return leikwan_run($params, function () use ($params) {
        $payload = leikwan_servicePayload($params, 'active');
        $payload['expire_at'] = leikwan_expireAt($params, true);
        $payload['initial_password'] = (string) ($params['password'] ?? '');
        leikwan_call($params, 'POST', '/api/whmcs/provision', $payload);
        Capsule::table('tblhosting')->where('id', (int) $params['serviceid'])->update(['username' => $payload['user_email']]);
    });
}

function leikwan_ChangePackage(array $params)
{
    return leikwan_run($params, function () use ($params) {
        $status = strtolower((string) ($params['status'] ?? 'Active')) === 'suspended' ? 'suspended' : 'active';
        leikwan_call($params, 'POST', '/api/whmcs/provision', leikwan_servicePayload($params, $status));
    });
}

function leikwan_Renew(array $params)
{
    return leikwan_run($params, function () use ($params) {
        leikwan_call($params, 'POST', '/api/whmcs/renew', [
            'whmcs_service_id' => (int) $params['serviceid'],
            'expire_at' => leikwan_expireAt($params),
        ]);
    });
}

function leikwan_SuspendAccount(array $params)
{
    return leikwan_run($params, function () use ($params) {
        $reason = trim((string) ($params['suspendreason'] ?? ''));
        leikwan_call($params, 'POST', '/api/whmcs/suspend', [
            'whmcs_service_id' => (int) $params['serviceid'],
            'reason' => $reason !== '' ? $reason : 'billing',
        ]);
    });
}

function leikwan_UnsuspendAccount(array $params)
{
    return leikwan_run($params, function () use ($params) {
        leikwan_call($params, 'POST', '/api/whmcs/unsuspend', [
            'whmcs_service_id' => (int) $params['serviceid'],
            'expire_at' => leikwan_expireAt($params),
        ]);
    });
}

function leikwan_TerminateAccount(array $params)
{
    return leikwan_run($params, function () use ($params) {
        leikwan_call($params, 'POST', '/api/whmcs/terminate', [
            'whmcs_service_id' => (int) $params['serviceid'],
        ]);
    });
}

function leikwan_ServiceSingleSignOn(array $params)
{
    try {
        $result = leikwan_call($params, 'POST', '/api/whmcs/sso', [
            'whmcs_service_id' => (int) $params['serviceid'],
            'whmcs_client_id' => (int) $params['userid'],
        ]);
        $url = (string) ($result['url'] ?? '');
        if (!preg_match('#^https?://#i', $url)) {
            throw new Exception('The panel returned no sign-in URL (set APP_URL on the panel).');
        }

        return ['success' => true, 'redirectTo' => $url];
    } catch (Exception $e) {
        return ['success' => false, 'errorMsg' => $e->getMessage()];
    }
}

function leikwan_ClientArea(array $params)
{
    return [
        'tabOverviewModuleOutputTemplate' => 'templates/clientarea.tpl',
        'templateVariables' => [
            'leikwanSsoUrl' => 'clientarea.php?action=productdetails&id=' . (int) $params['serviceid'] . '&dosinglesignon=1',
            'leikwanActive' => strtolower((string) ($params['status'] ?? '')) === 'active',
            'leikwanLabel' => leikwan_t('open_panel'),
        ],
    ];
}

// ---------------------------------------------------------------------------

/**
 * @return array<string, mixed>
 */
function leikwan_servicePayload(array $params, $status)
{
    $client = (array) ($params['clientsdetails'] ?? []);
    $email = strtolower(trim((string) ($client['email'] ?? '')));
    if ($email === '') {
        throw new Exception('The client has no email address.');
    }
    $name = trim(((string) ($client['firstname'] ?? '')) . ' ' . ((string) ($client['lastname'] ?? '')));
    if ($name === '') {
        $name = trim((string) ($client['companyname'] ?? '')) ?: $email;
    }
    $planId = (int) ($params['configoption1'] ?? 0);
    if ($planId <= 0) {
        throw new Exception('Select a panel plan under the product\'s Module Settings.');
    }
    $productName = (string) (Capsule::table('tblproducts')->where('id', (int) $params['pid'])->value('name') ?? 'Service');

    $payload = [
        'whmcs_service_id' => (int) $params['serviceid'],
        'whmcs_client_id' => (int) $params['userid'],
        'whmcs_user_id' => (int) ($client['owner_user_id'] ?? 0),
        'user_email' => $email,
        'user_name' => $name,
        'plan_id' => $planId,
        'service_name' => $productName . ' #' . (int) $params['serviceid'],
        'expire_at' => leikwan_expireAt($params),
        'status' => $status,
    ];
    $bandwidth = leikwan_configurableBandwidth($params);
    if ($bandwidth <= 0) {
        $bandwidth = (int) ($params['configoption2'] ?? 0);
    }
    if ($bandwidth > 0) {
        $payload['bandwidth_limit_mbps'] = $bandwidth;
    }
    return $payload;
}

/**
 * A configurable option whose name contains "bandwidth" (e.g. "Bandwidth|带宽")
 * overrides the product bandwidth with its numeric value.
 */
function leikwan_configurableBandwidth(array $params)
{
    foreach ((array) ($params['configoptions'] ?? []) as $name => $value) {
        if (stripos((string) $name, 'bandwidth') !== false && is_numeric($value)) {
            return (int) $value;
        }
    }

    return 0;
}

/**
 * Panel expiry (UTC) = WHMCS next due date + grace days. Free and one-time
 * services never expire on the panel side; WHMCS suspension still applies.
 */
function leikwan_expireAt(array $params, $firstTerm = false)
{
    $row = Capsule::table('tblhosting')->where('id', (int) $params['serviceid'])->first(['nextduedate', 'regdate', 'billingcycle']);
    $cycle = strtolower((string) ($row->billingcycle ?? ''));
    $due = (string) ($row->nextduedate ?? '');
    if (in_array($cycle, ['free account', 'free', 'one time', 'onetime'], true) || $due === '' || strpos($due, '0000') === 0) {
        return '2099-12-31 23:59:59';
    }
    $graceOption = trim((string) ($params['configoption3'] ?? ''));
    $grace = $graceOption === '' ? 3 : max(0, (int) $graceOption);
    $utc = new DateTimeZone('UTC');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr($due, 0, 10), $utc);
    if ($date === false) {
        throw new Exception('Invalid next due date: ' . $due);
    }
    // On a new order the due date may not have moved past the registration
    // date yet; the customer has still bought one full term.
    $regdate = DateTimeImmutable::createFromFormat('!Y-m-d', substr((string) ($row->regdate ?? ''), 0, 10), $utc);
    $months = ['monthly' => 1, 'quarterly' => 3, 'semi-annually' => 6, 'annually' => 12, 'biennially' => 24, 'triennially' => 36][$cycle] ?? 0;
    if ($firstTerm && $regdate !== false && $months > 0 && $date <= $regdate) {
        $date = $regdate->modify('+' . $months . ' months');
    }

    return $date->modify('+' . $grace . ' days')->format('Y-m-d') . ' 23:59:59';
}

function leikwan_run(array $params, callable $action)
{
    try {
        $action();
        return 'success';
    } catch (Exception $e) {
        return $e->getMessage();
    }
}

function leikwan_baseUrl(array $params)
{
    $host = trim((string) ($params['serverhostname'] ?? ''));
    if ($host === '') {
        $host = trim((string) ($params['serverip'] ?? ''));
    }
    if ($host === '') {
        throw new Exception('The server has no hostname.');
    }
    if (preg_match('#^https?://#i', $host)) {
        return rtrim($host, '/');
    }
    $secure = !empty($params['serversecure']) && $params['serversecure'] !== 'off';
    $port = (int) ($params['serverport'] ?? 0);
    $url = ($secure ? 'https://' : 'http://') . $host;
    if ($port > 0 && $port !== ($secure ? 443 : 80)) {
        $url .= ':' . $port;
    }

    return $url;
}

function leikwan_token(array $params)
{
    $token = trim((string) ($params['serveraccesshash'] ?? ''));
    if ($token === '') {
        $token = trim((string) ($params['serverpassword'] ?? ''));
    }
    if ($token === '') {
        throw new Exception('Set the panel WHMCS_WEBHOOK_TOKEN as the server Access Hash.');
    }

    return $token;
}

/**
 * @return array<string, mixed>
 */
function leikwan_call(array $params, $method, $path, array $body = [])
{
    $url = leikwan_baseUrl($params) . $path;
    $token = leikwan_token($params);
    $loggedBody = $body;
    unset($loggedBody['initial_password']);

    $ch = curl_init($url);
    $headers = ['Accept: application/json', 'X-Whmcs-Webhook-Token: ' . $token];
    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => LEIKWAN_HTTP_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    $logged = is_array($decoded) && isset($decoded['url']) ? array_merge($decoded, ['url' => '(sso link)']) : ($decoded ?? $raw);
    logModuleCall('leikwan', $method . ' ' . $path, $loggedBody, $raw === false ? $curlError : $raw, $logged, [$token]);

    if ($raw === false) {
        throw new Exception('Panel unreachable: ' . $curlError);
    }
    if (!is_array($decoded)) {
        throw new Exception('Panel returned HTTP ' . $status . ' with a non-JSON body.');
    }
    if ($status >= 400 || empty($decoded['ok'])) {
        $error = $decoded['error'] ?? null;
        $code = is_array($error) ? (string) ($error['code'] ?? '') : (string) $error;
        $message = is_array($error) ? (string) ($error['message'] ?? '') : (string) ($decoded['message'] ?? '');
        $code = $code !== '' ? $code : 'http_' . $status;
        throw new Exception('Panel error ' . $code . ($message !== '' ? ': ' . $message : ''));
    }

    return $decoded;
}

function leikwan_t($key)
{
    $zh = stripos((string) ($_SESSION['Language'] ?? ''), 'chinese') === 0;
    $strings = [
        'open_panel' => $zh ? '进入控制面板' : 'Open control panel',
    ];

    return $strings[$key] ?? $key;
}
