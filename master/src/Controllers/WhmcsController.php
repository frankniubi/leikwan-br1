<?php
declare(strict_types=1);

namespace Leikwan\Master\Controllers;

use Leikwan\Master\Http\Request;
use Leikwan\Master\Http\Response;
use Leikwan\Master\Support\Auth;
use Leikwan\Master\Support\ProvisioningService;
use Leikwan\Master\Support\SessionAuth;
use Leikwan\Master\Support\WhmcsSsoService;

final class WhmcsController extends BaseController
{
    private WhmcsSsoService $sso;

    public function __construct(
        \Leikwan\Master\Config $config,
        \Leikwan\Master\Database\Database $db,
        private ProvisioningService $provisioning,
        ?WhmcsSsoService $sso = null,
    ) {
        parent::__construct($config, $db);
        $this->sso = $sso ?? new WhmcsSsoService($db);
    }

    public function ping(Request $request, array $params): Response
    {
        Auth::requireWhmcsWebhook($request, $this->config);
        return $this->json(['ok' => true, 'service' => 'leikwan-panel']);
    }

    public function plans(Request $request, array $params): Response
    {
        Auth::requireWhmcsWebhook($request, $this->config);
        return $this->json(['ok' => true, 'plans' => $this->provisioning->listPlansForWhmcs()]);
    }

    public function provision(Request $request, array $params): Response
    {
        Auth::requireWhmcsWebhook($request, $this->config);
        return $this->json([
            'ok' => true,
            'service' => $this->provisioning->createOrUpdateServiceFromWhmcs($request->body()),
        ]);
    }

    public function renew(Request $request, array $params): Response
    {
        Auth::requireWhmcsWebhook($request, $this->config);
        return $this->json([
            'ok' => true,
            'service' => $this->provisioning->renewService($request->body()),
        ]);
    }

    public function suspend(Request $request, array $params): Response
    {
        Auth::requireWhmcsWebhook($request, $this->config);
        return $this->json([
            'ok' => true,
            'service' => $this->provisioning->suspendService($request->body()),
        ]);
    }

    public function unsuspend(Request $request, array $params): Response
    {
        Auth::requireWhmcsWebhook($request, $this->config);
        return $this->json([
            'ok' => true,
            'service' => $this->provisioning->unsuspendService($request->body()),
        ]);
    }

    public function terminate(Request $request, array $params): Response
    {
        Auth::requireWhmcsWebhook($request, $this->config);
        return $this->json([
            'ok' => true,
            'service' => $this->provisioning->terminateService($request->body()),
        ]);
    }

    public function issueSso(Request $request, array $params): Response
    {
        Auth::requireWhmcsWebhook($request, $this->config);
        $link = $this->sso->issue($request->body());
        return $this->json([
            'ok' => true,
            'url' => $this->appUrl($request) . '/auth/whmcs/sso?token=' . $link['token'],
            'expires_in' => $link['expires_in'],
        ]);
    }

    public function consumeSso(Request $request, array $params): Response
    {
        $result = $this->sso->consume(trim((string) $request->input('token', '')));
        if ($result === null) {
            SessionAuth::flash('error', 'This sign-in link has expired. Open the control panel again from the billing site.');
            return Response::redirect('/login')->withHeaders(['Referrer-Policy' => 'no-referrer']);
        }

        SessionAuth::login($result['user']);
        return Response::redirect($result['redirect_path'])->withHeaders(['Referrer-Policy' => 'no-referrer']);
    }

    private function appUrl(Request $request): string
    {
        $appUrl = rtrim((string) ($this->config->get('APP_URL', '') ?? ''), '/');
        if ($appUrl !== '') {
            return $appUrl;
        }
        $host = (string) ($request->header('host') ?? '');
        return preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $host) === 1 ? 'https://' . $host : '';
    }
}
