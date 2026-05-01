<?php
declare(strict_types=1);

namespace Leikwan\Master\Controllers;

use Leikwan\Master\Http\Request;
use Leikwan\Master\Support\Auth;
use Leikwan\Master\Support\ProvisioningService;

final class WhmcsController extends BaseController
{
    public function __construct(
        \Leikwan\Master\Config $config,
        \Leikwan\Master\Database\Database $db,
        private ProvisioningService $provisioning,
    ) {
        parent::__construct($config, $db);
    }

    public function provision(Request $request, array $params): \Leikwan\Master\Http\Response
    {
        Auth::requireWhmcsWebhook($request, $this->config);
        return $this->json([
            'ok' => true,
            'service' => $this->provisioning->createOrUpdateServiceFromWhmcs($request->body()),
        ]);
    }

    public function renew(Request $request, array $params): \Leikwan\Master\Http\Response
    {
        Auth::requireWhmcsWebhook($request, $this->config);
        return $this->json([
            'ok' => true,
            'service' => $this->provisioning->renewService($request->body()),
        ]);
    }

    public function suspend(Request $request, array $params): \Leikwan\Master\Http\Response
    {
        Auth::requireWhmcsWebhook($request, $this->config);
        return $this->json([
            'ok' => true,
            'service' => $this->provisioning->suspendService($request->body()),
        ]);
    }

    public function terminate(Request $request, array $params): \Leikwan\Master\Http\Response
    {
        Auth::requireWhmcsWebhook($request, $this->config);
        return $this->json([
            'ok' => true,
            'service' => $this->provisioning->terminateService($request->body()),
        ]);
    }
}
