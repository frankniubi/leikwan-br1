<?php
declare(strict_types=1);

namespace Leikwan\Master\Controllers;

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Http\Request;
use Leikwan\Master\Http\Response;
use Leikwan\Master\Support\OidcClient;
use Leikwan\Master\Support\OidcIdentityService;
use Leikwan\Master\Support\OidcProviderException;
use Leikwan\Master\Support\OidcSessionStore;
use Leikwan\Master\Support\SessionAuth;
use Leikwan\Master\Support\WhmcsIdentityService;
use Throwable;

final class OidcController extends BaseController
{
    private OidcClient $oidcClient;
    private OidcSessionStore $sessionStore;
    private OidcIdentityService $identityService;
    private WhmcsIdentityService $whmcsIdentityService;

    public function __construct(
        Config $config,
        Database $db,
        ?OidcClient $oidcClient = null,
        ?OidcSessionStore $sessionStore = null,
        ?OidcIdentityService $identityService = null,
        ?WhmcsIdentityService $whmcsIdentityService = null,
    ) {
        parent::__construct($config, $db);
        $this->oidcClient = $oidcClient ?? new OidcClient($config);
        $this->sessionStore = $sessionStore ?? new OidcSessionStore();
        $this->identityService = $identityService ?? new OidcIdentityService($db);
        $this->whmcsIdentityService = $whmcsIdentityService ?? new WhmcsIdentityService($config, $db);
    }

    public function start(Request $request, array $params): Response
    {
        if (!$this->oidcClient->isConfigured()) {
            SessionAuth::flash('error', 'Identity-provider sign-in is not configured.');
            return Response::redirect('/login');
        }

        try {
            $returnTo = $this->localReturnPath((string) $request->input('return', '/'));
            $flow = $this->sessionStore->create($returnTo, $this->oidcClient->usesPkce());
            return Response::redirect($this->oidcClient->authorizationUrl($flow));
        } catch (Throwable) {
            SessionAuth::flash('error', 'Unable to start identity-provider sign-in. Please try again.');
            return Response::redirect('/login');
        }
    }

    public function callback(Request $request, array $params): Response
    {
        try {
            if (!$this->oidcClient->isConfigured()) {
                throw new \RuntimeException('oidc_not_configured');
            }

            $state = trim((string) $request->input('state', ''));
            if ($state === '') {
                throw new \RuntimeException('missing_callback_parameters');
            }
            $flow = $this->sessionStore->consume($state);

            if (trim((string) $request->input('error', '')) !== '') {
                throw new \RuntimeException('provider_error');
            }
            $code = trim((string) $request->input('code', ''));
            if ($code === '') {
                throw new \RuntimeException('missing_callback_parameters');
            }

            $claims = $this->oidcClient->authenticateCallback($code, $flow);
            $user = $this->identityService->login($claims);

            $whmcsBindingWarning = false;
            if ($this->whmcsIdentityService->isConfigured()) {
                try {
                    $this->whmcsIdentityService->tryDirectBindForUser((int) $user['id']);
                } catch (Throwable) {
                    $whmcsBindingWarning = true;
                }
            }

            SessionAuth::login($user);
            if ($whmcsBindingWarning) {
                SessionAuth::flash('warning', 'Signed in with ' . $this->oidcClient->providerName() . ', but Buy station linking could not be completed.');
            } else {
                SessionAuth::flash('success', 'Signed in with ' . $this->oidcClient->providerName() . '.');
            }

            return Response::redirect($this->localReturnPath((string) ($flow['return_to'] ?? '/')));
        } catch (Throwable $throwable) {
            $context = $throwable instanceof OidcProviderException ? $throwable->context() : [];
            error_log(sprintf('OIDC callback failed: %s (%s)', $throwable->getMessage(), $throwable::class));
            if ($context !== []) {
                error_log('OIDC provider context: ' . (json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}'));
            }
            SessionAuth::flash('error', 'Unable to sign in with the identity provider. Please try again.');
            return Response::redirect('/login');
        }
    }

    private function localReturnPath(string $returnTo): string
    {
        $returnTo = trim($returnTo);
        if (
            $returnTo === ''
            || !str_starts_with($returnTo, '/')
            || str_starts_with($returnTo, '//')
            || str_contains($returnTo, '\\')
            || preg_match('/[\r\n]/', $returnTo) === 1
        ) {
            return '/';
        }

        return $returnTo;
    }
}
